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

namespace aiplacement_classifyassist;

use core\di;
use context;
use aiplacement_classifyassist\local\utils;

/**
 * Placement class for AI-based text classification.
 *
 * Provides integration with Moodle’s AI manager to classify text
 * against competency frameworks, using AI-generated responses.
 *
 * @package    aiplacement_classifyassist
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placement extends \core_ai\placement {
    /**
     * Get the list of supported AI actions for this placement.
     *
     * @return array List of action classnames.
     */
    public static function get_action_list(): array {
        return [\core_ai\aiactions\generate_text::class];
    }

    /**
     * Run text classification via AI service.
     *
     * @param context $context Moodle context object.
     * @param string $prompttext The text to classify.
     * @param int $selectedframeworkid Competency framework ID.
     * @param string $selectedframeworkshortname Competency framework shortname.
     * @param array $levels Selected levels.
     * @return string JSON-encoded classification result.
     * @throws \moodle_exception If AI classification fails.
     */
    public function classify(
        context $context,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = []
    ): string {
        global $USER;

        // Builds system instruction with course description.
        $instruction = utils::build_instruction($selectedframeworkid, $selectedframeworkshortname, $levels);
        $finalprompt = $instruction . "\n\nTEXT TO CLASSIFY:\n" . $prompttext;

        $action = new \core_ai\aiactions\generate_text(
            $context->id,
            (int)$USER->id,
            $finalprompt
        );

        if (method_exists($action, 'set_meta')) {
            $action->set_meta([
                'frameworkid'        => $selectedframeworkid,
                'frameworkshortname' => $selectedframeworkshortname,
                'levels'            => $levels,
            ]);
        }

        $manager  = di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        $success = method_exists($response, 'get_success')
            ? (bool)$response->get_success()
            : true;

        if (!$success) {
            $msg = method_exists($response, 'get_errormessage')
                ? (string)($response->get_errormessage() ?? '')
                : 'Unknown AI error';
            throw new \moodle_exception('aiclassifyfailed', 'aiplacement_classifyassist', '', null, $msg);
        }

        $rawdata = $response->get_response_data();
        return json_encode($rawdata, JSON_UNESCAPED_UNICODE);
    }
}
