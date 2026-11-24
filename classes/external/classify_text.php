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
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use aiplacement_competency\local\content\filetext;
use aiplacement_competency\local\content\resolver;
use aiplacement_competency\local\classifier;

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
            'verify' => new external_value(PARAM_BOOL, 'Run the verification pass', VALUE_DEFAULT, false),
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
     * @param bool $verify Whether to run the verification pass (optional).
     * @return array The classification result containing framework info,
     *               levels used, and matched competencies.
     */
    public static function execute(
        int $contextid,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = [],
        bool $verify = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'selectedframeworkid' => $selectedframeworkid,
            'selectedframeworkshortname' => $selectedframeworkshortname,
            'levels' => $levels,
            'verify' => $verify,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);
        require_capability('aiplacement/competency:classify_text', $context);

        filetext::take_omitted();

        // In an activity context, add whatever content that kind of activity holds.
        if ($context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
            $params['prompttext'] = resolver::augment_prompt($params['prompttext'], $cm, $context);
        }

        $selectedlevels = array_values(array_unique(array_filter(array_map(
            static function ($s) {
                $s = (string)$s;
                $s = trim($s);
                $s = preg_replace('/\s+/u', ' ', $s);
                return $s;
            },
            $params['levels'] ?? []
        ))));

        $title = '';
        if (isset($cm)) {
            $title = trim(format_string($cm->name, true, ['context' => $context, 'escape' => false]));
        }

        $result = classifier::classify(
            $context,
            $params['prompttext'],
            (int)$params['selectedframeworkid'],
            (string)$params['selectedframeworkshortname'],
            $selectedlevels,
            $title,
            (bool)$params['verify'] && get_config('aiplacement_competency', 'verify') !== '0'
        );

        $omitted = filetext::take_omitted();
        if (!empty($omitted)) {
            $result['warnings'][] = get_string('filesomitted', 'aiplacement_competency', implode(', ', $omitted));
        }

        return [
            'frameworkid'        => (int)$params['selectedframeworkid'],
            'frameworkshortname' => $result['frameworkshortname'],
            'usedlevels'         => $selectedlevels,
            'competencies'       => array_column($result['competencies'], 'label'),
            'suggestions'        => array_map(static function ($c) {
                return ['idnumber' => $c['idnumber'], 'label' => $c['label'], 'reason' => $c['reason']];
            }, $result['competencies']),
            'warnings'           => $result['warnings'],
        ];
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
            'suggestions' => new external_multiple_structure(
                new external_single_structure([
                    'idnumber' => new external_value(PARAM_RAW, 'Competency idnumber'),
                    'label' => new external_value(PARAM_TEXT, 'Competency "CODE - Name"'),
                    'reason' => new external_value(PARAM_TEXT, 'Why the content supports it', VALUE_DEFAULT, ''),
                ]),
                'Classified competencies with the reason for each',
                VALUE_DEFAULT,
                []
            ),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Something to check before applying'),
                'Warnings about the content or the result',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
