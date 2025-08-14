<?php
namespace aiplacement_classifyassist;

use core\hook\output\before_footer_html_generation;

/**
 * Register output injected via Moodle’s hook API.
 */
class hook_callbacks {
    /**
     * Inject the button only when the UI tells us it is appropriate.
     */
    public static function before_footer_html_generation(
        before_footer_html_generation $hook
    ): void {
        \aiplacement_classifyassist\output\classify_ui::load_classify_ui($hook);
    }
}
