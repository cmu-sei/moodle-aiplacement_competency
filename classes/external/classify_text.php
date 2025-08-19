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
        $params  = self::validate_parameters(self::execute_parameters(), [
            'contextid'  => $contextid,
            'prompttext' => $prompttext,
        ]);
        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        require_capability('aiplacement/classifyassist:classify_text', $context);

        $placement = new \aiplacement_classifyassist\placement();
        $rawjson   = $placement->classify($context, $prompttext);

        $outer = json_decode($rawjson, true);

        $frameworkid = (is_array($outer) && isset($outer['meta']['frameworkid']))
            ? (int)$outer['meta']['frameworkid'] : 0;

        $frameworkshortname = (is_array($outer) && isset($outer['meta']['frameworkshortname']))
            ? (string)$outer['meta']['frameworkshortname'] : '';

        if (!is_array($outer) || !isset($outer['response'])) {
            return ['frameworkid' => $frameworkid, 'frameworkshortname' => $frameworkshortname, 'tasks' => [], 'skills' => [], 'knowledge' => []];
        }

        $level1 = json_decode((string)$outer['response'], true);
        if (!is_array($level1) || !isset($level1['response'])) {
            return ['frameworkid' => $frameworkid, 'frameworkshortname' => $frameworkshortname, 'tasks' => [], 'skills' => [], 'knowledge' => []];
        }

        $inner = json_decode((string)$level1['response'], true);
        if (!is_array($inner)) {
            return ['frameworkid' => $frameworkid, 'frameworkshortname' => $frameworkshortname, 'tasks' => [], 'skills' => [], 'knowledge' => []];
        }

        $norm = function($raw) {
            $arr = is_array($raw) ? $raw : (is_string($raw) ? [$raw] : []);
            $arr = array_values(array_unique(array_filter(array_map(function($v) {
                if (!is_string($v)) return false;
                $t = trim($v);
                return $t !== '' ? $t : false;
            }, $arr))));
            return $arr;
        };

        $tasks     = $norm($inner['tasks'] ?? []);
        $skills    = $norm($inner['skills'] ?? []);
        $knowledge = $norm($inner['knowledge'] ?? []);

        return [
            'frameworkid' => $frameworkid,
            'frameworkshortname' => $frameworkshortname,
            'tasks'              => $tasks,
            'skills'             => $skills,
            'knowledge'          => $knowledge,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworkid' => new external_value(PARAM_INT, 'ID of the competency framework used to classify'),
            'frameworkshortname' => new external_value(PARAM_TEXT, 'Short name of the competency framework used to classify'),
            'tasks'       => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'NICE task code and name'),
                'List of related NICE tasks'
            ),
            'skills'     => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'NICE skill code and name'),
                'List of related NICE skills'
            ),
            'knowledge'     => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'NICE knowledge code and name'),
                'List of related NICE knowledge'
            ),
        ]);
    }
}