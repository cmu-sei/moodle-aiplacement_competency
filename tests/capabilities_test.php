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
namespace aiplacement_competency;

/**
 * Unit tests for capabilities in the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   test
 * @coversNothing
 * @copyright  2026 Carnegie Mellon University
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
        require($CFG->dirroot . '/ai/placement/competency/db/access.php');
        $this->capabilities = $capabilities;
    }

    public function test_capability_is_declared(): void {
        $this->assertArrayHasKey('aiplacement/competency:classify_text', $this->capabilities);
    }

    public function test_capability_has_expected_structure(): void {
        $c = $this->capabilities['aiplacement/competency:classify_text'];

        $this->assertSame('write', $c['captype']);
        $this->assertSame(CONTEXT_MODULE, $c['contextlevel']);

        $this->assertIsArray($c['archetypes']);
        $this->assertArrayHasKey('manager', $c['archetypes']);
        $this->assertArrayHasKey('editingteacher', $c['archetypes']);
        $this->assertArrayHasKey('teacher', $c['archetypes']);
    }

    public function test_archetypes_allow_correct_roles(): void {
        $archetypes = $this->capabilities['aiplacement/competency:classify_text']['archetypes'];

        $this->assertSame(CAP_ALLOW, $archetypes['manager']);
        $this->assertSame(CAP_ALLOW, $archetypes['editingteacher']);
        $this->assertSame(CAP_ALLOW, $archetypes['teacher']);

        // Ensure some roles are *not* accidentally allowed.
        $this->assertArrayNotHasKey('student', $archetypes);
        $this->assertArrayNotHasKey('guest', $archetypes);
    }
}
