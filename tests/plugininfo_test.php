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

namespace aiplacement_competency;

/**
 * PHPUnit tests for plugininfo of the AI Placement Competency plugin.
 *
 * Ensures the plugin is registered in the plugin manager and
 * that its version number is properly set.
 *
 * @package    aiplacement_competency
 * @category   test
 * @coversNothing
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugininfo_test extends \basic_testcase {
    public function test_plugin_is_registered_and_version_is_int(): void {
        $pm = \core_plugin_manager::instance();
        $info = $pm->get_plugin_info('aiplacement_competency');
        $this->assertNotNull($info);
        $this->assertIsInt((int)$info->versiondb);
    }
}
