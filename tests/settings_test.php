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

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS"
BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER
INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM
USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM
PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact
permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see
Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

declare(strict_types=1);

namespace aiplacement_competency;

use aiplacement_competency\local\utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the plugin's admin settings.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(utils::class)]
final class settings_test extends \advanced_testcase {
    public function test_the_default_framework_is_found_by_idnumber_then_shortname(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $dcwf = $generator->create_framework(['shortname' => 'DoD Cyber Workforce Framework', 'idnumber' => 'DCWF']);
        $other = $generator->create_framework(['shortname' => 'DCWF', 'idnumber' => 'OTHER']);

        $this->assertSame(0, utils::default_framework_id());

        set_config('defaultframework', ' dcwf ', 'aiplacement_competency');
        // The idnumber wins over a framework whose shortname is the same word.
        $this->assertSame((int)$dcwf->get('id'), utils::default_framework_id());

        set_config('defaultframework', 'DoD Cyber Workforce Framework', 'aiplacement_competency');
        $this->assertSame((int)$dcwf->get('id'), utils::default_framework_id());

        set_config('defaultframework', 'OTHER', 'aiplacement_competency');
        $this->assertSame((int)$other->get('id'), utils::default_framework_id());

        set_config('defaultframework', 'No such framework', 'aiplacement_competency');
        $this->assertSame(0, utils::default_framework_id());
    }

    public function test_a_hidden_framework_is_not_a_default(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $generator->create_framework(['shortname' => 'HIDDEN-FW', 'idnumber' => 'HID', 'visible' => 0]);
        set_config('defaultframework', 'HID', 'aiplacement_competency');

        $this->assertSame(0, utils::default_framework_id());
    }

    public function test_the_default_framework_setting_is_registered_as_text(): void {
        global $CFG;
        $this->resetAfterTest();

        $ADMIN = new \stdClass();
        $ADMIN->fulltree = true;
        $settings = new \admin_settingpage('aiplacement_competency_test', 'Test');
        require($CFG->dirroot . '/ai/placement/competency/settings.php');

        $found = null;
        foreach ((array)$settings->settings as $setting) {
            if ($setting->name === 'defaultframework') {
                $found = $setting;
            }
        }
        $this->assertInstanceOf(\admin_setting_configtext::class, $found);
    }

    public function test_the_default_framework_match_ignores_case_but_not_accents(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $generator->create_framework(['shortname' => 'Défense', 'idnumber' => 'DEF-1']);
        $plain = $generator->create_framework(['shortname' => 'Defense', 'idnumber' => 'DEF-2']);

        set_config('defaultframework', 'defense', 'aiplacement_competency');

        $this->assertSame((int)$plain->get('id'), utils::default_framework_id());
    }

    public function test_the_setting_says_which_framework_a_value_picks_or_that_it_picks_none(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $name = 'DoD Cyber Workforce Framework (DCWF) v5.1';
        $generator->create_framework(['shortname' => $name, 'idnumber' => 'DCWF-5.1']);
        $setting = new \aiplacement_competency\admin\defaultframework_setting(
            'aiplacement_competency/defaultframework',
            'Default',
            'Desc',
            ''
        );

        $this->assertStringContainsString("Picks the framework {$name}", $setting->resolution('dcwf-5.1'));
        $this->assertStringContainsString('idnumber DCWF-5.1', $setting->resolution('DCWF-5.1'));
        // An exact match, not a prefix.
        $this->assertStringContainsString('Matches no visible framework', $setting->resolution('DCWF'));
        $this->assertStringContainsString('No default', $setting->resolution(''));
        $this->assertStringContainsString('Matches no visible framework', $setting->output_html('DCWF'));
    }
}
