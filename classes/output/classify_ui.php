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
