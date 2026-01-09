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

Copyright 2026 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS. 
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO, 
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. 
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

namespace aiplacement_competency\output;

use core\hook\output\before_footer_html_generation;

/**
 * Decides when to show the “Classify” button and renders it.
 *
 * Provides the output logic for injecting the classify drawer
 * and action buttons into course editing pages.
 *
 * @package    aiplacement_competency
 * @category   output
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classify_ui {

    /**
     * Load and inject the classify UI into the footer.
     *
     * @param before_footer_html_generation $hook Hook object for footer HTML injection.
     * @return void
     */
    public static function load_classify_ui(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT, $USER;

        $params = $PAGE->url->params();
        $isnew = !empty($params['add']) && empty($params['update']);

        if ($isnew) {
            $msg = get_string('classify_note_newactivity', 'aiplacement_competency');
            $PAGE->requires->js_call_amd('aiplacement_competency/newactivity_notice', 'init', [$msg]);
            return;
        }

        if (!self::preflight_checks()) {
            return;
        }

        $html = $OUTPUT->render_from_template(
            'aiplacement_competency/drawer',
            ['userid' => $USER->id, 'contextid' => $PAGE->context->id]
        );
        $hook->add_html($html);

        $PAGE->requires->js_call_amd(
            'aiplacement_competency/classify_button',
            'init',
            [$PAGE->context->id]
        );

        $PAGE->requires->js_call_amd(
            'aiplacement_competency/applycmps',
            'init',
            [$PAGE->context->id]
        );
    }

    /**
     * Run environment and capability checks before showing the UI.
     *
     * @return bool True if UI may be displayed, false otherwise.
     */
    private static function preflight_checks(): bool {
        global $PAGE;

        if (during_initial_install() || !get_config('aiplacement_competency', 'version')) {
            return false;
        }

        // Placement plugin itself must be installed & enabled.
        $pm = \core_plugin_manager::instance();
        $info = $pm->get_plugin_info('aiplacement_competency');
        if (!$info || !$info->is_enabled()) {
            return false;
        }

        $actionclass = \core_ai\aiactions\generate_text::class;
        $aimanager = \core\di::get(\core_ai\manager::class);

        // Check if the action is enabled.
        if (!$aimanager->is_action_enabled('aiplacement_competency', $actionclass)) {
            return false;
        }

        // Is there at least one configured/enabled provider for this action?
        $providers = $aimanager->get_providers_for_actions([$actionclass], true);
        if (empty($providers[$actionclass])) {
            return false;
        }

        // Capability checks.
        if (!has_capability('moodle/course:update', $PAGE->context)) {
            return false;
        }
        if (!has_capability('aiplacement/competency:classify_text', $PAGE->context)) {
            return false;
        }

        // Only on course edit pages.
        $path = $PAGE->url->get_path();
        $iseditform = (
            preg_match('#/course/(mod)?edit\.php$#', $path)
            || preg_match('#/course/edit(section|settings)?\.php$#', $path)
        );

        return $iseditform;
    }

}
