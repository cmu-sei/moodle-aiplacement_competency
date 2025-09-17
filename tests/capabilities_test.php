<?php
namespace aiplacement_classifyassist;

defined('MOODLE_INTERNAL') || die();

final class capabilities_test extends \basic_testcase {

    private $capabilities;

    protected function setUp(): void {
        global $CFG;
        require($CFG->dirroot . '/ai/placement/classifyassist/db/access.php');
        $this->capabilities = $capabilities;
    }

    public function test_capability_is_declared(): void {
        $this->assertArrayHasKey('aiplacement/classifyassist:classify_text', $this->capabilities);
    }

    public function test_capability_has_expected_structure(): void {
        $c = $this->capabilities['aiplacement/classifyassist:classify_text'];

        $this->assertSame('write', $c['captype']);
        $this->assertSame(CONTEXT_MODULE, $c['contextlevel']);

        $this->assertIsArray($c['archetypes']);
        $this->assertArrayHasKey('manager', $c['archetypes']);
        $this->assertArrayHasKey('editingteacher', $c['archetypes']);
        $this->assertArrayHasKey('teacher', $c['archetypes']);
    }

    public function test_archetypes_allow_correct_roles(): void {
        $archetypes = $this->capabilities['aiplacement/classifyassist:classify_text']['archetypes'];

        $this->assertSame(CAP_ALLOW, $archetypes['manager']);
        $this->assertSame(CAP_ALLOW, $archetypes['editingteacher']);
        $this->assertSame(CAP_ALLOW, $archetypes['teacher']);

        // Ensure some roles are *not* accidentally allowed.
        $this->assertArrayNotHasKey('student', $archetypes);
        $this->assertArrayNotHasKey('guest', $archetypes);
    }
}