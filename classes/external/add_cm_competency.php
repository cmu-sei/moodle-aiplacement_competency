<?php
namespace aiplacement_classifyassist\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

class add_cm_competency extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'         => new external_value(PARAM_INT, 'Course module id'),
            'competencyid' => new external_value(PARAM_INT, 'Competency id'),
        ]);
    }

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

    public static function execute_returns(): external_value {
        return new external_value(PARAM_BOOL, 'True if added, false if already linked');
    }
}
