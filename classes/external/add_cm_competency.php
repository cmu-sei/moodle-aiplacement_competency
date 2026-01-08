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

namespace aiplacement_competency\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;


/**
 * External function to add a competency to a course module.
 *
 * This class exposes a web service function that links a competency
 * to a given course module via the core_competency API.
 *
 * @package    aiplacement_competency
 * @category   external
 * @copyright  2025 Nuria Pacheco
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
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'competencyid' => $competencyid,
        ]);

        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        return (bool) \core_competency\api::add_competency_to_course_module(
            $params['cmid'],
            $params['competencyid']
        );
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
