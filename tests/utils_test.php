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

use aiplacement_competency\local\utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the utils helper class in the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(utils::class)]
final class utils_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_build_instruction_includes_framework_and_levels_and_dedupes(): void {
        $frameworkid = 42;
        $shortname = 'NICE-1.0.0';
        $levels = ['  Analyze  ', 'Analyze', 'Detect', '', null, 123, 'DETECT', '  detect '];

        $str = utils::build_instruction($frameworkid, $shortname, $levels);

        $this->assertStringContainsString($shortname, $str);

        if (preg_match('/levels?\s*:\s*(.+)/i', $str, $m)) {
            $list = $m[1];

            $tokens = array_filter(array_map('trim', explode(',', $list)), fn($s) => $s !== '');
            $norm = array_map(fn($s) => mb_strtolower($s), $tokens);

            $this->assertSame(
                ['analyze', 'detect'],
                array_values(array_unique($norm)),
                'Levels list should be deduped and normalized (order Analyze, Detect).'
            );
            $this->assertGreaterThanOrEqual(1, substr_count($list, 'Analyze'));
            $this->assertGreaterThanOrEqual(1, max(substr_count($list, 'Detect'), substr_count($list, 'detect')));
        } else {
            $this->assertStringContainsStringIgnoringCase('Analyze', $str);
            $this->assertStringContainsStringIgnoringCase('Detect', $str);
        }
    }

    public function test_build_instruction_matches_a_level_whatever_its_case(): void {
        $this->setAdminUser();
        $framework = $this->create_framework([
            'Operate and Maintain' => ['OM-1' => 'Administer the network'],
        ]);

        // The drawer lowercases the level before sending it.
        $instruction = utils::build_instruction($framework, 'TESTFW', ['operate and maintain']);

        $this->assertStringContainsString('OM-1 - Administer the network', $instruction);
        // Named back with the shortname the framework uses, not as it arrived.
        $this->assertStringContainsString('Operate and Maintain', $instruction);
    }

    public function test_build_instruction_ignores_a_level_that_is_only_inside_another_name(): void {
        $this->setAdminUser();
        $framework = $this->create_framework([
            'IT' => ['IT-1' => 'Maintain the server estate'],
            'Monitoring' => ['MON-1' => 'Watch the sensor feeds'],
        ]);

        $instruction = utils::build_instruction($framework, 'TESTFW', ['it']);

        $this->assertStringContainsString('IT-1 - Maintain the server estate', $instruction);
        // 'it' is inside 'Monitoring', which used to be enough to pull it in.
        $this->assertStringNotContainsString('MON-1', $instruction);
        $this->assertStringNotContainsString('Watch the sensor feeds', $instruction);
    }

    public function test_build_instruction_falls_back_to_a_partial_level_match(): void {
        $this->setAdminUser();
        $framework = $this->create_framework([
            'Securely Provision' => ['SP-1' => 'Design secure systems'],
            'Investigate' => ['IN-1' => 'Collect digital evidence'],
        ]);

        // Not a shortname, so nothing matches outright and the fallback runs.
        $instruction = utils::build_instruction($framework, 'TESTFW', ['Securely']);

        $this->assertStringContainsString('SP-1 - Design secure systems', $instruction);
        $this->assertStringNotContainsString('IN-1', $instruction);
    }

    public function test_build_instruction_without_a_level_offers_the_whole_framework(): void {
        $this->setAdminUser();
        $framework = $this->create_framework([
            'Analyze' => ['AN-1' => 'Interpret threat reporting'],
            'Protect and Defend' => ['PD-1' => 'Respond to incidents'],
        ]);

        $instruction = utils::build_instruction($framework, 'TESTFW', []);

        $this->assertStringContainsString('AN-1 - Interpret threat reporting', $instruction);
        $this->assertStringContainsString('PD-1 - Respond to incidents', $instruction);
    }

    /**
     * Creates a framework of top level competencies, each with children.
     *
     * @param array $levels Level shortname => [child shortname => child description].
     * @return int The framework id.
     */
    private function create_framework(array $levels): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework(['shortname' => 'TESTFW']);
        $frameworkid = (int)$framework->get('id');

        foreach ($levels as $shortname => $children) {
            $parent = $generator->create_competency([
                'competencyframeworkid' => $frameworkid,
                'shortname' => $shortname,
                'description' => '',
            ]);

            foreach ($children as $childshortname => $description) {
                $generator->create_competency([
                    'competencyframeworkid' => $frameworkid,
                    'parentid' => $parent->get('id'),
                    'shortname' => $childshortname,
                    'description' => $description,
                ]);
            }
        }

        return $frameworkid;
    }

    public function test_extract_classification_from_plain_json_string_payload(): void {
        $payload = json_encode([
            'framework' => ['shortname' => 'NICE-1.0.0'],
            'levels' => ['Analyze', 'Detect'],
            'competencies' => ['T1119 - Recommend vulnerability remediation strategies', '  ',
                                null, 'T1119 - Recommend vulnerability remediation strategies'],
        ]);

        $out = utils::extract_classification(['generatedcontent' => $payload]);

        $this->assertSame('NICE-1.0.0', $out['frameworkshortname']);
        $this->assertEquals(['Analyze', 'Detect'], $out['levels']);
        $this->assertEquals(['T1119 - Recommend vulnerability remediation strategies'], $out['competencies']);
    }

    public function test_extract_classification_from_array_response_nested_response_string(): void {
        $inner = [
            'response' => json_encode([
                'framework' => ['shortname' => 'MITRE D3FEND'],
                'levels' => ['Harden', 'Detect', 'Harden'],
                'competencies' => ['Network Segmentation', '  ', 'Email Content Filtering'],
            ]),
        ];
        $raw = ['response' => $inner];

        $out = utils::extract_classification($raw);
        $this->assertSame('MITRE D3FEND', $out['frameworkshortname']);
        $this->assertEquals(['Harden', 'Detect'], $out['levels']);
        $this->assertEquals(['Network Segmentation', 'Email Content Filtering'], $out['competencies']);
    }

    public function test_extract_classification_handles_malformed_and_empty_values(): void {
        $out = utils::extract_classification(['generatedcontent' => '{}']);
        $this->assertSame('', $out['frameworkshortname']);
        $this->assertSame([], $out['levels']);
        $this->assertSame([], $out['competencies']);

        $payload = json_encode(['framework' => ['shortname' => '   '], 'levels' => [null, 1, ' '], 'competencies' => [false]]);
        $out = utils::extract_classification(['generatedcontent' => $payload]);
        $this->assertSame('', $out['frameworkshortname']);
        $this->assertSame([], $out['levels']);
        $this->assertSame([], $out['competencies']);
    }

    public function test_extract_classification_allows_array_payload_without_stringification(): void {
        $raw = [
            'generatedcontent' => [
                'framework' => ['shortname' => 'ATT&CK'],
                'levels' => ['Initial Access', 'Execution', 'Execution'],
                'competencies' => ['T1566 - Phishing', 'T1059 - Command and Scripting Interpreter'],
            ],
        ];
        $out = utils::extract_classification($raw);
        $this->assertSame('ATT&CK', $out['frameworkshortname']);
        $this->assertEquals(['Initial Access', 'Execution'], $out['levels']);
        $this->assertEquals(
            ['T1566 - Phishing', 'T1059 - Command and Scripting Interpreter'],
            $out['competencies']
        );
    }
}
