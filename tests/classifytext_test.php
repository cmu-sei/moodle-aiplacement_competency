<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
AI Placement Plugin for Moodle Competencies

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY
DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

declare(strict_types=1);

namespace aiplacement_competency;

use aiplacement_competency\external\classify_text;
use core_ai\aiactions\responses\response_generate_text;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the classify_text external function.
 *
 * The AI provider is never contacted: core_ai\manager is replaced in the
 * dependency injection container, so each test controls exactly what the
 * model is deemed to have returned.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(classify_text::class)]
final class classifytext_test extends \advanced_testcase {
    /**
     * The prompt text of every action the mocked manager was asked to process.
     *
     * @var string[]
     */
    private array $sentprompts = [];

    /**
     * Reset the database and the DI container between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->sentprompts = [];
    }

    /**
     * Replaces core_ai\manager with a mock returning the given generated content.
     *
     * The prompt of each processed action is recorded in $this->sentprompts, so
     * tests can assert on what would have been sent to the model.
     *
     * @param array $generatedcontent The classification payload the model "returned".
     */
    private function mock_ai_manager(array $generatedcontent): void {
        $response = new response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => json_encode($generatedcontent),
            'finishreason' => 'stop',
        ]);

        $mockmanager = $this->createMock(\core_ai\manager::class);
        $mockmanager->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\base $action) use ($response) {
                $this->sentprompts[] = (string)$action->get_configuration('prompttext');
                return $response;
            }
        );
        $mockmanager->method('is_action_available')->willReturn(true);
        $mockmanager->method('is_action_enabled')->willReturn(true);

        \core\di::set(\core_ai\manager::class, function () use ($mockmanager) {
            return $mockmanager;
        });
    }

    /**
     * Creates an activity and returns its module context.
     *
     * @return \context_module The context of a new assignment.
     */
    private function create_module_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);

        return \context_module::instance($cm->cmid);
    }

    /**
     * Duplicate competencies in the model response are collapsed.
     */
    public function test_execute_success_parses_and_dedupes(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();

        $this->mock_ai_manager([
            'framework' => ['shortname' => 'NICE-1.0.0'],
            'levels' => ['Analyze', 'Detect', 'Analyze'],
            'competencies' => [
                'T1119 - Recommend vulnerability remediation strategies',
                'T1119 - Recommend vulnerability remediation strategies',
            ],
        ]);

        $result = classify_text::execute(
            $cmctx->id,
            'Any prompt here',
            7,
            'NICE-1.0.0',
            ['Analyze', 'Detect', 'Detect']
        );

        $this->assertSame(7, $result['frameworkid']);
        $this->assertSame('NICE-1.0.0', $result['frameworkshortname']);
        $this->assertEquals(['Analyze', 'Detect'], $result['usedlevels']);
        $this->assertEquals(
            ['T1119 - Recommend vulnerability remediation strategies'],
            $result['competencies']
        );
    }

    /**
     * A response without a levels key still reports the levels the user selected.
     */
    public function test_execute_falls_back_to_selectedlevels_when_response_missing_levels(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();

        $this->mock_ai_manager([
            'framework' => ['shortname' => 'NICE-1.0.0'],
            'competencies' => [
                'T1119 - Recommend vulnerability remediation strategies',
                'T1119 - Recommend vulnerability remediation strategies',
            ],
        ]);

        $selected = ['Analyze', 'Detect'];
        $result = classify_text::execute(
            $cmctx->id,
            'Any prompt here',
            99,
            'NICE-1.0.0',
            $selected
        );

        $this->assertSame(99, $result['frameworkid']);
        $this->assertSame('NICE-1.0.0', $result['frameworkshortname']);
        $this->assertEquals($selected, $result['usedlevels']);
        $this->assertEquals(
            ['T1119 - Recommend vulnerability remediation strategies'],
            $result['competencies']
        );
    }

    /**
     * The framework shortname falls back to the one the caller selected.
     */
    public function test_execute_falls_back_to_selected_framework_shortname(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();

        $this->mock_ai_manager([
            'competencies' => ['T1059 - Command and Scripting Interpreter'],
        ]);

        $result = classify_text::execute(
            $cmctx->id,
            'Any prompt here',
            42,
            'DCWF-1.0.0',
            ['Analyze']
        );

        $this->assertSame('DCWF-1.0.0', $result['frameworkshortname']);
        $this->assertEquals(['T1059 - Command and Scripting Interpreter'], $result['competencies']);
    }

    /**
     * Creates a quiz holding one question that has two versions.
     *
     * The first version says FIRSTVERSIONTEXT, the second says SECONDVERSIONTEXT.
     *
     * @return array [the quiz record, its module context]
     */
    private function create_quiz_with_two_question_versions(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 100.0,
        ]);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('shortanswer', null, [
            'category' => $category->id,
            'name' => 'Question version one',
            'questiontext' => ['text' => '<p>FIRSTVERSIONTEXT</p>', 'format' => FORMAT_HTML],
        ]);
        quiz_add_quiz_question($question->id, $quiz);

        $questiongenerator->update_question($question, null, [
            'name' => 'Question version two',
            'questiontext' => ['text' => '<p>SECONDVERSIONTEXT</p>', 'format' => FORMAT_HTML],
        ]);

        return [$quiz, \context_module::instance($quiz->cmid)];
    }

    /**
     * A quiz slot contributes the latest version of its question, not an arbitrary one.
     */
    public function test_quiz_prompt_uses_the_latest_question_version(): void {
        [, $quizcontext] = $this->create_quiz_with_two_question_versions();
        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($quizcontext->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('ACTIVITY INTRO', $prompt);
        $this->assertStringContainsString('SECONDVERSIONTEXT', $prompt);
        $this->assertStringContainsString('Question version two', $prompt);
        $this->assertStringNotContainsString('FIRSTVERSIONTEXT', $prompt);
    }

    /**
     * A slot pinned to an older version contributes that version, not the latest.
     */
    public function test_quiz_prompt_honours_a_slot_pinned_to_an_older_version(): void {
        global $DB;

        [$quiz, $quizcontext] = $this->create_quiz_with_two_question_versions();

        // This is what choosing a specific version in the "Question version" selector does.
        $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => $quiz->id], MUST_EXIST);
        $DB->set_field('question_references', 'version', 1, [
            'itemid' => $slotid,
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
        ]);

        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($quizcontext->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('FIRSTVERSIONTEXT', $prompt);
        $this->assertStringNotContainsString('SECONDVERSIONTEXT', $prompt);
    }

    /**
     * Creates a group quiz holding the given questions, in the given order.
     *
     * mod_groupquiz ships no PHPUnit generator, so the instance and its course
     * module are built the same way core does it, without going through the
     * module form.
     *
     * @param array $questions Question records to link, in the order the activity should use.
     * @param string $intro The activity intro, as HTML.
     * @return array [int $groupquizid, \context_module $context]
     */
    private function create_groupquiz(array $questions, string $intro = '<p>ACTIVITY INTRO</p>'): array {
        global $CFG, $DB;

        if (!\core_component::get_plugin_directory('mod', 'groupquiz')) {
            $this->markTestSkipped('mod_groupquiz is not installed on this site.');
        }

        require_once($CFG->dirroot . '/course/lib.php');

        $course = $this->getDataGenerator()->create_course();

        $groupquizid = $DB->insert_record('groupquiz', (object)[
            'course' => $course->id,
            'name' => 'Group quiz under test',
            'intro' => $intro,
            'introformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // groupquiz.questionorder is a comma separated list of groupquiz_questions ids.
        $order = [];
        foreach ($questions as $question) {
            $order[] = $DB->insert_record('groupquiz_questions', (object)[
                'groupquizid' => $groupquizid,
                'questionid' => $question->id,
                'points' => 1,
            ]);
        }
        $DB->set_field('groupquiz', 'questionorder', implode(',', $order), ['id' => $groupquizid]);

        $cmid = add_course_module((object)[
            'course' => $course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'groupquiz'], MUST_EXIST),
            'instance' => $groupquizid,
            'section' => 0,
            'visible' => 1,
        ]);
        course_add_cm_to_section($course->id, $cmid, 0);

        return [$groupquizid, \context_module::instance($cmid)];
    }

    /**
     * Creates a question with the given name and text.
     *
     * @param string $name The question name.
     * @param string $text The question text.
     * @return \stdClass The question record.
     */
    private function create_question(string $name, string $text): \stdClass {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        return $questiongenerator->create_question('shortanswer', null, [
            'category' => $category->id,
            'name' => $name,
            'questiontext' => ['text' => '<p>' . $text . '</p>', 'format' => FORMAT_HTML],
        ]);
    }

    /**
     * A group quiz contributes its questions, in the order it presents them.
     */
    public function test_groupquiz_prompt_includes_questions_in_the_saved_order(): void {
        $first = $this->create_question('Question alpha', 'ALPHATEXT');
        $second = $this->create_question('Question beta', 'BETATEXT');

        // Deliberately not creation order, to prove questionorder is honoured.
        [, $context] = $this->create_groupquiz([$second, $first]);

        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($context->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('ACTIVITY INTRO', $prompt);
        $this->assertStringContainsString('BETATEXT', $prompt);
        $this->assertStringContainsString('ALPHATEXT', $prompt);
        $this->assertLessThan(
            strpos($prompt, 'ALPHATEXT'),
            strpos($prompt, 'BETATEXT'),
            'Questions should appear in the order given by groupquiz.questionorder.'
        );
    }

    /**
     * A group quiz follows the question id it stores, which editing does not move.
     *
     * Unlike mod_quiz, mod_groupquiz points straight at a question row rather
     * than at a bank entry, so a new version created in the question bank is not
     * picked up. The prompt has to match what the activity presents, so it keeps
     * the stored version.
     */
    public function test_groupquiz_prompt_follows_the_stored_question_id(): void {
        $question = $this->create_question('Question version one', 'FIRSTVERSIONTEXT');
        [, $context] = $this->create_groupquiz([$question]);

        $this->getDataGenerator()->get_plugin_generator('core_question')->update_question($question, null, [
            'name' => 'Question version two',
            'questiontext' => ['text' => '<p>SECONDVERSIONTEXT</p>', 'format' => FORMAT_HTML],
        ]);

        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($context->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('FIRSTVERSIONTEXT', $prompt);
        $this->assertStringNotContainsString('SECONDVERSIONTEXT', $prompt);
    }

    /**
     * Ids left in questionorder with no surviving link are skipped.
     */
    public function test_groupquiz_prompt_skips_stale_question_order_entries(): void {
        global $DB;

        $kept = $this->create_question('Question kept', 'KEPTTEXT');
        $removed = $this->create_question('Question removed', 'REMOVEDTEXT');
        [$groupquizid, $context] = $this->create_groupquiz([$removed, $kept]);

        // Drop the link but leave its id in the order list, as a stale order would.
        $DB->delete_records('groupquiz_questions', [
            'groupquizid' => $groupquizid,
            'questionid' => $removed->id,
        ]);

        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($context->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('KEPTTEXT', $prompt);
        $this->assertStringNotContainsString('REMOVEDTEXT', $prompt);
        // Numbering counts what is actually sent, so the survivor is question 1.
        $this->assertStringContainsString('Question 1 (Question kept)', $prompt);
    }

    /**
     * A group quiz with no questions contributes nothing beyond its intro.
     */
    public function test_groupquiz_prompt_with_no_questions_is_intro_only(): void {
        [, $context] = $this->create_groupquiz([]);

        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($context->id, 'ACTIVITY INTRO', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertCount(1, $this->sentprompts);
        $this->assertStringContainsString('ACTIVITY INTRO', $this->sentprompts[0]);
        $this->assertStringNotContainsString('Question 1', $this->sentprompts[0]);
    }

    /**
     * Content that is not valid JSON yields no competencies rather than an error.
     */
    public function test_execute_tolerates_unparseable_generated_content(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();

        $response = new response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => 'I am afraid I cannot help with that.',
            'finishreason' => 'stop',
        ]);

        $mockmanager = $this->createMock(\core_ai\manager::class);
        $mockmanager->method('process_action')->willReturn($response);
        \core\di::set(\core_ai\manager::class, function () use ($mockmanager) {
            return $mockmanager;
        });

        $result = classify_text::execute($cmctx->id, 'Any prompt here', 1, 'NICE-1.0.0', ['Analyze']);

        $this->assertSame([], $result['competencies']);
        $this->assertEquals(['Analyze'], $result['usedlevels']);
    }

    /**
     * Users without the classify capability are rejected.
     */
    public function test_execute_requires_capability_in_module_context(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cmctx = \context_module::instance($cm->cmid);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);

        classify_text::execute(
            $cmctx->id,
            'Denied',
            1,
            '',
            []
        );
    }
}
