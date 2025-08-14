<?php
declare(strict_types=1);
namespace aiplacement_classifyassist\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;

use context;

class classify_text extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context ID'),
            'prompttext' => new external_value(PARAM_RAW, 'Visible page text'),
        ]);
    }

    public static function execute(int $contextid, string $prompttext): array {
        $params  = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid, 'prompttext' => $prompttext,]);
        $context = context::instance_by_id($params['contextid']);
        self::validate_context($context);

        require_capability('aiplacement/classifyassist:use', $context);

        $placement = new \aiplacement_classifyassist\placement();
        $rawjson = $placement->classify($context, $prompttext);

        $outer = json_decode($rawjson, true);
        if (!is_array($outer) || !isset($outer['response'])) {
            return ['role' => '', 'work_role' => []];
        }

        $inner = json_decode($outer['response'], true);
        if (!is_array($inner)) {
            return ['role' => '', 'work_role' => []];
        }

        return [
            'role'      => $inner['role']      ?? '',
            'work_role' => $inner['work_role'] ?? [],
        ];

    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'role'      => new external_value(PARAM_TEXT, 'Role'),
            'work_role' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Work role'),
                'List of matching NICE work roles'
            ),
        ]);
    }

}