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

namespace aiplacement_classifyassist;

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks for the AI Placement Classify Assist plugin.
 *
 * Registers output injected via Moodle’s hook API.
 *
 * @package    aiplacement_classifyassist
 * @category   hook
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Inject the button only when the UI tells us it is appropriate.
     *
     * @param before_footer_html_generation $hook Hook object for footer HTML injection.
     * @return void
     */
    public static function before_footer_html_generation(
        before_footer_html_generation $hook
    ): void {
        \aiplacement_classifyassist\output\classify_ui::load_classify_ui($hook);
    }
}
