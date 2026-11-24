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

namespace aiplacement_competency\admin;

use aiplacement_competency\local\utils;

/**
 * The default framework setting, which says under the field which framework the value picks.
 *
 * A value that matches nothing selects nothing in the drawer, and an administrator who mistyped it would never know.
 * The value is not refused, since a site may set it before the framework is imported, so the field says what
 * it resolves to instead.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class defaultframework_setting extends \admin_setting_configtext {
    #[\Override]
    public function output_html($data, $query = '') {
        $html = parent::output_html($data, $query);

        return $html . \html_writer::div($this->resolution(trim((string)$data)), 'form-text', [
            'data-region' => 'defaultframework-resolution',
        ]);
    }

    /**
     * Says which framework a value picks, or that it picks none.
     *
     * @param string $value The value as entered.
     * @return string The sentence.
     */
    public function resolution(string $value): string {
        global $DB;

        if ($value === '') {
            return get_string('setting_defaultframework_empty', 'aiplacement_competency');
        }

        $id = utils::framework_id_for($value);
        if ($id === 0) {
            return \html_writer::span(
                get_string('setting_defaultframework_nomatch', 'aiplacement_competency'),
                'text-danger'
            );
        }

        $framework = $DB->get_record('competency_framework', ['id' => $id], 'id, idnumber, shortname', MUST_EXIST);

        return get_string('setting_defaultframework_match', 'aiplacement_competency', (object)[
            'shortname' => format_string($framework->shortname),
            'idnumber' => s($framework->idnumber),
        ]);
    }
}
