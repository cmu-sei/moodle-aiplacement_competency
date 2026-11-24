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

/**
 * Settings for the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'aiplacement_competency/maxtags',
        get_string('setting_maxtags', 'aiplacement_competency'),
        get_string('setting_maxtags_desc', 'aiplacement_competency'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'aiplacement_competency/maxcandidates',
        get_string('setting_maxcandidates', 'aiplacement_competency'),
        get_string('setting_maxcandidates_desc', 'aiplacement_competency'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'aiplacement_competency/verify',
        get_string('setting_verify', 'aiplacement_competency'),
        get_string('setting_verify_desc', 'aiplacement_competency'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'aiplacement_competency/pairtranscripts',
        get_string('setting_pairtranscripts', 'aiplacement_competency'),
        get_string('setting_pairtranscripts_desc', 'aiplacement_competency'),
        1
    ));

    $settings->add(new admin_setting_configexecutable(
        'aiplacement_competency/pdftotext',
        get_string('setting_pdftotext', 'aiplacement_competency'),
        get_string('setting_pdftotext_desc', 'aiplacement_competency'),
        ''
    ));
}
