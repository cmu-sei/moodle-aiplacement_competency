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

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS"
BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER
INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM
USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM
PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact
permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see
Copyright notice for non-US Government use and distribution.

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
     * A failed provider response, built the way the running Moodle takes one.
     *
     * Moodle 5.2 added a required error name beside the code, and 5.0 has no such argument.
     *
     * @param string $message The provider's error message.
     * @return response_generate_text The response.
     */
    private function failed_response(string $message): response_generate_text {
        try {
            return new response_generate_text(success: false, errorcode: 500, error: 'Error', errormessage: $message);
        } catch (\Error $e) {
            return new response_generate_text(success: false, errorcode: 500, errormessage: $message);
        }
    }

    /**
     * Replaces core_ai\manager with a mock that answers each request with the next response.
     *
     * The last response repeats once the list runs out. Each item is a payload array
     * or a ready made response_generate_text.
     *
     * @param array $responses The responses to give, in order.
     */
    private function mock_ai_sequence(array $responses): void {
        $queue = [];
        foreach ($responses as $item) {
            if (is_array($item)) {
                $item = new response_generate_text(success: true);
                $item->set_response_data([
                    'generatedcontent' => json_encode($responses[count($queue)]),
                    'finishreason' => 'stop',
                ]);
            }
            $queue[] = $item;
        }

        $calls = 0;
        $mockmanager = $this->createMock(\core_ai\manager::class);
        $mockmanager->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\base $action) use ($queue, &$calls) {
                $this->sentprompts[] = (string)$action->get_configuration('prompttext');
                return $queue[min($calls++, count($queue) - 1)];
            }
        );
        $mockmanager->method('is_action_available')->willReturn(true);
        $mockmanager->method('is_action_enabled')->willReturn(true);

        \core\di::set(\core_ai\manager::class, function () use ($mockmanager) {
            return $mockmanager;
        });
    }

    /**
     * Creates a framework of top level competencies, each with children, idnumbered by shortname.
     *
     * @param array $levels Level shortname => [child shortname => child description].
     * @return int The framework id.
     */
    private function create_framework(array $levels): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $frameworkid = (int)$generator->create_framework(['shortname' => 'TESTFW'])->get('id');

        foreach ($levels as $shortname => $children) {
            $parent = $generator->create_competency([
                'competencyframeworkid' => $frameworkid,
                'shortname' => $shortname,
                'idnumber' => $shortname,
                'description' => '',
            ]);
            foreach ($children as $childshortname => $description) {
                $generator->create_competency([
                    'competencyframeworkid' => $frameworkid,
                    'parentid' => $parent->get('id'),
                    'shortname' => $childshortname,
                    'idnumber' => explode(' - ', $childshortname)[0],
                    'description' => $description,
                ]);
            }
        }

        return $frameworkid;
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
        $fw = $this->create_framework(['Analyze' => ['T1119' => 'Recommend vulnerability remediation strategies']]);

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
            $fw,
            'NICE-1.0.0',
            ['Analyze', 'Detect', 'Detect']
        );

        $this->assertSame($fw, $result['frameworkid']);
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
        $fw = $this->create_framework(['Analyze' => ['T1119' => 'Recommend vulnerability remediation strategies']]);

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
            $fw,
            'NICE-1.0.0',
            $selected
        );

        $this->assertSame($fw, $result['frameworkid']);
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
        $fw = $this->create_framework(['Analyze' => ['T1059' => 'Command and Scripting Interpreter']]);

        $this->mock_ai_manager([
            'competencies' => ['T1059 - Command and Scripting Interpreter'],
        ]);

        $result = classify_text::execute(
            $cmctx->id,
            'Any prompt here',
            $fw,
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

        // The groupquiz.questionorder column is a comma separated list of groupquiz_questions ids.
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
     * A reply that is not an answer is reported as unusable, not shown as "no competencies".
     */
    public function test_execute_reports_unparseable_generated_content(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = new response_generate_text(success: true);
        $response->set_response_data([
            'generatedcontent' => 'Here are some thoughts, but nothing structured.',
            'finishreason' => 'stop',
        ]);
        $this->mock_ai_sequence([$response]);

        try {
            classify_text::execute($cmctx->id, 'Any prompt here', 1, 'NICE-1.0.0', ['Analyze']);
            $this->fail('An unusable reply should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyunusable', $e->errorcode);
        }
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

    /**
     * The competencies the model names are checked against the framework, reasons are kept.
     */
    public function test_execute_drops_competencies_the_framework_does_not_have_and_keeps_reasons(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'Triage intrusions', 'T2' => 'Audit payroll']]);
        $this->mock_ai_manager(['competencies' => [
            ['idnumber' => 'T1', 'reason' => 'Learners triage an intrusion.'],
            ['idnumber' => 'T777', 'reason' => 'Invented.'],
        ]]);

        $result = classify_text::execute($cmctx->id, 'Triage a live intrusion.', $fw, 'TESTFW', ['Tasks']);

        $this->assertSame(['T1 - Triage intrusions'], $result['competencies']);
        $this->assertSame('T1', $result['suggestions'][0]['idnumber']);
        $this->assertSame('Learners triage an intrusion.', $result['suggestions'][0]['reason']);
    }

    /**
     * No more than the configured number of tags come back.
     */
    public function test_execute_caps_the_number_of_tags(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        set_config('maxtags', 2, 'aiplacement_competency');
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two', 'T3' => 'Three']]);
        $this->mock_ai_manager(['competencies' => ['T1', 'T2', 'T3']]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks']);

        $this->assertCount(2, $result['competencies']);
        $this->assertStringContainsString('at most 2', $this->sentprompts[0]);
    }

    /**
     * Long content is read whole, in sections, and the sections' tags are merged.
     */
    public function test_execute_reads_the_whole_of_long_content_in_sections(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'First', 'T2' => 'Second', 'T3' => 'Third']]);
        $this->mock_ai_sequence([
            ['competencies' => ['T1', 'T2']],
            ['competencies' => ['T2', 'T3']],
        ]);
        $text = str_repeat("Opening material about routing.\n\n", 400) . 'ENDOFTRANSCRIPTMARKER';

        $result = classify_text::execute($cmctx->id, $text, $fw, 'TESTFW', ['Tasks']);

        $this->assertGreaterThan(1, count($this->sentprompts));
        $this->assertStringContainsString('ENDOFTRANSCRIPTMARKER', end($this->sentprompts));
        // T2 was backed by both sections, so it comes first.
        $this->assertSame('T2', explode(' - ', $result['competencies'][0])[0]);
        $this->assertCount(3, $result['competencies']);
    }

    /**
     * A model that declines on safety grounds is reported as such, not as a failure.
     */
    public function test_execute_reports_a_refusal(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = $this->failed_response('The model stopped: content filter safeguards triggered');
        $this->mock_ai_sequence([$response]);

        try {
            classify_text::execute($cmctx->id, 'Some text', 0, '', []);
            $this->fail('A refusal should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyrefused', $e->errorcode);
        }
    }

    /**
     * A reply in prose that says the model will not help counts as a refusal.
     */
    public function test_execute_reports_a_prose_refusal(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => "I can't help with that request.", 'finishreason' => 'stop']);
        $this->mock_ai_sequence([$response]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('aiclassifyrefused', 'aiplacement_competency'));

        classify_text::execute($cmctx->id, 'Some text', 0, '', []);
    }

    /**
     * The verification pass removes tags the reviewer does not keep.
     */
    public function test_execute_verify_removes_tags_the_reviewer_drops(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two']]);
        $this->mock_ai_sequence([
            ['competencies' => [['idnumber' => 'T1', 'reason' => 'a'], ['idnumber' => 'T2', 'reason' => 'b']]],
            ['keep' => ['T2']],
        ]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);

        $this->assertSame(['T2 - Two'], $result['competencies']);
        $this->assertCount(2, $this->sentprompts);
        $this->assertStringContainsString('strict reviewer', $this->sentprompts[1]);
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Verification is skipped when it is not asked for.
     */
    public function test_execute_does_not_verify_unless_asked(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);

        classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks']);

        $this->assertCount(1, $this->sentprompts);
    }

    /**
     * Content that shares nothing with the title is flagged.
     */
    public function test_execute_warns_when_the_content_does_not_match_the_title(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Cryptography Lecture']);
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);
        $text = str_repeat('The instructor explains how routers forward packets between subnets. ', 40);

        $result = classify_text::execute(\context_module::instance($cm->cmid)->id, $text, $fw, 'TESTFW', ['Tasks']);

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Cryptography Lecture', $result['warnings'][0]);
    }

    /**
     * An empty reply, as when a reasoning block comes first, gets its own message.
     */
    public function test_execute_reports_an_empty_reply(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => '', 'finishreason' => 'stop']);
        $this->mock_ai_sequence([$response]);

        try {
            classify_text::execute($cmctx->id, 'Some text', 0, '', []);
            $this->fail('An empty reply should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyempty', $e->errorcode);
        }
    }

    /**
     * When some sections fail and others succeed, the result says it may be incomplete.
     */
    public function test_execute_warns_when_some_sections_fail(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $failed = $this->failed_response('Provider timeout');
        $ok = new response_generate_text(success: true);
        $ok->set_response_data(['generatedcontent' => json_encode(['competencies' => ['T1']]), 'finishreason' => 'stop']);
        $this->mock_ai_sequence([$failed, $ok]);
        $text = str_repeat("Some lecture material about routing.\n\n", 400);

        $result = classify_text::execute($cmctx->id, $text, $fw, 'TESTFW', ['Tasks']);

        $this->assertSame(['T1 - One'], $result['competencies']);
        $this->assertStringContainsString('sections of the content could not be classified', implode(' ', $result['warnings']));
    }

    /**
     * A verification pass that gives no usable answer keeps the tags and says it did not check them.
     */
    public function test_execute_warns_when_verification_gives_no_answer(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_sequence([
            ['competencies' => ['T1']],
            ['unexpected' => true],
        ]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);

        $this->assertSame(['T1 - One'], $result['competencies']);
        $this->assertStringContainsString('not been double-checked', implode(' ', $result['warnings']));
    }

    /**
     * Content past the section limit is sampled, with the first and last sections kept, and says so.
     */
    public function test_execute_samples_sections_beyond_the_limit(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);
        $paragraph = str_repeat('x', 5900) . "\n\n";
        $text = 'STARTMARK ' . str_repeat($paragraph, 60) . 'ENDMARK';

        $result = classify_text::execute($cmctx->id, $text, $fw, 'TESTFW', ['Tasks']);

        $this->assertCount(\aiplacement_competency\local\classifier::MAX_CHUNKS, $this->sentprompts);
        $this->assertStringContainsString('STARTMARK', $this->sentprompts[0]);
        $this->assertStringContainsString('ENDMARK', end($this->sentprompts));
        $this->assertStringContainsString('read in 6 of', implode(' ', $result['warnings']));
    }

    /**
     * Solution guides in the description are not sent either.
     */
    public function test_execute_does_not_send_a_solution_section_from_the_description(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $this->mock_ai_manager(['competencies' => []]);

        classify_text::execute($cmctx->id, "Learners scan a host.\n\nSolution guide: run SECRETCOMMAND", 0, '', []);

        $this->assertStringContainsString('Learners scan a host.', $this->sentprompts[0]);
        $this->assertStringNotContainsString('SECRETCOMMAND', $this->sentprompts[0]);
    }

    /**
     * A refused connection is an outage, not the model declining.
     */
    public function test_execute_does_not_call_a_refused_connection_a_refusal(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = $this->failed_response('cURL error 7: Connection refused');
        $this->mock_ai_sequence([$response]);

        try {
            classify_text::execute($cmctx->id, 'Some text', 0, '', []);
            $this->fail('A failure should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyfailed', $e->errorcode);
        }
    }

    /**
     * An empty reply that the provider marks as a refusal is explained as one.
     */
    public function test_execute_reports_an_empty_reply_with_a_refusal_stop_reason_as_a_refusal(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => '', 'finishreason' => 'refusal']);
        $this->mock_ai_sequence([$response]);

        try {
            classify_text::execute($cmctx->id, 'Some text', 0, '', []);
            $this->fail('A refusal should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyrefused', $e->errorcode);
        }
    }

    /**
     * A reviewer that answers with "CODE - Name" is understood, and one that names nothing known is not trusted.
     */
    public function test_execute_verify_reads_code_and_name_and_ignores_an_answer_about_nothing(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two']]);
        $this->mock_ai_sequence([
            ['competencies' => ['T1', 'T2']],
            ['keep' => ['T2 - Two']],
        ]);
        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);
        $this->assertSame(['T2 - Two'], $result['competencies']);

        $this->mock_ai_sequence([
            ['competencies' => ['T1', 'T2']],
            ['keep' => ['Something else entirely']],
        ]);
        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);
        $this->assertCount(2, $result['competencies']);
        $this->assertStringContainsString('not been double-checked', implode(' ', $result['warnings']));
    }

    /**
     * The reviewer is shown the section that backs a tag, even one in the middle of the content.
     */
    public function test_execute_verify_shows_the_section_behind_a_tag(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two']]);
        $filler = str_repeat('filler words about nothing in particular. ', 140) . "\n\n";
        $text = $filler . 'MIDDLEMARK ' . $filler . $filler . $filler . $filler . $filler . $filler;
        $this->mock_ai_sequence([
            ['competencies' => ['T1']],
            ['competencies' => ['T2']],
            ['competencies' => ['T1']],
            ['competencies' => ['T1']],
            ['competencies' => ['T1']],
            ['competencies' => ['T1']],
            ['competencies' => ['T1']],
            ['keep' => ['T1', 'T2']],
        ]);

        classify_text::execute($cmctx->id, $text, $fw, 'TESTFW', ['Tasks'], true);

        $this->assertStringContainsString('strict reviewer', end($this->sentprompts));
        $this->assertStringContainsString('MIDDLEMARK', end($this->sentprompts));
    }

    /**
     * With nothing offered to choose from, the answer says why it is empty.
     */
    public function test_execute_warns_when_the_levels_offer_no_competencies(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['No such level']);

        $this->assertSame([], $result['competencies']);
        $this->assertStringContainsString('No competencies were found', implode(' ', $result['warnings']));
    }

    /**
     * The title reaches the model and the warning as written, not HTML escaped.
     */
    public function test_execute_does_not_escape_the_title(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'R&D Cryptography']);
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);

        classify_text::execute(\context_module::instance($cm->cmid)->id, 'Anything', $fw, 'TESTFW', ['Tasks']);

        $this->assertStringContainsString('"R&D Cryptography"', $this->sentprompts[0]);
        $this->assertStringNotContainsString('&amp;', $this->sentprompts[0]);
    }

    /**
     * Valid JSON that is not the answer asked for is not taken as "no competencies".
     */
    public function test_execute_reports_json_that_is_not_an_answer(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $this->mock_ai_manager(['message' => "I can't help with that request."]);

        try {
            classify_text::execute($cmctx->id, 'Some text', 0, '', []);
            $this->fail('A non answer should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyrefused', $e->errorcode);
        }
    }

    /**
     * The reviewer's keep list may carry the name too, in the form the verify prompt itself uses.
     */
    public function test_execute_verify_reads_bracketed_codes(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two']]);
        $this->mock_ai_sequence([
            ['competencies' => ['T1', 'T2']],
            ['keep' => ['T1', '[T2] Two', 'T3: not offered']],
        ]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);

        $this->assertCount(2, $result['competencies']);
    }

    /**
     * Two competencies that read the same in full are told apart for the person ticking them.
     */
    public function test_execute_tells_apart_competencies_with_the_same_label(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'TESTFW'])->get('id');
        $parent = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'Skills', 'idnumber' => 'Skills']);
        foreach (['S001', 'S002'] as $idnumber) {
            $generator->create_competency([
                'competencyframeworkid' => $fw,
                'parentid' => $parent->get('id'),
                'shortname' => 'Network discovery',
                'idnumber' => $idnumber,
                'description' => 'Use nmap to find hosts.',
            ]);
        }
        $this->mock_ai_manager(['competencies' => ['S001', 'S002']]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Skills']);

        $this->assertCount(2, array_unique($result['competencies']));
        $this->assertSame(['S001', 'S002'], array_column($result['suggestions'], 'idnumber'));
    }

    /**
     * Each tag is judged against the first section that backs it, even when many sections back others.
     */
    public function test_execute_verify_always_shows_the_only_section_behind_a_tag(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        set_config('maxtags', 3, 'aiplacement_competency');
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One', 'T2' => 'Two']]);
        $filler = str_repeat('filler words about nothing in particular. ', 200) . "\n\n";
        $sections = [];
        for ($i = 0; $i < 8; $i++) {
            $sections[] = $i === 1 ? 'ONLYSECTIONMARK ' . $filler : $filler;
        }
        $answers = [];
        for ($i = 0; $i < 8; $i++) {
            $answers[] = ['competencies' => $i === 1 ? ['T2'] : ['T1']];
        }
        $answers[] = ['keep' => ['T1', 'T2']];
        $this->mock_ai_sequence($answers);

        classify_text::execute($cmctx->id, implode('', $sections), $fw, 'TESTFW', ['Tasks'], true);

        $this->assertStringContainsString('ONLYSECTIONMARK', end($this->sentprompts));
    }

    /**
     * Keeping a sub-technique does not keep its parent.
     */
    public function test_execute_verify_does_not_keep_a_parent_for_its_child(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $fw = $this->create_framework(['Tactics' => ['T1566' => 'Phishing', 'T1566.001' => 'Spearphishing Attachment']]);
        $this->mock_ai_sequence([
            ['competencies' => ['T1566', 'T1566.001']],
            ['keep' => ['T1566.001']],
        ]);

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tactics'], true);

        $this->assertSame(['T1566.001 - Spearphishing Attachment'], $result['competencies']);
    }

    /**
     * A generated label never reads the same as another competency's own label.
     */
    public function test_execute_labels_stay_unique_when_a_description_ends_like_a_suffix(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'TESTFW'])->get('id');
        $parent = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'Skills', 'idnumber' => 'Skills']);
        $descriptions = ['S001' => 'Use nmap.', 'S002' => 'Use nmap.', 'S003' => 'Use nmap. (S002)'];
        foreach ($descriptions as $idnumber => $description) {
            $generator->create_competency([
                'competencyframeworkid' => $fw,
                'parentid' => $parent->get('id'),
                'shortname' => 'Discovery',
                'idnumber' => $idnumber,
                'description' => $description,
            ]);
        }
        $this->mock_ai_manager(['competencies' => ['S001', 'S002', 'S003']]);
        set_config('maxtags', 5, 'aiplacement_competency');

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Skills']);

        $this->assertCount(3, array_unique(array_map('strtolower', $result['competencies'])));
    }

    /**
     * Once the time budget is spent no further section is sent, and the result says so.
     */
    public function test_execute_stops_starting_sections_when_the_time_budget_is_spent(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        set_config('timebudget', '0.0001', 'aiplacement_competency');
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $this->mock_ai_manager(['competencies' => ['T1']]);
        $text = str_repeat("Some lecture material about routing.\n\n", 800);

        $result = classify_text::execute($cmctx->id, $text, $fw, 'TESTFW', ['Tasks'], true);

        // The first section is always sent. A second would pass the budget, and so would the review.
        $this->assertCount(1, $this->sentprompts);
        $this->assertSame(['T1 - One'], $result['competencies']);
        $this->assertStringContainsString('Stopped after 1 of', implode(' ', $result['warnings']));
    }

    /**
     * A request is not started when one as long as the longest so far would end past the budget.
     */
    public function test_execute_does_not_start_a_section_that_would_end_past_the_budget(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        set_config('timebudget', '0.5', 'aiplacement_competency');
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => json_encode(['competencies' => ['T1']]), 'finishreason' => 'stop']);
        $mockmanager = $this->createMock(\core_ai\manager::class);
        $mockmanager->method('process_action')->willReturnCallback(function () use ($response) {
            $this->sentprompts[] = 'request';
            // Each request takes 0.3 s: after one, 0.3 s spent plus 0.3 s for another is over the 0.5 s budget.
            usleep(300000);
            return $response;
        });
        $mockmanager->method('is_action_available')->willReturn(true);
        $mockmanager->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mockmanager) {
            return $mockmanager;
        });

        $result = classify_text::execute($cmctx->id, str_repeat("Some lecture material.\n\n", 1200), $fw, 'TESTFW', ['Tasks']);

        $this->assertCount(1, $this->sentprompts);
        $this->assertStringContainsString('Stopped after 1 of', implode(' ', $result['warnings']));
    }

    /**
     * When time is short the verification pass is skipped, and the result says the tags were not checked.
     */
    public function test_execute_says_when_there_was_no_time_to_verify(): void {
        $cmctx = $this->create_module_context();
        $this->setAdminUser();
        set_config('timebudget', '0.2', 'aiplacement_competency');
        $fw = $this->create_framework(['Tasks' => ['T1' => 'One']]);
        $response = new response_generate_text(success: true);
        $response->set_response_data(['generatedcontent' => json_encode(['competencies' => ['T1']]), 'finishreason' => 'stop']);
        $mockmanager = $this->createMock(\core_ai\manager::class);
        $mockmanager->method('process_action')->willReturnCallback(function () use ($response) {
            $this->sentprompts[] = 'request';
            usleep(300000);
            return $response;
        });
        $mockmanager->method('is_action_available')->willReturn(true);
        $mockmanager->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mockmanager) {
            return $mockmanager;
        });

        $result = classify_text::execute($cmctx->id, 'Anything', $fw, 'TESTFW', ['Tasks'], true);

        $this->assertCount(1, $this->sentprompts);
        $this->assertSame(['T1 - One'], $result['competencies']);
        $this->assertStringContainsString('not enough time left', implode(' ', $result['warnings']));
    }
}
