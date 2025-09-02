<?php
declare(strict_types=1);
namespace aiplacement_classifyassist\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use aiplacement_classifyassist\local\utils;

class classify_text extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT,  'Context ID'),
            'prompttext' => new external_value(PARAM_RAW, 'Visible page text'),
            'selectedframeworkid' => new external_value(PARAM_INT,  'Framework id',        VALUE_DEFAULT, 0),
            'selectedframeworkshortname' => new external_value(PARAM_TEXT, 'Framework shortname', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $contextid,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = ''
    ): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'selectedframeworkid' => $selectedframeworkid,
            'selectedframeworkshortname' => $selectedframeworkshortname,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('aiplacement/classifyassist:classify_text', $context);

        $placement = new \aiplacement_classifyassist\placement();
        $rawjson = $placement->classify(
            $context,
            $params['prompttext'],
            (int)$params['selectedframeworkid'],
            (string)$params['selectedframeworkshortname']
        );

        // Parse information received from ai
        $raw = json_decode($rawjson, true) ?: [];
        $parsed = utils::extract_classification($raw);

        return [
            'frameworkid'        => (int)$params['selectedframeworkid'],
            'frameworkshortname' => (string)$params['selectedframeworkshortname'],
            'tasks'              => $parsed['tasks'],
            'skills'             => $parsed['skills'],
            'knowledge'          => $parsed['knowledge'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworkid'        => new external_value(PARAM_INT,  'ID of the competency framework used to classify'),
            'frameworkshortname' => new external_value(PARAM_TEXT, 'Short name of the competency framework used to classify'),
            'tasks'              => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Task code and name'),
                'List of related tasks'
            ),
            'skills'             => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Skill code and name'),
                'List of related skills'
            ),
            'knowledge'          => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Knowledge code and name'),
                'List of related knowledge'
            ),
        ]);
    }
}
