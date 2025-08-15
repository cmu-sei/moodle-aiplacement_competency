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
        $context = context::instance_by_id($params['contextid']);
        self::validate_context($context);

        require_capability('aiplacement/classifyassist:classify_text', $context);

        $placement = new \aiplacement_classifyassist\placement();
        $rawjson   = $placement->classify($context, $prompttext);

        $outer = json_decode($rawjson, true);
        if (!is_array($outer) || !array_key_exists('response', $outer)) {
            return ['work_role' => '', 'tasks' => [], 'skills' => [], 'knowledge' => []];
        }

        $inner = json_decode($outer['response'], true);
        if (!is_array($inner)) {
            return ['work_role' => '', 'tasks' => [], 'skills' => [], 'knowledge' => []];
        }

        $work_role = isset($inner['work_role']) && is_string($inner['work_role']) ? $inner['work_role'] : '';

        $tasksRaw  = $inner['tasks'] ?? [];
        if (is_null($tasksRaw)) {
            $tasks = [];
        } elseif (is_string($tasksRaw)) {
            $tasks = [$tasksRaw];
        } elseif (is_array($tasksRaw)) {
            $tasks = $tasksRaw;
        } else {
            $tasks = [];
        }

        $tasks = array_values(array_unique(array_filter(array_map(function($v) {
            if (!is_string($v)) return false;
            $t = trim($v);
            return $t !== '' ? $t : false;
        }, $tasks))));

        $skillsRaw  = $inner['skills'] ?? [];
        if (is_null($skillsRaw)) {
            $skills = [];
        } elseif (is_string($skillsRaw)) {
            $skills = [$skillsRaw];
        } elseif (is_array($skillsRaw)) {
            $skills = $skillsRaw;
        } else {
            $skills = [];
        }

        $skills = array_values(array_unique(array_filter(array_map(function($v) {
            if (!is_string($v)) return false;
            $t = trim($v);
            return $t !== '' ? $t : false;
        }, $skills))));

        $knowledgeRaw  = $inner['knowledge'] ?? [];
        if (is_null($knowledgeRaw)) {
            $knowledge = [];
        } elseif (is_string($knowledgeRaw)) {
            $knowledge = [$knowledgeRaw];
        } elseif (is_array($knowledgeRaw)) {
            $knowledge = $knowledgeRaw;
        } else {
            $knowledge = [];
        }

        $knowledge = array_values(array_unique(array_filter(array_map(function($v) {
            if (!is_string($v)) return false;
            $t = trim($v);
            return $t !== '' ? $t : false;
        }, $knowledge))));

        return [
            'tasks'       => $tasks,
            'skills'     => $skills,
            'knowledge'  => $knowledge,
        ];
    }


    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
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