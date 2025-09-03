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
            'selectedframeworkid' => new external_value(PARAM_INT,  'Framework id', VALUE_DEFAULT, 0),
            'selectedframeworkshortname' => new external_value(PARAM_TEXT, 'Framework shortname', VALUE_DEFAULT, ''),
            'domains' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Selected domain name'),
                'Selected domain names',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute(
        int $contextid,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $domains = []
    ): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'selectedframeworkid' => $selectedframeworkid,
            'selectedframeworkshortname' => $selectedframeworkshortname,
            'domains' => $domains,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('aiplacement/classifyassist:classify_text', $context);

        $selecteddomains = array_values(array_unique(array_filter(array_map(
            static function($s) {
                $s = (string)$s;
                $s = trim($s);
                $s = preg_replace('/\s+/u', ' ', $s);
                return $s;
            },
            $params['domains'] ?? []
        ))));

        $placement = new \aiplacement_classifyassist\placement();
        $rawjson = $placement->classify(
            $context,
            $params['prompttext'],
            (int)$params['selectedframeworkid'],
            (string)$params['selectedframeworkshortname'],
            $selecteddomains
        );

        $outer = json_decode($rawjson, true);
        $inner = [];

        $decode_json_maybe = static function(string $s): ?array {
            $s = trim($s);
            if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $s, $m)) {
                $s = $m[1];
            }
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            $p1 = strpos($s, '{'); $p2 = strrpos($s, '}');
            if ($p1 !== false && $p2 !== false && $p2 > $p1) {
                $slice = substr($s, $p1, $p2 - $p1 + 1);
                $decoded = json_decode($slice, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            return null;
        };

        if (is_array($outer) && array_key_exists('generatedcontent', $outer)) {
            $inner = is_string($outer['generatedcontent'])
                ? ($decode_json_maybe($outer['generatedcontent']) ?? [])
                : (is_array($outer['generatedcontent']) ? $outer['generatedcontent'] : []);
        } else {
            $inner = is_array($outer) ? $outer : ($decode_json_maybe((string)$rawjson) ?? []);
        }

        $fwshort = (string)($inner['framework']['shortname'] ?? $params['selectedframeworkshortname']);

        $useddomains = [];
        if (!empty($inner['domains']) && is_array($inner['domains'])) {
            $useddomains = array_values(array_unique(array_filter(array_map(
                static function($s) {
                    $s = trim((string)$s);
                    return $s === '' ? null : preg_replace('/\s+/u', ' ', $s);
                },
                $inner['domains']
            ))));
        }
        if (!$useddomains) {
            $useddomains = $selecteddomains;
        }

        $competencies = [];
        if (!empty($inner['competencies']) && is_array($inner['competencies'])) {
            $competencies = array_values(array_unique(array_filter(array_map(
                static function($s) {
                    $s = trim((string)$s);
                    return $s === '' ? null : $s;
                },
                $inner['competencies']
            ))));
        }

        return [
            'frameworkid'        => (int)$params['selectedframeworkid'],
            'frameworkshortname' => $fwshort,
            'useddomains'        => $useddomains,
            'competencies'       => $competencies,
        ];

    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworkid'        => new external_value(PARAM_INT,  'ID of the competency framework used to classify'),
            'frameworkshortname' => new external_value(PARAM_TEXT, 'Short name of the competency framework used to classify'),
            'useddomains'        => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Domain name used'),
                'Domains actually used for classification',
                VALUE_DEFAULT,
                []
            ),
            'competencies' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Competency "CODE - Name"'),
                'Classified competencies',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
