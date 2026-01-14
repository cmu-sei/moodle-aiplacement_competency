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

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests template rendering for the AI Placement Competency plugin.
 *
 * Ensures all templates render without exceptions and produce valid HTML.
 *
 * @package    aiplacement_competency
 * @category   test
 * @coversNothing
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_render_test extends \advanced_testcase {
    /**
     * The plugin component name used in template references.
     *
     * @var string
     */
    private const COMPONENT = 'aiplacement_competency';


    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    #[DataProvider('templateProvider')]
    public function test_template_renders_without_exceptions(string $templatename, array $context): void {
        global $PAGE, $OUTPUT;

        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/'));

        $html = $OUTPUT->render_from_template($templatename, $context);

        $this->assertIsString($html, "Render returned non-string for {$templatename}");
        $this->assertNotSame('', trim($html), "Rendered HTML is empty for {$templatename}");
    }

    /**
     * Provides template names and contexts for testing.
     *
     * @return array[] List of [templatename, context] pairs
     */
    public static function template_provider(): array {
        $c = self::COMPONENT;

        return [
            [$c . '/applycmps_modal', [
                'hascompetencies' => true,
                'competencies' => [
                    'T1119 - Recommend vulnerability remediation strategies',
                    'T1059 - Command and Scripting Interpreter',
                ],
            ]],

            [$c . '/classify_button', [
            ]],

            [$c . '/drawer', [
                'userid' => 2,
                'contextid' => \context_system::instance()->id,
            ]],

            [$c . '/error', []],

            [$c . '/framework_select', []],

            [$c . '/levels', [
                'options' => [
                    ['value' => 'analyze', 'label' => 'Analyze', 'help' => 'Use to examine data'],
                    ['value' => 'detect',  'label' => 'Detect'],
                ],
            ]],

            [$c . '/loading', []],

            [$c . '/newactivity_notice', [
                'message' => '<strong>Heads up:</strong> a new activity was created.',
            ]],

            [$c . '/response', [
                'action' => 'classify',
                'heading' => 'AI Classification',
                'uniqid' => 'abc123',
                'frameworkid' => 42,
                'frameworkshortname' => 'NICE-1.0.0',
                'usedlevels' => ['Analyze', 'Detect'],
                'competencies' => [
                    'T1119 - Recommend vulnerability remediation strategies',
                    'T1059 - Command and Scripting Interpreter',
                ],
            ]],
        ];
    }
}
