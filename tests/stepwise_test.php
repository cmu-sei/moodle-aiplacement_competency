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

use aiplacement_competency\external\classify_start;
use aiplacement_competency\external\classify_step;
use aiplacement_competency\local\utils;
use core_ai\aiactions\responses\response_generate_text;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for classifying a step at a time, choosing work roles first, and the progress the steps report.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(classify_start::class)]
#[CoversClass(classify_step::class)]
#[CoversClass(local\job::class)]
final class stepwise_test extends \advanced_testcase {
    /** @var string[] The prompt of every request the mocked manager was given. */
    private array $prompts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->prompts = [];
        // Most tests are about the roles' own statements, so the keyword matches beside them are off unless asked for.
        set_config('keywordextras', 0, 'aiplacement_competency');
    }

    /**
     * Answers each request with the next payload, the last repeating.
     *
     * @param array[] $payloads What the model "returns", as arrays.
     */
    private function mock_model(array $payloads): void {
        $calls = 0;
        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturnCallback(
            function (\core_ai\aiactions\base $action) use ($payloads, &$calls) {
                $this->prompts[] = (string)$action->get_configuration('prompttext');
                $response = new response_generate_text(success: true);
                $response->set_response_data([
                    'generatedcontent' => json_encode($payloads[min($calls++, count($payloads) - 1)]),
                    'finishreason' => 'stop',
                ]);

                return $response;
            }
        );
        $mock->method('is_action_available')->willReturn(true);
        $mock->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mock) {
            return $mock;
        });
    }

    /**
     * A framework like DCWF: categories of statements, and a group of work roles that list their statements.
     *
     * Role A has task T1, knowledge K1 and a skill; role B has task T2 and knowledge K2.
     *
     * @return int The framework id.
     */
    private function create_role_framework(): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'ROLEFW'])->get('id');
        $make = static function (string $shortname, string $description, int $parent = 0) use ($generator, $fw) {
            return $generator->create_competency([
                'competencyframeworkid' => $fw,
                'parentid' => $parent,
                'shortname' => $shortname,
                'idnumber' => explode(' - ', $shortname)[0],
                'description' => $description,
            ]);
        };
        // Moodle keeps the lower id of a related pair first, and a role is a competency that lists others and is
        // not listed, so the roles come first, as in the imports.
        $roles = $make('Roles', '');
        $rolea = $make('RA', 'Incident Responder', $roles->get('id'));
        $roleb = $make('RB', 'Payroll Auditor', $roles->get('id'));
        $tasks = $make('Tasks', '');
        $knowledge = $make('Knowledge', '');
        $skills = $make('Skills', '');
        $t1 = $make('T1', 'Triage intrusion alerts', $tasks->get('id'));
        $t2 = $make('T2', 'Audit the payroll ledger', $tasks->get('id'));
        $k1 = $make('K1', 'Knowledge of intrusion detection', $knowledge->get('id'));
        $k2 = $make('K2', 'Knowledge of ledger controls', $knowledge->get('id'));
        $s1 = $make('S1', 'Skill in reading alert logs', $skills->get('id'));
        foreach ([[$rolea, $t1], [$rolea, $k1], [$rolea, $s1], [$roleb, $t2], [$roleb, $k2]] as [$role, $item]) {
            $generator->create_related_competency([
                'competencyid' => $role->get('id'),
                'relatedcompetencyid' => $item->get('id'),
            ]);
        }

        return $fw;
    }

    /**
     * An activity, for a context to classify in.
     *
     * @return \context_module The context.
     */
    private function create_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Intrusion triage',
            'content' => '<p>Learners triage intrusion alerts.</p>',
        ]);

        return \context_module::instance($cm->cmid);
    }

    /**
     * Runs a job to the end through the web service functions.
     *
     * @param \context_module $context The context.
     * @param int $fw The framework id.
     * @param string[] $levels The ticked levels.
     * @param bool $verify Whether to verify.
     * @return array [the start response, the last step response, every step response].
     */
    private function run_job(\context_module $context, int $fw, array $levels, bool $verify = false): array {
        $started = classify_start::execute($context->id, 'Learners triage intrusion alerts.', $fw, 'ROLEFW', $levels, $verify);
        $steps = [];
        do {
            $step = classify_step::execute($started['jobid']);
            $steps[] = $step;
        } while (!$step['finished']);

        return [$started, $step, $steps];
    }

    public function test_roles_exist_only_when_a_group_lists_others(): void {
        $fw = $this->create_role_framework();

        $roles = utils::get_roles($fw);

        $this->assertEqualsCanonicalizing(['RA', 'RB'], array_column($roles, 'idnumber'));
        $this->assertTrue(utils::needs_role_pick($fw, ['Tasks', 'Knowledge']));
        // Ticking a group of roles names the roles already.
        $this->assertFalse(utils::needs_role_pick($fw, ['Roles']));
        $this->assertFalse(utils::needs_role_pick($fw, ['No such level']));
    }

    public function test_a_role_is_described_by_its_sentence_not_the_codes_that_follow_it(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'DESCFW'])->get('id');
        $role = $generator->create_competency([
            'competencyframeworkid' => $fw,
            'shortname' => 'Role X (1)',
            'idnumber' => 'RX',
            'description' => 'Triages alerts. Core (2): T-1, T-2 Additional (1): T-3',
        ]);
        $task = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'T-1', 'idnumber' => 'T-1']);
        $generator->create_related_competency(['competencyid' => $role->get('id'), 'relatedcompetencyid' => $task->get('id')]);

        $line = array_values(utils::get_roles($fw))[0]->line;

        $this->assertSame('Role X (1) - Triages alerts.', $line);
    }

    public function test_the_role_pool_is_the_roles_statements_in_the_ticked_categories(): void {
        $fw = $this->create_role_framework();
        $rolea = array_values(array_filter(utils::get_roles($fw), fn($r) => $r->idnumber === 'RA'))[0];

        $pool = utils::role_pool($fw, [(int)$rolea->id], ['Tasks', 'Knowledge']);

        $this->assertSame(['T1', 'K1'], array_column($pool, 'idnumber'));
        $this->assertSame(['Tasks', 'Knowledge'], array_column($pool, 'category'));
    }

    public function test_a_job_chooses_roles_then_tags_from_their_statements_only(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([
            ['roles' => ['RA']],
            ['competencies' => [
                ['idnumber' => 'T1', 'reason' => 'Learners triage alerts.'],
                ['idnumber' => 'K1', 'reason' => 'Detection is explained.'],
                ['idnumber' => 'T2', 'reason' => 'Not on offer.'],
            ]],
        ]);

        [$started, $last, $steps] = $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        $this->assertSame(2, $started['total']);
        $this->assertStringContainsString('choosing the work roles', $started['message']);
        $this->assertFalse($steps[0]['finished']);
        $this->assertStringContainsString('matching competencies', $steps[0]['message']);
        $this->assertTrue($last['finished']);

        // The first request offers every role, the second only role A's statements, headed by category.
        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('[RB]', $this->prompts[0]);
        $this->assertStringContainsString('== Tasks ==', $this->prompts[1]);
        $this->assertStringContainsString('[T1]', $this->prompts[1]);
        $this->assertStringNotContainsString('[T2]', $this->prompts[1]);
        $this->assertStringNotContainsString('[S1]', $this->prompts[1]);

        $result = $last['result'];
        $this->assertSame(['T1', 'K1'], array_column($result['suggestions'], 'idnumber'));
        $this->assertSame(['Tasks', 'Knowledge'], array_column($result['suggestions'], 'category'));
        $this->assertSame(['RA'], $result['roles']);
    }

    public function test_tags_are_capped_for_each_category_not_overall(): void {
        $this->setAdminUser();
        set_config('maxtags', 10, 'aiplacement_competency');
        set_config('maxtagspercategory', 1, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([
            ['roles' => ['RA', 'RB']],
            ['competencies' => ['T1', 'T2', 'K1', 'K2']],
        ]);

        [, $last] = $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        $this->assertSame(['T1', 'K1'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_a_section_with_no_usable_role_falls_back_to_the_keyword_shortlist(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([
            ['roles' => ['NOPE']],
            ['competencies' => ['T1']],
        ]);

        [, $last] = $this->run_job($context, $fw, ['Tasks']);

        // The shortlist is every task, not one role's.
        $this->assertStringContainsString('[T2]', $this->prompts[1]);
        $this->assertSame(['T1'], array_column($last['result']['suggestions'], 'idnumber'));
        $this->assertSame([], $last['result']['roles']);
    }

    public function test_a_framework_without_roles_is_one_step_per_section(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'FLAT'])->get('id');
        $parent = $generator->create_competency([
            'competencyframeworkid' => $fw,
            'shortname' => 'Tactics',
            'idnumber' => 'Tactics',
        ]);
        $generator->create_competency([
            'competencyframeworkid' => $fw,
            'parentid' => $parent->get('id'),
            'shortname' => 'X1',
            'idnumber' => 'X1',
            'description' => 'Phishing',
        ]);
        $context = $this->create_context();
        $this->mock_model([['competencies' => ['X1']]]);

        [$started, $last] = $this->run_job($context, $fw, ['Tactics']);

        $this->assertSame(1, $started['total']);
        $this->assertSame(['X1'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_verify_is_a_step_of_its_own(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([
            ['roles' => ['RA']],
            ['competencies' => ['T1', 'K1']],
            ['keep' => ['T1']],
        ]);

        [$started, $last, $steps] = $this->run_job($context, $fw, ['Tasks', 'Knowledge'], true);

        $this->assertSame(3, $started['total']);
        $this->assertStringContainsString('Double-checking', $steps[1]['message']);
        $this->assertSame(['T1'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_another_user_cannot_run_a_job_and_a_spent_job_is_gone(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA']], ['competencies' => ['T1']]]);
        $started = classify_start::execute($context->id, 'Text.', $fw, 'ROLEFW', ['Tasks']);

        $this->setUser($this->getDataGenerator()->create_user());
        try {
            classify_step::execute($started['jobid']);
            $this->fail('Another user must not run the job.');
        } catch (\moodle_exception $e) {
            $this->assertSame('classifyjobgone', $e->errorcode);
        }

        $this->setAdminUser();
        classify_step::execute($started['jobid']);
        $done = classify_step::execute($started['jobid']);
        $this->assertTrue($done['finished']);

        $this->expectException(\moodle_exception::class);
        classify_step::execute($started['jobid']);
    }

    public function test_a_section_with_no_role_chosen_says_so(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([
            ['roles' => []],
            ['competencies' => ['T1']],
        ]);

        [, $last] = $this->run_job($context, $fw, ['Tasks']);

        $warnings = implode(' ', $last['result']['warnings']);
        $this->assertStringContainsString('No work role could be chosen for 1 of 1 sections', $warnings);
    }

    public function test_a_failed_role_request_is_not_an_error_of_its_own_but_is_said(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $calls = 0;
        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturnCallback(function () use (&$calls) {
            if ($calls++ === 0) {
                try {
                    return new response_generate_text(success: false, errorcode: 500, error: 'Error', errormessage: 'Timeout');
                } catch (\Error $e) {
                    return new response_generate_text(success: false, errorcode: 500, errormessage: 'Timeout');
                }
            }
            $response = new response_generate_text(success: true);
            $response->set_response_data([
                'generatedcontent' => json_encode(['competencies' => ['T1']]),
                'finishreason' => 'stop',
            ]);

            return $response;
        });
        $mock->method('is_action_available')->willReturn(true);
        $mock->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mock) {
            return $mock;
        });

        [, $last] = $this->run_job($context, $fw, ['Tasks']);

        $this->assertSame(['T1'], array_column($last['result']['suggestions'], 'idnumber'));
        $this->assertStringContainsString('No work role could be chosen', implode(' ', $last['result']['warnings']));
    }

    public function test_the_synchronous_call_leaves_room_for_both_requests_of_a_section_with_roles(): void {
        $this->setAdminUser();
        set_config('timebudget', '0.5', 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $text = str_repeat("Some lecture material about intrusion alerts.\n\n", 800);
        $answers = [['roles' => ['RA']], ['competencies' => ['T1']]];
        $calls = 0;
        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturnCallback(function (\core_ai\aiactions\base $action) use (&$calls, $answers) {
            $this->prompts[] = 'request';
            usleep(250000);
            $response = new response_generate_text(success: true);
            $response->set_response_data([
                'generatedcontent' => json_encode($answers[$calls++ % 2]),
                'finishreason' => 'stop',
            ]);

            return $response;
        });
        $mock->method('is_action_available')->willReturn(true);
        $mock->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mock) {
            return $mock;
        });

        $result = external\classify_text::execute($context->id, $text, $fw, 'ROLEFW', ['Tasks']);

        // The first section (two requests, 0.5 s) uses the budget: a second section's roles request would need 0.5 s
        // more, so only the first section is read, and no request starts that could end past the budget.
        $this->assertCount(2, $this->prompts);
        $this->assertStringContainsString('Stopped after 1 of', implode(' ', $result['warnings']));
    }

    public function test_an_empty_role_pool_falls_back_with_a_warning(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        // Role B has no skills, so ticking Skills leaves its pool empty.
        $this->mock_model([['roles' => ['RB']], ['competencies' => ['S1']]]);

        [, $last] = $this->run_job($context, $fw, ['Skills']);

        $this->assertStringContainsString('No work role could be chosen', implode(' ', $last['result']['warnings']));
        $this->assertSame(['S1'], array_column($last['result']['suggestions'], 'idnumber'));
        // Matched without roles, but still told which category it is.
        $this->assertSame(['Skills'], array_column($last['result']['suggestions'], 'category'));
    }

    public function test_tags_found_without_roles_are_kept_for_their_own_category(): void {
        $this->setAdminUser();
        set_config('maxtags', 10, 'aiplacement_competency');
        set_config('maxtagspercategory', 1, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $text = str_repeat("Intrusion alerts are triaged by the learners.\n\n", 800);
        // Section 1 has no usable role and gives T1, section 2 the same and gives K1: one tag for each category.
        $this->mock_model([
            ['roles' => []], ['competencies' => ['T1']],
            ['roles' => []], ['competencies' => ['K1']],
        ]);
        $started = classify_start::execute($context->id, $text, $fw, 'ROLEFW', ['Tasks', 'Knowledge']);
        $this->assertGreaterThanOrEqual(4, $started['total']);
        do {
            $step = classify_step::execute($started['jobid']);
        } while (!$step['finished']);

        $this->assertEqualsCanonicalizing(['T1', 'K1'], array_column($step['result']['suggestions'], 'idnumber'));
    }

    public function test_the_provider_message_survives_a_total_failure(): void {
        $this->setAdminUser();
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $mock = $this->createMock(\core_ai\manager::class);
        $mock->method('process_action')->willReturnCallback(function () {
            try {
                return new response_generate_text(success: false, errorcode: 500, error: 'Error', errormessage: 'Quota exceeded');
            } catch (\Error $e) {
                return new response_generate_text(success: false, errorcode: 500, errormessage: 'Quota exceeded');
            }
        });
        $mock->method('is_action_available')->willReturn(true);
        $mock->method('is_action_enabled')->willReturn(true);
        \core\di::set(\core_ai\manager::class, function () use ($mock) {
            return $mock;
        });

        try {
            $this->run_job($context, $fw, ['Tasks']);
            $this->fail('Every section failed, so this should throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aiclassifyfailed', $e->errorcode);
            $this->assertStringContainsString('Quota exceeded', (string)$e->debuginfo);
        }
    }

    public function test_narrowing_a_role_pool_keeps_every_category(): void {
        $this->setAdminUser();
        set_config('maxcandidates', 6, 'aiplacement_competency');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = (int)$generator->create_framework(['shortname' => 'BIGFW'])->get('id');
        $role = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'Role', 'idnumber' => 'R']);
        $tasks = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'Tasks', 'idnumber' => 'Tasks']);
        $skills = $generator->create_competency(['competencyframeworkid' => $fw, 'shortname' => 'Skills', 'idnumber' => 'Skills']);
        foreach (range(1, 20) as $i) {
            $task = $generator->create_competency([
                'competencyframeworkid' => $fw,
                'parentid' => $tasks->get('id'),
                'shortname' => "T{$i}",
                'idnumber' => "T{$i}",
                'description' => 'Triage intrusion alerts',
            ]);
            $generator->create_related_competency(['competencyid' => $role->get('id'), 'relatedcompetencyid' => $task->get('id')]);
        }
        $skill = $generator->create_competency([
            'competencyframeworkid' => $fw,
            'parentid' => $skills->get('id'),
            'shortname' => 'S1',
            'idnumber' => 'S1',
            'description' => 'Unrelated wording',
        ]);
        $generator->create_related_competency(['competencyid' => $role->get('id'), 'relatedcompetencyid' => $skill->get('id')]);

        $pool = utils::role_pool($fw, [(int)$role->get('id')], ['Tasks', 'Skills']);
        $prepared = utils::prepare($fw, 'BIGFW', ['Tasks', 'Skills'], 'Learners triage intrusion alerts.', '', $pool);

        $this->assertLessThanOrEqual(7, count($prepared['candidates']));
        $this->assertContains('S1', array_column($prepared['candidates'], 'idnumber'));
    }

    public function test_keyword_matches_are_sent_beside_the_roles_statements(): void {
        $this->setAdminUser();
        set_config('keywordextras', 150, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA']], ['competencies' => ['T1', 'T2']]]);

        [, $last] = $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        // T2 is role B's, and the model chose role A, but it is a keyword match for the ticked categories.
        $this->assertStringContainsString('[T2]', $this->prompts[1]);
        $this->assertStringContainsString('== Tasks ==', $this->prompts[1]);
        $this->assertEqualsCanonicalizing(['T1', 'T2'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_keyword_matches_are_limited_to_the_ticked_categories(): void {
        $this->setAdminUser();
        set_config('keywordextras', 150, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA']], ['competencies' => ['T1']]]);

        $this->run_job($context, $fw, ['Tasks']);

        $this->assertStringNotContainsString('[K2]', $this->prompts[1]);
        $this->assertStringNotContainsString('[S1]', $this->prompts[1]);
    }

    public function test_the_defaults_are_four_in_all_and_two_from_one_category(): void {
        // A site that saved the settings page holds the old default; these are the defaults of one that has not.
        unset_config('maxtags', 'aiplacement_competency');
        unset_config('maxtagspercategory', 'aiplacement_competency');
        $this->assertSame(4, utils::max_tags());
        $this->assertSame(2, utils::max_tags_per_category());
    }

    public function test_the_total_cap_is_reached_before_the_category_cap_when_it_is_lower(): void {
        $this->setAdminUser();
        set_config('maxtags', 3, 'aiplacement_competency');
        set_config('maxtagspercategory', 2, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA', 'RB']], ['competencies' => ['T1', 'T2', 'K1', 'K2']]]);

        [, $last] = $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        // Four tags are on offer, two from each category, and the total allows three, in the order they were found.
        $this->assertSame(['T1', 'T2', 'K1'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_no_one_category_fills_the_quota(): void {
        $this->setAdminUser();
        set_config('maxtags', 4, 'aiplacement_competency');
        set_config('maxtagspercategory', 1, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA', 'RB']], ['competencies' => ['T1', 'T2', 'K1', 'K2']]]);

        [, $last] = $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        $this->assertSame(['T1', 'K1'], array_column($last['result']['suggestions'], 'idnumber'));
    }

    public function test_the_prompt_states_both_caps(): void {
        $this->setAdminUser();
        set_config('maxtags', 5, 'aiplacement_competency');
        set_config('maxtagspercategory', 2, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => ['RA']], ['competencies' => ['T1']]]);

        $this->run_job($context, $fw, ['Tasks', 'Knowledge']);

        $this->assertStringContainsString('at most 2 competencies from any one of the groups', $this->prompts[1]);
        $this->assertStringContainsString('at most 5 in all', $this->prompts[1]);
    }

    public function test_a_section_no_role_fits_gets_only_the_keyword_matches_not_the_whole_shortlist(): void {
        $this->setAdminUser();
        set_config('keywordextras', 1, 'aiplacement_competency');
        $fw = $this->create_role_framework();
        $context = $this->create_context();
        $this->mock_model([['roles' => []], ['competencies' => ['T1']]]);

        [, $last] = $this->run_job($context, $fw, ['Tasks']);

        // The text is about triaging intrusion alerts, so the one keyword match is T1, and T2 is not offered.
        $this->assertStringContainsString('[T1]', $this->prompts[1]);
        $this->assertStringNotContainsString('[T2]', $this->prompts[1]);
        $this->assertSame(['T1'], array_column($last['result']['suggestions'], 'idnumber'));
        $this->assertStringContainsString('No work role could be chosen', implode(' ', $last['result']['warnings']));
    }
}
