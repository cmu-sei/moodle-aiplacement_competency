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

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY
DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/

declare(strict_types=1);

namespace aiplacement_competency\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use aiplacement_competency\local\utils;

/**
 * External function to classify text against a competency framework.
 *
 * This service allows text content to be classified into competencies
 * and levels using the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   external
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classify_text extends external_api {

    /**
     * Describes the parameters accepted by the classify_text function.
     *
     * @return external_function_parameters Parameter definitions.
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
        ]);
    }

    /**
     * Executes the classification of text into competencies and levels.
     *
     * @param int $contextid The context ID.
     * @param string $prompttext The visible text to classify.
     * @param int $selectedframeworkid Framework ID (optional).
     * @param string $selectedframeworkshortname Framework shortname (optional).
     * @param array $levels Selected level names (optional).
     * @return array The classification result containing framework info,
     *               levels used, and matched competencies.
     */
    public static function execute(
        int $contextid,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = []
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'selectedframeworkid' => $selectedframeworkid,
            'selectedframeworkshortname' => $selectedframeworkshortname,
            'levels' => $levels,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('aiplacement/competency:classify_text', $context);

        // If this is a course module context, check for activity-specific content.
        if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);

            // Handle quiz questions.
            if ($cm->modname === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
                if ($quiz) {
                    $questionscontent = self::get_quiz_questions_content((int)$quiz->id);
                    if (!empty($questionscontent)) {
                        $params['prompttext'] .= "\n\n" . $questionscontent;
                    }
                }
            }

            // Handle TopoMojo content.
            if ($cm->modname === 'topomojo') {
                $topomojo = $DB->get_record('topomojo', ['id' => $cm->instance], 'content');
                if ($topomojo && !empty($topomojo->content)) {
                    $content = trim(strip_tags($topomojo->content));
                    if ($content !== '') {
                        $params['prompttext'] .= "\n\n" . $content;
                    }
                }
            }
        }

        $selectedlevels = array_values(array_unique(array_filter(array_map(
            static function($s) {
                $s = (string)$s;
                $s = trim($s);
                $s = preg_replace('/\s+/u', ' ', $s);
                return $s;
            },
            $params['levels'] ?? []
        ))));

        $placement = new \aiplacement_competency\placement();
        $rawjson = $placement->classify(
            $context,
            $params['prompttext'],
            (int)$params['selectedframeworkid'],
            (string)$params['selectedframeworkshortname'],
            $selectedlevels
        );

        $outer = json_decode($rawjson, true);
        $inner = [];

        $decodejsonmaybe = static function(string $s): ?array {
            $s = trim($s);
            if (preg_match('/^\x60{3}[a-zA-Z]*\s*(.*?)\s*\x60{3}$/s', $s, $m)) {
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
                ? ($decodejsonmaybe($outer['generatedcontent']) ?? [])
                : (is_array($outer['generatedcontent']) ? $outer['generatedcontent'] : []);
        } else {
            $inner = is_array($outer) ? $outer : ($decodejsonmaybe((string)$rawjson) ?? []);
        }

        $fwshort = (string)($inner['framework']['shortname'] ?? $params['selectedframeworkshortname']);

        // Always use the levels that were explicitly selected by the user,
        // rather than relying on the AI response which may be incomplete.
        $usedlevels = $selectedlevels;

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
            'usedlevels'         => $usedlevels,
            'competencies'       => $competencies,
            // Commented out to hide debug information from users.
            // 'prompttext'         => $params['prompttext'],
            // 'promptlength'       => strlen($params['prompttext']),
        ];
    }

    /**
     * Get quiz questions content for AI classification.
     *
     * @param int $quizid The quiz ID.
     * @return string The concatenated question text.
     */
    protected static function get_quiz_questions_content(int $quizid): string {
        global $DB;

        $content = '';

        // Get quiz slots and questions.
        $sql = "SELECT qs.slot, q.questiontext, q.name
                FROM {quiz_slots} qs
                JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot'
                JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                JOIN {question} q ON q.id = qv.questionid
                WHERE qs.quizid = :quizid
                ORDER BY qs.slot";

        $questions = $DB->get_records_sql($sql, ['quizid' => $quizid]);

        foreach ($questions as $question) {
            $questiontext = strip_tags($question->questiontext ?? '');
            $questionname = $question->name ?? '';

            if (!empty($questiontext)) {
                $content .= "Question {$question->slot}";
                if (!empty($questionname)) {
                    $content .= " ({$questionname})";
                }
                $content .= ": " . trim($questiontext) . "\n\n";
            }
        }

        return trim($content);
    }

    /**
     * Describes the return structure of the classify_text function.
     *
     * @return external_single_structure Structure of the returned data.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworkid' => new external_value(
                PARAM_INT,
                'ID of the competency framework used to classify'
            ),
            'frameworkshortname' => new external_value(
                PARAM_TEXT,
                'Short name of the competency framework used to classify'
            ),
            'usedlevels' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Level name used'),
                'Levels actually used for classification',
                VALUE_DEFAULT,
                []
            ),
            'competencies' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Competency "CODE - Name"'),
                'Classified competencies',
                VALUE_DEFAULT,
                []
            ),
            // Commented out to hide debug information from users.
            // 'prompttext' => new external_value(
            //     PARAM_RAW,
            //     'The full prompt text sent to AI',
            //     VALUE_DEFAULT,
            //     ''
            // ),
            // 'promptlength' => new external_value(
            //     PARAM_INT,
            //     'Length of prompt text in characters',
            //     VALUE_DEFAULT,
            //     0
            // ),
        ]);
    }
}
