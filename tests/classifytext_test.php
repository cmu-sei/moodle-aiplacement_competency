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

/**
 * Unit tests for the classify_text external function in the
 * AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   test
 * @covers     \aiplacement_competency\external\classify_text
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
declare(strict_types=1);
namespace aiplacement_competency;

defined('MOODLE_INTERNAL') || die();

use aiplacement_competency\external\classify_text;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Fake placement stub for testing.
 *
 * @package    aiplacement_competency
 * @category   test
 */
class fake_placement {
    /**
     * Fake classify method that simulates AI classification output.
     *
     * @param \context $context The Moodle context
     * @param string $prompttext The input prompt
     * @param int $selectedframeworkid Framework ID
     * @param string $selectedframeworkshortname Framework shortname
     * @param array $selectedlevels Selected levels
     * @return string JSON encoded classification response
     */
    public function classify(
        \context $context,
        string $prompttext,
        int $selectedframeworkid,
        string $selectedframeworkshortname,
        array $selectedlevels
    ): string {
        if ($prompttext === '__NO_LEVELS__') {
            return json_encode([
                'generatedcontent' => json_encode([
                    'framework'    => ['shortname' => $selectedframeworkshortname ?: 'NICE-1.0.0'],
                    'competencies' => [
                        'T1119 - Recommend vulnerability remediation strategies',
                        'T1119 - Recommend vulnerability remediation strategies',
                    ],
                ]),
                'finishreason' => 'stop',
            ]);
        }

        return json_encode([
            'generatedcontent' => json_encode([
                'framework'    => ['shortname' => 'NICE-1.0.0'],
                'levels'       => ['Analyze', 'Detect', 'Analyze'],
                'competencies' => [
                    'T1119 - Recommend vulnerability remediation strategies',
                    'T1119 - Recommend vulnerability remediation strategies',
                ],
            ]),
            'finishreason' => 'stop',
        ]);
    }
}

/**
 * Unit tests for the classify_text external function.
 *
 * @package    aiplacement_competency
 * @category   test
 * @covers     \aiplacement_competency\external\classify_text
 */
final class classifytext_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_execute_success_parses_and_dedupes(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cmctx = \context_module::instance($cm->cmid);

        $this->setAdminUser();

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

    public function test_execute_falls_back_to_selectedlevels_when_response_missing_levels(): void {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cmctx = \context_module::instance($cm->cmid);

        $this->setAdminUser();

        $selected = ['Analyze', 'Detect'];
        $result = classify_text::execute(
            $cmctx->id,
            '__NO_LEVELS__',
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

