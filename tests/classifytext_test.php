<?php
declare(strict_types=1);
namespace aiplacement_classifyassist {
defined('MOODLE_INTERNAL') || die();

    class placement {
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
                        // no 'levels' key → external should fall back to $selectedlevels
                        'competencies' => [
                            'T1119 - Recommend vulnerability remediation strategies',
                            'T1119 - Recommend vulnerability remediation strategies',
                        ],
                    ]),
                    'finishreason' => 'stop',
                ]);
            }

            // Default: include duplicates to exercise de-dupe in external.
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
}

namespace aiplacement_classifyassist\external {

    use PHPUnit\Framework\Attributes\CoversClass;

    #[CoversClass(\aiplacement_classifyassist\external\classify_text::class)]
    final class classifytext_test extends \advanced_testcase {

        protected function setUp(): void {
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
            $this->assertEquals(['Analyze', 'Detect'], $result['usedlevels']); // de-duped from model
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
}
