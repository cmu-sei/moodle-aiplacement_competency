<?php
namespace aiplacement_classifyassist\output;

use core\hook\output\before_footer_html_generation;

/**
 * Decides *when* to show the “Classify” button and renders it.
 */
class classify_ui {

    public static function load_classify_ui(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT, $USER;

        $params = $PAGE->url->params();
        $isnew = !empty($params['add']) && empty($params['update']);

        if ($isnew) {
            $msg = get_string('classify_note_newactivity', 'aiplacement_classifyassist');
            $PAGE->requires->js_call_amd('aiplacement_classifyassist/newactivity_notice', 'init', [$msg]);
            return;
        }

        if (!self::preflight_checks()) {
            return;
        }

        $html = $OUTPUT->render_from_template(
            'aiplacement_classifyassist/drawer',
            ['userid' => $USER->id, 'contextid' => $PAGE->context->id]
        );
        $hook->add_html($html);

        $PAGE->requires->js_call_amd(
            'aiplacement_classifyassist/classify_button',
            'init',
            [$PAGE->context->id]
        );

        $PAGE->requires->js_call_amd(
            'aiplacement_classifyassist/applycmps',
            'init',
            [$PAGE->context->id]
        );
    }

    private static function preflight_checks(): bool {
        global $PAGE;

        if (during_initial_install() || !get_config('aiplacement_classifyassist', 'version')) {
            return false;
        }

        // Placement plugin itself must be installed & enabled.
        $pm = \core_plugin_manager::instance();
        $info = $pm->get_plugin_info('aiplacement_classifyassist');
        if (!$info || !$info->is_enabled()) {
            return false;
        }

        $actionclass = \core_ai\aiactions\generate_text::class;
        $aimanager = \core\di::get(\core_ai\manager::class);

        // // Check if the action is enabled
        if (!$aimanager->is_action_enabled('aiplacement_classifyassist', $actionclass)) {
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
        if (!has_capability('aiplacement/classifyassist:classify_text', $PAGE->context)) {
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
