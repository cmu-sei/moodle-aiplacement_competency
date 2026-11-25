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

namespace aiplacement_competency\external;

use aiplacement_competency\local\content\filetext;
use aiplacement_competency\local\job;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Starts a classification that the browser then runs a step at a time.
 *
 * Gathers the content and works out the steps, and keeps the job for classify_step to continue. Nothing is sent to the
 * model yet, so this returns at once.
 *
 * @package    aiplacement_competency
 * @category   external
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classify_start extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters The definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context ID'),
            'prompttext' => new external_value(PARAM_RAW, 'Visible page text'),
            'selectedframeworkid' => new external_value(PARAM_INT, 'Framework id', VALUE_DEFAULT, 0),
            'selectedframeworkshortname' => new external_value(PARAM_TEXT, 'Framework shortname', VALUE_DEFAULT, ''),
            'levels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Selected level name'),
                'Selected level names',
                VALUE_DEFAULT,
                []
            ),
            'verify' => new external_value(PARAM_BOOL, 'Run the verification pass', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Starts the job.
     *
     * @param int $contextid The context ID.
     * @param string $prompttext The visible text to classify.
     * @param int $selectedframeworkid Framework ID.
     * @param string $selectedframeworkshortname Framework shortname.
     * @param array $levels Selected level names.
     * @param bool $verify Whether to run the verification pass.
     * @return array With the job id, how many steps there are, and what the first one does.
     */
    public static function execute(
        int $contextid,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = [],
        bool $verify = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'selectedframeworkid' => $selectedframeworkid,
            'selectedframeworkshortname' => $selectedframeworkshortname,
            'levels' => $levels,
            'verify' => $verify,
        ]);

        [$context, $text, $selectedlevels, $title] = classify_text::prepare_request(
            $params['contextid'],
            $params['prompttext'],
            $params['levels'] ?? []
        );

        $job = job::start(
            $context->id,
            $text,
            (int)$params['selectedframeworkid'],
            (string)$params['selectedframeworkshortname'],
            $selectedlevels,
            $title,
            (bool)$params['verify'] && get_config('aiplacement_competency', 'verify') !== '0'
        );

        $omitted = filetext::take_omitted();
        if (!empty($omitted)) {
            $job->add_warning(get_string('filesomitted', 'aiplacement_competency', implode(', ', $omitted)));
        }

        $jobid = bin2hex(random_bytes(16));
        \cache::make('aiplacement_competency', 'jobs')->set($jobid, $job->state());

        return [
            'jobid' => $jobid,
            'total' => $job->total_steps(),
            'message' => $job->next_message(),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure The definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'jobid' => new external_value(PARAM_ALPHANUM, 'Identifies the job in the steps that follow'),
            'total' => new external_value(PARAM_INT, 'How many steps there are'),
            'message' => new external_value(PARAM_TEXT, 'What the first step does'),
        ]);
    }
}
