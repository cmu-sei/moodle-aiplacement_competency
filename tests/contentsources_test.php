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

use aiplacement_competency\local\content\choice_source;
use aiplacement_competency\local\content\cmi5launch_source;
use aiplacement_competency\local\content\crucible_source;
use aiplacement_competency\local\content\data_source;
use aiplacement_competency\local\content\feedback_source;
use aiplacement_competency\local\content\imscp_source;
use aiplacement_competency\local\content\pptbook_source;
use aiplacement_competency\local\content\resolver;
use aiplacement_competency\local\content\scorm_source;
use aiplacement_competency\local\content\topomojo_source;
use aiplacement_competency\local\content\wiki_source;
use aiplacement_competency\local\content\workshop_source;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the per-module content sources.
 *
 * One test per module, asserting what the resolver hands to a classification
 * request: which fields are read, in which order, and which are deliberately
 * left out because they hold learner work rather than what the author wrote.
 *
 * The Crucible modules are not on every site that installs this plugin, so
 * their tests skip rather than fail when the module is missing.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(topomojo_source::class)]
#[CoversClass(crucible_source::class)]
#[CoversClass(pptbook_source::class)]
#[CoversClass(cmi5launch_source::class)]
#[CoversClass(choice_source::class)]
#[CoversClass(feedback_source::class)]
#[CoversClass(data_source::class)]
#[CoversClass(wiki_source::class)]
#[CoversClass(scorm_source::class)]
#[CoversClass(imscp_source::class)]
#[CoversClass(workshop_source::class)]
final class contentsources_test extends \advanced_testcase {
    /**
     * Reset the database between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Several module generators save editor files, which no guest may do.
        $this->setAdminUser();
    }

    /**
     * Creates an activity of a module that ships no PHPUnit generator.
     *
     * The instance row and its course module are built the way core builds
     * them, without going through the module form, so the Crucible modules can
     * be covered without depending on their own test support.
     *
     * @param string $modname The module name.
     * @param array $fields Fields to set on the instance row.
     * @return array [int $instanceid, \stdClass $cm, \context_module $context]
     */
    private function create_activity(string $modname, array $fields = []): array {
        global $CFG, $DB;

        if (!\core_component::get_plugin_directory('mod', $modname)) {
            $this->markTestSkipped("mod_{$modname} is not installed on this site.");
        }

        require_once($CFG->dirroot . '/course/lib.php');

        $course = $this->getDataGenerator()->create_course();

        $instanceid = $DB->insert_record($modname, (object)($fields + [
            'course' => $course->id,
            'name' => 'Activity under test',
            'intro' => '<p>ACTIVITYINTRO</p>',
            'introformat' => FORMAT_HTML,
            'timecreated' => time(),
            'timemodified' => time(),
        ]));

        $cmid = add_course_module((object)[
            'course' => $course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => $modname], MUST_EXIST),
            'instance' => $instanceid,
            'section' => 0,
            'visible' => 1,
        ]);
        course_add_cm_to_section($course->id, $cmid, 0);

        return [
            (int)$instanceid,
            get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST),
            \context_module::instance($cmid),
        ];
    }

    /**
     * Creates an activity of a module that has a PHPUnit generator.
     *
     * @param string $modname The module name.
     * @param array $record Fields to set on the instance.
     * @return array [\stdClass $instance, \stdClass $cm, \context_module $context]
     */
    private function create_module(string $modname, array $record = []): array {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module($modname, $record + [
            'course' => $course->id,
            'intro' => '<p>ACTIVITYINTRO</p>',
            'introformat' => FORMAT_HTML,
        ]);

        return [
            $instance,
            get_coursemodule_from_id($modname, $instance->cmid, 0, false, MUST_EXIST),
            \context_module::instance($instance->cmid),
        ];
    }

    /**
     * Wraps text the way a module generator expects an editor field.
     *
     * @param string $text The HTML to save.
     * @return array The editor field value.
     */
    private static function editor(string $text): array {
        return ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
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
     * A TopoMojo activity contributes its lab guide and its questions.
     */
    public function test_topomojo_content_is_the_guide_and_the_questions_in_order(): void {
        global $DB;

        $first = $this->create_question('Question alpha', 'ALPHATEXT');
        $second = $this->create_question('Question beta', 'BETATEXT');

        [$instanceid, $cm, $context] = $this->create_activity('topomojo', [
            'workspaceid' => 'workspace-under-test',
            'content' => '<p>GUIDEBODY</p>',
            'contentformat' => FORMAT_HTML,
        ]);

        // topomojo.questionorder is a comma separated list of topomojo_questions
        // ids, and this is deliberately not creation order.
        $order = [];
        foreach ([$second, $first] as $question) {
            $order[] = $DB->insert_record('topomojo_questions', (object)[
                'topomojoid' => $instanceid,
                'questionid' => $question->id,
                'points' => 1,
            ]);
        }
        $DB->set_field('topomojo', 'questionorder', implode(',', $order), ['id' => $instanceid]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('GUIDEBODY', $content);
        $this->assertStringContainsString('Question 1 (Question beta): BETATEXT', $content);
        $this->assertStringContainsString('Question 2 (Question alpha): ALPHATEXT', $content);
    }

    /**
     * A TopoMojo activity with questions but no guide still has content.
     */
    public function test_topomojo_has_content_from_questions_alone(): void {
        global $DB;

        $question = $this->create_question('Question alpha', 'ALPHATEXT');
        [$instanceid, $cm, $context] = $this->create_activity('topomojo', [
            'workspaceid' => 'workspace-under-test',
            'content' => '',
            'intro' => '',
        ]);

        $linkid = $DB->insert_record('topomojo_questions', (object)[
            'topomojoid' => $instanceid,
            'questionid' => $question->id,
            'points' => 1,
        ]);
        $DB->set_field('topomojo', 'questionorder', (string)$linkid, ['id' => $instanceid]);

        $this->assertTrue(resolver::has_content($cm, $context));
        $this->assertStringContainsString('ALPHATEXT', resolver::get_content($cm, $context));
    }

    /**
     * A Crucible exercise contributes the tasks students can see.
     */
    public function test_crucible_content_is_its_visible_tasks(): void {
        global $DB;

        [$instanceid, $cm, $context] = $this->create_activity('crucible', [
            'eventtemplateid' => 'template-under-test',
        ]);

        foreach ([
            ['name' => 'Task alpha', 'description' => '<p>ALPHADESC</p>', 'visible' => 1],
            // No description: the name is the whole of what the task asks.
            ['name' => 'NAMEONLYTASK', 'description' => '', 'visible' => 1],
            ['name' => 'Task hidden', 'description' => '<p>HIDDENDESC</p>', 'visible' => 0],
        ] as $task) {
            $DB->insert_record('crucible_tasks', (object)($task + [
                'crucibleid' => $instanceid,
                'gradable' => 1,
                'points' => 1,
                'multiple' => 0,
            ]));
        }

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Task 1 (Task alpha): ALPHADESC', $content);
        $this->assertStringContainsString('Task 2: NAMEONLYTASK', $content);
        $this->assertStringNotContainsString('HIDDENDESC', $content);
    }

    /**
     * A Crucible exercise whose only tasks are hidden has nothing to classify.
     */
    public function test_crucible_has_no_content_when_every_task_is_hidden(): void {
        global $DB;

        [$instanceid, $cm, $context] = $this->create_activity('crucible', [
            'eventtemplateid' => 'template-under-test',
            'intro' => '',
        ]);

        $DB->insert_record('crucible_tasks', (object)[
            'crucibleid' => $instanceid,
            'name' => 'Task hidden',
            'description' => '<p>HIDDENDESC</p>',
            'visible' => 0,
            'gradable' => 1,
            'points' => 1,
            'multiple' => 0,
        ]);

        $this->assertFalse(resolver::has_content($cm, $context));
        $this->assertSame('', resolver::get_content($cm, $context));
    }

    /**
     * A PPT Book contributes its captions, in the order the slides are shown.
     */
    public function test_pptbook_content_is_captions_in_slide_order(): void {
        // Slide 10 sorts before slide 2 by string compare, which is not the
        // order the activity shows them in.
        [, $cm, $context] = $this->create_activity('pptbook', [
            'captionsjson' => json_encode([
                'slide10.png' => 'TENTHCAPTION',
                'slide2.png' => 'SECONDCAPTION',
                'slide3.png' => '',
            ]),
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Slide 1: SECONDCAPTION', $content);
        $this->assertStringContainsString('Slide 2: TENTHCAPTION', $content);
    }

    /**
     * A cmi5 course contributes its assignable units, each one once.
     */
    public function test_cmi5launch_content_is_its_assignable_units(): void {
        // The units arrive grouped into blocks, and a unit reachable from more
        // than one block is listed in each of them.
        $aus = [
            [
                [
                    'auIndex' => 0,
                    'title' => [
                        ['lang' => 'de-DE', 'text' => 'GERMANTITLE'],
                        ['lang' => 'en-US', 'text' => 'ENGLISHTITLE'],
                    ],
                    'description' => [['lang' => 'en-US', 'text' => 'FIRSTDESC']],
                ],
                [
                    'auIndex' => 1,
                    'title' => [['lang' => 'fr-FR', 'text' => 'FRENCHONLYTITLE']],
                    'description' => null,
                ],
            ],
            [
                [
                    'auIndex' => 0,
                    'title' => [['lang' => 'en-US', 'text' => 'ENGLISHTITLE']],
                    'description' => [['lang' => 'en-US', 'text' => 'FIRSTDESC']],
                ],
            ],
        ];

        [, $cm, $context] = $this->create_activity('cmi5launch', [
            'aus' => json_encode($aus),
            'courseinfo' => '',
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Assignable unit 1 (ENGLISHTITLE): FIRSTDESC', $content);
        // No English title, so the one language the package does offer is used.
        $this->assertStringContainsString('Assignable unit 2: FRENCHONLYTITLE', $content);
        $this->assertSame(1, substr_count($content, 'FIRSTDESC'));
        $this->assertStringNotContainsString('GERMANTITLE', $content);
    }

    /**
     * A cmi5 course with no units on the activity row falls back to its metadata.
     */
    public function test_cmi5launch_content_falls_back_to_the_course_metadata(): void {
        [, $cm, $context] = $this->create_activity('cmi5launch', [
            'aus' => '',
            'courseinfo' => json_encode([
                'metadata' => [
                    'aus' => [
                        [
                            'auIndex' => 0,
                            'title' => [['lang' => 'en-US', 'text' => 'METADATATITLE']],
                            'description' => [['lang' => 'en-US', 'text' => 'METADATADESC']],
                        ],
                    ],
                ],
            ]),
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Assignable unit 1 (METADATATITLE): METADATADESC', $content);
    }

    /**
     * A choice activity contributes the options it offers.
     */
    public function test_choice_content_is_its_options(): void {
        [, $cm, $context] = $this->create_module('choice', ['option' => ['ALPHAOPTION', 'BETAOPTION']]);

        $this->assertSame('Options: ALPHAOPTION; BETAOPTION', resolver::get_content($cm, $context));
    }

    /**
     * A feedback activity contributes its questions, answers and notes.
     */
    public function test_feedback_content_is_its_items(): void {
        [$feedback, $cm, $context] = $this->create_module('feedback');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_feedback');

        $generator->create_item_multichoice($feedback, [
            'name' => 'QUESTIONNAME',
            'values' => "ALPHAOPTION\nBETAOPTION",
        ]);
        $generator->create_item_pagebreak($feedback);
        $generator->create_item_label($feedback, [
            'presentation_editor' => ['text' => '<p>LABELTEXT</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Question 1: QUESTIONNAME Options: ALPHAOPTION; BETAOPTION', $content);
        // A label asks nothing, so it is not numbered among the questions.
        $this->assertStringContainsString('Information: LABELTEXT', $content);
    }

    /**
     * A database activity contributes its field definitions, not its records.
     */
    public function test_data_content_is_its_field_definitions(): void {
        [$data, $cm, $context] = $this->create_module('data');

        $this->getDataGenerator()->get_plugin_generator('mod_data')->create_field(
            (object)['type' => 'text', 'name' => 'FIELDNAME', 'description' => 'FIELDDESC'],
            $data
        );

        $this->assertStringContainsString('Field 1 (FIELDNAME): FIELDDESC', resolver::get_content($cm, $context));
    }

    /**
     * A collaborative wiki contributes its first page and nothing else.
     */
    public function test_wiki_content_is_the_first_page_of_a_collaborative_wiki(): void {
        [$wiki, $cm, $context] = $this->create_module('wiki', [
            'wikimode' => 'collaborative',
            'firstpagetitle' => 'Home',
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_wiki');

        $generator->create_first_page($wiki, ['content' => 'FIRSTPAGEBODY']);
        $generator->create_page($wiki, ['title' => 'Student page', 'content' => 'STUDENTBODY']);

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('FIRSTPAGEBODY', $content);
        // Every page but the first is learner work.
        $this->assertStringNotContainsString('STUDENTBODY', $content);
    }

    /**
     * An individual wiki contributes nothing: every page in it belongs to a student.
     */
    public function test_wiki_content_is_empty_for_an_individual_wiki(): void {
        [$wiki, $cm, $context] = $this->create_module('wiki', [
            'wikimode' => 'individual',
            'firstpagetitle' => 'Home',
        ]);

        $this->getDataGenerator()->get_plugin_generator('mod_wiki')
            ->create_first_page($wiki, ['content' => 'FIRSTPAGEBODY']);

        $this->assertSame('', resolver::get_content($cm, $context));
    }

    /**
     * A SCORM package contributes the titles from its manifest.
     */
    public function test_scorm_content_is_the_manifest_titles(): void {
        global $DB;

        [$scorm, $cm, $context] = $this->create_module('scorm');

        // The package the generator unzips brings its own titles, which would
        // tie this test to a fixture mod_scorm ships, so the parsed table is
        // replaced with known rows.
        $DB->delete_records('scorm_scoes', ['scorm' => $scorm->id]);
        foreach ([
            ['title' => 'PACKAGETITLE', 'sortorder' => 1],
            ['title' => 'FIRSTSCOTITLE', 'sortorder' => 2],
            // A one-SCO package repeats its title on the organisation row.
            ['title' => 'PACKAGETITLE', 'sortorder' => 3],
        ] as $sco) {
            $DB->insert_record('scorm_scoes', (object)($sco + [
                'scorm' => $scorm->id,
                'manifest' => '',
                'organization' => '',
                'parent' => '/',
                'identifier' => 'sco-' . $sco['sortorder'],
                'launch' => '',
                'scormtype' => 'sco',
            ]));
        }

        $content = resolver::get_content($cm, $context);

        $this->assertSame('Package contents: PACKAGETITLE; FIRSTSCOTITLE', $content);
    }

    /**
     * An IMS content package contributes its table of contents, subitems included.
     */
    public function test_imscp_content_is_its_table_of_contents(): void {
        global $DB;

        [$imscp, $cm, $context] = $this->create_module('imscp');

        // The structure is a serialized tree, which is how mod_imscp stores what
        // it parsed out of the package manifest.
        $DB->set_field('imscp', 'structure', serialize([
            [
                'title' => 'FIRSTITEM',
                'href' => 'first.html',
                'subitems' => [
                    ['title' => 'NESTEDITEM', 'href' => 'nested.html', 'subitems' => []],
                ],
            ],
            ['title' => 'SECONDITEM', 'href' => 'second.html', 'subitems' => []],
        ]), ['id' => $imscp->id]);

        $content = resolver::get_content($cm, $context);

        $this->assertSame('Package contents: FIRSTITEM; NESTEDITEM; SECONDITEM', $content);
    }

    /**
     * A workshop contributes its instructions and its assessment criteria.
     */
    public function test_workshop_content_includes_the_assessment_criteria(): void {
        global $DB;

        [$workshop, $cm, $context] = $this->create_module('workshop', [
            'strategy' => 'accumulative',
            'instructauthorseditor' => self::editor('<p>AUTHORINSTRUCTIONS</p>'),
            'instructreviewerseditor' => self::editor('<p>REVIEWERINSTRUCTIONS</p>'),
        ]);

        // Deliberately inserted out of order, to prove sort is honoured.
        foreach ([
            ['description' => '<p>SECONDCRITERION</p>', 'sort' => 2],
            ['description' => '<p>FIRSTCRITERION</p>', 'sort' => 1],
        ] as $dimension) {
            $DB->insert_record('workshopform_accumulative', (object)($dimension + [
                'workshopid' => $workshop->id,
                'descriptionformat' => FORMAT_HTML,
                'grade' => 10,
                'weight' => 1,
            ]));
        }

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString('Instructions for submission: AUTHORINSTRUCTIONS', $content);
        $this->assertStringContainsString('Instructions for assessment: REVIEWERINSTRUCTIONS', $content);
        $this->assertStringContainsString('Assessment criterion 1: FIRSTCRITERION', $content);
        $this->assertStringContainsString('Assessment criterion 2: SECONDCRITERION', $content);
    }

    /**
     * A rubric contributes what each level of a criterion is meant to look like.
     */
    public function test_workshop_content_includes_rubric_level_definitions(): void {
        global $DB;

        [$workshop, $cm, $context] = $this->create_module('workshop', ['strategy' => 'rubric']);

        $dimensionid = $DB->insert_record('workshopform_rubric', (object)[
            'workshopid' => $workshop->id,
            'sort' => 1,
            'description' => '<p>RUBRICCRITERION</p>',
            'descriptionformat' => FORMAT_HTML,
        ]);

        foreach ([['grade' => 1, 'definition' => 'LOWDEFINITION'], ['grade' => 2, 'definition' => 'HIGHDEFINITION']]
                as $level) {
            $DB->insert_record('workshopform_rubric_levels', (object)($level + [
                'dimensionid' => $dimensionid,
                'definitionformat' => FORMAT_HTML,
            ]));
        }

        $content = resolver::get_content($cm, $context);

        $this->assertStringContainsString(
            'Assessment criterion 1: RUBRICCRITERION Levels: LOWDEFINITION; HIGHDEFINITION',
            $content
        );
    }

    /**
     * Criteria are still sent when the caller already sent the instructions.
     *
     * The drawer scrapes the two instruction fields off the settings form, so
     * for a saved workshop they arrive twice. Only the part that was repeated is
     * dropped: the criteria are not on that form and would otherwise be lost.
     */
    public function test_workshop_criteria_survive_the_instructions_being_sent_twice(): void {
        global $DB;

        [$workshop, $cm, $context] = $this->create_module('workshop', [
            'strategy' => 'accumulative',
            'instructauthorseditor' => self::editor('<p>AUTHORINSTRUCTIONS</p>'),
            'instructreviewerseditor' => self::editor(''),
        ]);

        $DB->insert_record('workshopform_accumulative', (object)[
            'workshopid' => $workshop->id,
            'sort' => 1,
            'description' => '<p>ONLYCRITERION</p>',
            'descriptionformat' => FORMAT_HTML,
            'grade' => 10,
            'weight' => 1,
        ]);

        // Stand in for the drawer sending the intro and instructions it scraped.
        $prompt = resolver::augment_prompt("UNSAVEDINTRO\n\nAUTHORINSTRUCTIONS", $cm, $context);

        $this->assertSame(1, substr_count($prompt, 'AUTHORINSTRUCTIONS'));
        $this->assertStringContainsString('ONLYCRITERION', $prompt);
    }
}
