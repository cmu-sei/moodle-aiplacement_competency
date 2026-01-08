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

namespace aiplacement_classifyassist;

/**
 * Unit tests for capabilities in the AI Placement Classify Assist plugin.
 *
 * @package    aiplacement_classifyassist
 * @category   test
 * @coversNothing
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capabilities_test extends \basic_testcase {

    /**
     * Capabilities loaded from the plugin's access.php file.
     *
     * @var array
     */
    private $capabilities;

    protected function setUp(): void {
        parent::setUp();

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
