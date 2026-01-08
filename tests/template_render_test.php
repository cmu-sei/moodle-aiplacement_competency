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

declare(strict_types=1);

namespace aiplacement_classifyassist;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests template rendering for the Classify Assist plugin.
 *
 * Ensures all templates render without exceptions and produce valid HTML.
 *
 * @package    aiplacement_classifyassist
 * @category   test
 * @coversNothing
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class template_render_test extends \advanced_testcase {
    /**
     * The plugin component name used in template references.
     *
     * @var string
     */
    private const COMPONENT = 'aiplacement_classifyassist';


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
