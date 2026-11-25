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

use aiplacement_competency\local\job;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Runs the next step of a classification, one model request, and says what comes after.
 *
 * @package    aiplacement_competency
 * @category   external
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classify_step extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters The definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'jobid' => new external_value(PARAM_ALPHANUM, 'The job classify_start returned'),
        ]);
    }

    /**
     * Runs the next step.
     *
     * @param string $jobid The job.
     * @return array With 'finished', 'done', 'total', 'message' (what comes next) and, once finished, 'result'.
     * @throws \moodle_exception When the job is unknown, or the model failed or declined for every section.
     */
    public static function execute(string $jobid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['jobid' => $jobid]);

        // One step at a time for a job, so two requests that overlap cannot read the same state and both run the same
        // paid step. The second waits, then runs the step after it. The drawer sends them in turn, and this is only
        // a guard against a retry or a double click.
        $lock = \core\lock\lock_config::get_lock_factory('aiplacement_competency')
            ->get_lock('job_' . $params['jobid'], 120);
        if (!$lock) {
            throw new \moodle_exception('classifyjobgone', 'aiplacement_competency');
        }
        try {
            return self::run($params['jobid']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs the next step of a job whose lock is held.
     *
     * @param string $jobid The job.
     * @return array The response of execute().
     */
    private static function run(string $jobid): array {
        $cache = \cache::make('aiplacement_competency', 'jobs');

        $state = $cache->get($jobid);
        if (!is_array($state)) {
            throw new \moodle_exception('classifyjobgone', 'aiplacement_competency');
        }
        $job = job::from_state($state);

        // The job is the user's own. Another user holding its id gets nothing from it.
        global $USER;
        if ($job->userid() !== (int)$USER->id) {
            throw new \moodle_exception('classifyjobgone', 'aiplacement_competency');
        }
        $context = \context::instance_by_id($job->contextid());
        self::validate_context($context);
        require_capability('aiplacement/competency:classify_text', $context);

        try {
            $job->step();
        } catch (\Throwable $e) {
            // A job that failed has nothing to wait for, and nothing in it is worth keeping an hour.
            $cache->delete($jobid);
            throw $e;
        }

        if (!$job->finished()) {
            $cache->set($jobid, $job->state());

            return [
                'finished' => false,
                'done' => $job->done_steps(),
                'total' => $job->total_steps(),
                'message' => $job->next_message(),
            ];
        }

        $cache->delete($jobid);
        $state = $job->state();
        $result = classify_text::build_return(
            (int)$state['frameworkid'],
            $state['levels'],
            $job->result()
        );

        return [
            'finished' => true,
            'done' => $job->done_steps(),
            'total' => $job->total_steps(),
            'message' => '',
            'result' => $result,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure The definition.
     */
    public static function execute_returns(): external_single_structure {
        // The result is there only once the last step has run.
        $result = classify_text::execute_returns();
        $result->required = VALUE_OPTIONAL;

        return new external_single_structure([
            'finished' => new external_value(PARAM_BOOL, 'Whether this was the last step'),
            'done' => new external_value(PARAM_INT, 'Steps run so far'),
            'total' => new external_value(PARAM_INT, 'How many steps there are'),
            'message' => new external_value(PARAM_TEXT, 'What the next step does'),
            'result' => $result,
        ], 'The outcome of the step', VALUE_REQUIRED);
    }
}
