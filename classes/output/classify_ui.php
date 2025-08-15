<?php
namespace aiplacement_classifyassist\output;

use core\hook\output\before_footer_html_generation;
use html_writer;

/**
 * Decides *when* to show the “Classify” button and renders it.
 */
class classify_ui {

    public static function load_classify_ui(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT, $USER;

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
    }

    private static function preflight_checks(): bool {
        global $PAGE;

        if (during_initial_install() || !get_config('aiplacement_classifyassist', 'version')) {
            return false;
        }

        if (!has_capability('moodle/course:update', $PAGE->context)) {
            return false;
        }

        if (!has_capability('aiplacement/classifyassist:classify_text', $PAGE->context)) {
            return false;
        }

        $path = $PAGE->url->get_path();
        $iseditform = (
            preg_match('#/course/(mod)?edit\\.php$#', $path)
            || preg_match('#/course/edit(section|settings)?\\.php$#', $path)
        );

        return $iseditform;
    }
}
