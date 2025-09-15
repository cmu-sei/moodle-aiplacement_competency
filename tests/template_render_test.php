<?php
declare(strict_types=1);

namespace aiplacement_classifyassist;

defined('MOODLE_INTERNAL') || die();

use PHPUnit\Framework\Attributes\DataProvider;

final class template_render_test extends \advanced_testcase {
    private const COMPONENT = 'aiplacement_classifyassist';

    protected function setUp(): void {
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

    /** List all templates and their minimal render contexts. */
    public static function templateProvider(): array {
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
