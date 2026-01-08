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

namespace aiplacement_competency;

/**
 * PHPUnit test for language strings of the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   test
 * @coversNothing
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lang_strings_test extends \basic_testcase {
    public function test_pluginname_string_exists(): void {
        $component = 'aiplacement_competency';

        $this->assertTrue(get_string_manager()->string_exists('pluginname', $component));

        $s = get_string('pluginname', $component);
        $this->assertIsString($s);
        $this->assertNotSame('[[pluginname]]', $s);
        $this->assertNotSame('', trim($s));
    }
}
