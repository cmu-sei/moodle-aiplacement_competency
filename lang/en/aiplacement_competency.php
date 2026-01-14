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

/**
 * Language strings for the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   string
 * @copyright  2025 Nuria Pacheco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['action_classify_text_instruction'] = '
You will receive:
- A course/activity description (free text).
- A competency framework shortname: "{$a->frameworkshortname}".
- A list of selected levels (top level within that framework): {$a->levels}

Task
Classify the description using ONLY competencies that belong to "{$a->frameworkshortname}" and fall within the listed levels. If you are not certain a competency exists in the specified framework/level, DO NOT include it.

Output format
Return JSON only, with these keys:
{
  "framework": { "shortname": "{$a->frameworkshortname}" },
  "levels": {$a->levels},
  "competencies": [
    "CODE - Name"  // If the framework has canonical codes (e.g., NICE K/S/T/A codes). Otherwise just "Name".
  ]
}

Rules
1) Do not invent codes or names. If unsure, leave "competencies": [].
2) Do not add extra keys, explanations, or markdown — JSON only.

Examples (illustrative; not exhaustive)

Example A — single level
{
  "framework": { "shortname": "NICE-1.0.0" },
  "levels": ["Oversight and Governance"],
  "competencies": [
    "T1119 - Recommend vulnerability remediation strategies",
    "S0415 - Skill in evaluating regulations",
    "K0676 - Knowledge of cybersecurity laws and regulations"
  ]
}

{
  "framework": { "shortname": "MITRE D3FEND" },
  "levels": ["Harden", "Detect"],
  "competencies": [
    "Credential Hardening",
    "Network Segmentation",
    "Application Isolation and Sandboxing",
    "Email Content Filtering",
    "File Analysis"
  ]
}

{
  "framework": { "shortname": "MITRE ATT&CK (Enterprise)" },
  "levels": ["Initial Access", "Execution"],
  "competencies": [
    "T1566 - Phishing",
    "T1059 - Command and Scripting Interpreter",
    "T1204 - User Execution"
  ]
}

Example B — multiple levels
{
  "framework": { "shortname": "NICE-1.0.0" },
  "levels": ["Protect and Defend", "Investigate"],
  "competencies": [
    "T1241 - Document cybersecurity incidents",
    "S0385 - Skill in communicating complex concepts",
    "K0674 - Knowledge of computer networking protocols"
  ]
}

Example C — no clear matches
{
  "framework": { "shortname": "NICE-1.0.0" },
  "levels": ["Analyze"],
  "competencies": []
}
';
$string['aiclassification_help'] = 'Uses AI to classify course context according to the NICE Framework Competencies.';
$string['aiclassificationlabel'] = 'AI Classification';
$string['applycmps'] = 'Apply CMPs';
$string['applycmps_clearall'] = 'Clear all';
$string['applycmps_clicktotoggle'] = 'Click the checkboxes to toggle competency selection.';
$string['applycmps_intro_checklist'] = 'Select the competencies you want to apply. You can uncheck any you don’t need.';
$string['applycmps_selectall'] = 'Select all';
$string['applycmps_title'] = 'Apply classification';
$string['applycompetencies'] = 'Apply';
$string['applynow'] = 'Apply';
$string['classify_note_newactivity'] = 'Once this activity has been saved, the Competency Classification tool will be enabled.';
$string['classify_tooltips'] = 'Classify content based on competency framework.';
$string['competency:classify_text'] = 'Classify Text';
$string['classifybutton'] = 'Classify Text';
$string['classifyheading'] = 'AI Classification Result';
$string['competencies'] = 'Competencies';
$string['copy'] = 'Copy';
$string['frameworkselection'] = 'Competency Framework Selection';
$string['frameworkselection_help'] = 'Choose the competency framework that the AI model should use to classify your content.';
$string['frameworkselection_label'] = 'Available competency frameworks:';
$string['frameworkselection_placeholder'] = 'Please choose a framework…';
$string['frameworkshortname'] = 'Framework';
$string['generatefailtitle'] = 'Something went wrong';
$string['generating'] = 'Generating your response';
$string['help'] = 'Help';
$string['knowledge'] = 'Knowledge';
$string['levels'] = 'Competency Levels';
$string['levelsselection'] = 'Competency Selection';
$string['levelsselection_help'] = 'Select the competency levels (taxonomies) you want the AI model to use when classifying your content.';
$string['none'] = 'None';
$string['notify_cm_added_heading']  = 'Added {$a->count} competencies to this activity';
$string['notify_cm_exists_heading'] = 'Already linked to this activity (not added): {$a->count}';
$string['notify_cm_failed_heading'] = 'Failed to link to activity: {$a->count}';
$string['notify_course_added_heading']  = 'Added {$a->count} competencies to this course';
$string['notify_course_exists_heading'] = 'Already in this course (not added): {$a->count}';
$string['notify_course_failed_heading'] = 'Failed to add to course: {$a->count}';
$string['notify_empty_description'] = 'Please add a description before using the Competency Classification tool.';
$string['pluginname'] = 'Competency';
$string['privacy:metadata'] = 'The AI Placement Competency plugin stores no personal data.';
$string['regenerate'] = 'Regenerate';
$string['skills'] = 'Skills';
$string['tasks'] = 'Tasks';
$string['tryagain'] = 'Try again';
