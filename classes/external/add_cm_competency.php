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

use core_external\external_api;
use core_external\external_function_parameters;
use aiplacement_competency\local\content\transcripts;
use core_external\external_value;

/**
 * External function to add a competency to a course module.
 *
 * This class exposes a web service function that links a competency
 * to a given course module via the core_competency API.
 *
 * @package    aiplacement_competency
 * @category   external
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_cm_competency extends external_api {
    /**
     * Describes the parameters for the external function.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'Course module id'),
            'competencyid' => new external_value(PARAM_INT, 'Competency id'),
        ]);
    }

    /**
     * Adds a competency to a course module.
     *
     * @param int $cmid Course module ID.
     * @param int $competencyid Competency ID.
     * @return bool True if competency was added, false if already linked.
     */
    public static function execute($cmid, $competencyid): bool {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'competencyid' => $competencyid,
        ]);

        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // Moodle refuses an activity competency the course does not already list, and a
        // caller that skipped the course step would stop partway. Adding is harmless
        // when the course has it. A user who may manage competencies on this activity but
        // not on the course cannot add it: the activity link below then succeeds only if
        // the course already had it, as it always did.
        try {
            \core_competency\api::add_competency_to_course($cm->course, $params['competencyid']);
        } catch (\required_capability_exception $e) {
            debugging('Could not add the competency to the course: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $added = (bool) \core_competency\api::add_competency_to_course_module(
            $params['cmid'],
            $params['competencyid']
        );

        // A lecture video and its transcript are one lesson: what the video teaches,
        // the transcript file next to it teaches too. The link the caller asked for is
        // already made, so a transcript that cannot be tagged is left, not reported as a failure.
        if ($cm->modname === 'label' && get_config('aiplacement_competency', 'pairtranscripts') !== '0') {
            foreach (transcripts::for_label($cm) as $pair) {
                try {
                    $managed = has_capability('moodle/competency:coursecompetencymanage', \context_module::instance($pair->id));
                    if ($pair->uservisible && $managed) {
                        \core_competency\api::add_competency_to_course_module($pair->id, $params['competencyid']);
                    }
                } catch (\moodle_exception $e) {
                    debugging('Could not tag the transcript: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        return $added;
    }

    /**
     * Describes the return value for the external function.
     *
     * @return external_value True if added, false if already linked.
     */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_BOOL, 'True if added, false if already linked');
    }
}
