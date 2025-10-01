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

/**
 * Web service function definitions for the AI Placement Classify Assist plugin.
 *
 * Declares external functions available via Moodle’s web services API.
 *
 * @package    aiplacement_classifyassist
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'aiplacement_classifyassist_classify_text' => [
        'classname'   => 'aiplacement_classifyassist\\external\\classify_text',
        'methodname'  => 'execute',
        'classpath'   => '',
        'description' => 'Classify arbitrary text using the configured AI provider.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'aiplacement/classifyassist:classify_text',
    ],
    'aiplacement_classifyassist_add_cm_competency' => [
        'classname'   => 'aiplacement_classifyassist\external\add_cm_competency',
        'methodname'  => 'execute',
        'description' => 'Add a competency to a course module.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'moodle/competency:coursecompetencyconfigure',
    ],
];
