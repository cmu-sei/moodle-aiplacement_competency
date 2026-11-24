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

use core\di;
use context;
use aiplacement_competency\local\utils;

/**
 * Placement class for AI-based text classification.
 *
 * Provides integration with Moodle’s AI manager to classify text
 * against competency frameworks, using AI-generated responses.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
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
     * Classifies text against a competency framework.
     *
     * @param context $context The context the request is made in.
     * @param string $prompttext The content to classify.
     * @param int $selectedframeworkid The framework id.
     * @param string $selectedframeworkshortname The framework shortname.
     * @param array $levels The selected level names.
     * @return string The provider's response data, as JSON.
     * @throws \moodle_exception When the provider fails or declines.
     */
    public function classify(
        context $context,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = []
    ): string {
        // Builds system instruction with course description.
        $instruction = utils::build_instruction($selectedframeworkid, $selectedframeworkshortname, $levels, $prompttext);

        return $this->send(
            $context,
            $instruction . "\n\nTEXT TO CLASSIFY:\n" . $prompttext,
            [
                'frameworkid'        => $selectedframeworkid,
                'frameworkshortname' => $selectedframeworkshortname,
                'levels'            => $levels,
            ]
        );
    }

    /**
     * Sends a finished prompt to the AI subsystem.
     *
     * @param context $context The context the request is made in.
     * @param string $prompt The whole prompt.
     * @param array $meta Extra information to record against the action.
     * @return string The provider's response data, as JSON.
     * @throws \moodle_exception When the provider fails or declines.
     */
    public function send(context $context, string $prompt, array $meta = []): string {
        global $USER;

        $action = new \core_ai\aiactions\generate_text(
            $context->id,
            (int)$USER->id,
            $prompt
        );

        if (method_exists($action, 'set_meta')) {
            $action->set_meta($meta);
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
            if (self::looks_like_refusal($msg)) {
                throw new \moodle_exception('aiclassifyrefused', 'aiplacement_competency', '', null, $msg);
            }
            throw new \moodle_exception('aiclassifyfailed', 'aiplacement_competency', '', null, $msg);
        }

        $rawdata = $response->get_response_data();
        return json_encode($rawdata, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Whether text from a provider reads as the model declining on safety grounds.
     *
     * Bedrock reports its cyber-content safeguards as an error or a stop reason,
     * and a model may also answer in prose that it will not help. Either way the
     * person needs to be told so rather than shown a generic failure.
     *
     * @param string $text An error message, stop reason or the start of a reply.
     * @return bool True when it reads as a refusal.
     */
    public static function looks_like_refusal(string $text): bool {
        return (bool)preg_match(
            '/refusal|\brefus(ed|es|ing)\s+to\s+(answer|help|assist|comply|respond|generate|classify)\b|' .
            'safeguard|safety (filter|system|polic)|content[ _-]?(filter|polic)|guardrail|' .
            'stop_reason.{0,6}(refusal|content)|' .
            '\bI\s+(can\'?t|cannot|won\'?t|am unable to|\'m unable to)\s+(help|assist|provide|comply|classify)/iu',
            $text
        );
    }
}
