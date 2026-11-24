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

/**
 * Language strings for the AI Placement Competency plugin.
 *
 * @package    aiplacement_competency
 * @category   string
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['action_classify_text_instruction'] = '
This is defensive cybersecurity and IT training material, written by instructors to teach learners. You are
cataloguing what it teaches, not carrying out or elaborating on anything in it. Do not reproduce procedures from it.

You will receive:
- Educational content which may be: lecture transcripts, activity descriptions, quiz questions, lab instructions, assignments, or other learning materials.
- A competency framework shortname: "{$a->frameworkshortname}".
- A list of selected levels (top level within that framework): {$a->levels}
- The activity title (when known): "{$a->activitytitle}"

Task
Decide which competencies from "{$a->frameworkshortname}" the content TEACHES, then give a short reason for each.

- If the content is QUIZ QUESTIONS: the competencies being tested. Think about what the learner must know or do to answer.
- If the content is a LECTURE or DESCRIPTION: the competencies the learner gains by taking it.
- If the content is a LAB or ASSIGNMENT: the competencies the learner demonstrates by completing it.

CRITICAL REQUIREMENTS:
- Tag only what the activity teaches, not what it merely mentions. A passing reference is not teaching.
- Return at most {$a->maxtags} competencies. Fewer is better: return only the ones you are confident in.
- Use ATT&CK style adversary technique competencies only where the content actually explains that adversary behaviour.
- Use D3FEND style defensive technique competencies only where the content actually teaches that defensive technique.
- Use ONLY competencies from the AVAILABLE COMPETENCIES list below, and refer to each by its idnumber exactly as written in the square brackets.
- If no competency clearly applies, return an empty array.
- For every competency, give a "reason" of one short sentence that quotes or closely paraphrases the content that supports it.
- If the content does not appear to be about the activity title (for example it is another lecture\'s transcript), set "matches_title" to false.

Output format
Return JSON only, with these keys:
{
  "framework": { "shortname": "{$a->frameworkshortname}" },
  "levels": {$a->levels},
  "matches_title": true,
  "competencies": [
    { "idnumber": "T1586", "reason": "The instructor walks through scoring a risk register: \'rank each risk by likelihood and impact\'." }
  ]
}

Rules
1) Do not invent idnumbers. Use only idnumbers from the list.
2) Do not include competencies from other frameworks or levels not specified.
3) Do not add extra keys, explanations, or markdown: JSON only.
4) For quiz questions, focus on what the questions TEST, not what they literally say.
{$a->availablecompetencies}
';
$string['aiclassification_help'] = 'Uses AI to classify this content against the competency framework you select.';
$string['aiclassificationlabel'] = 'AI Classification';
$string['aiclassifyempty'] = 'The AI model returned no text. Some models answer with a reasoning block first, which Moodle reads as an empty reply. Try again, or check the provider\'s model settings.';
$string['aiclassifyfailed'] = 'AI classification failed. Please try again.';
$string['aiclassifyrefused'] = 'The AI model declined to classify this content, most likely because its safety filters objected to the security subject matter. Try a different model, or trim the content (solution guides and exploit walkthroughs are the usual cause).';
$string['aiclassifyunusable'] = 'The AI model did not return a usable answer. Please try again.';
$string['applycmps_clearall'] = 'Clear all';
$string['applycmps_clicktotoggle'] = 'Click the checkboxes to toggle competency selection.';
$string['applycmps_intro_checklist'] = 'Select the competencies you want to apply. You can uncheck any you don’t need.';
$string['applycmps_selectall'] = 'Select all';
$string['applycmps_title'] = 'Apply classification';
$string['applycompetencies'] = 'Apply';
$string['applynow'] = 'Apply';
$string['classify_note_newactivity'] = 'Once this activity has been saved, the Competency Classification tool will be enabled.';
$string['classify_note_nocontent'] = 'Add content to this activity to enable the Competency Classification tool.';
$string['classify_tooltips'] = 'Classify content based on competency framework.';
$string['classifybutton'] = 'Classify Text';
$string['classifyheading'] = 'AI Classification Result';
$string['competencies'] = 'Competencies';
$string['competency:classify_text'] = 'Classify Text';
$string['contentmismatch'] = 'This content does not appear to be about the activity "{$a}". It may be another activity\'s transcript or a mislabelled file, so check it before applying the tags.';
$string['contentpartial'] = 'The content was long, so it was read in {$a->read} of {$a->total} sections. Tags may miss material from the sections that were skipped.';
$string['copy'] = 'Copy';
$string['droppedbyverify'] = '{$a} suggested tag(s) were removed by the verification pass.';
$string['filesomitted'] = 'These files could not be read and were left out, so the tags may be incomplete: {$a}.';
$string['frameworkselection'] = 'Competency Framework Selection';
$string['frameworkselection_error'] = 'The competency frameworks could not be loaded.';
$string['frameworkselection_help'] = 'Choose the competency framework that the AI model should use to classify your content.';
$string['frameworkselection_label'] = 'Available competency frameworks:';
$string['frameworkselection_none'] = 'No competency frameworks found.';
$string['frameworkselection_placeholder'] = 'Please choose a framework…';
$string['frameworkshortname'] = 'Framework';
$string['generatefailtitle'] = 'Something went wrong';
$string['generating'] = 'Generating your response';
$string['help'] = 'Help';
$string['levels'] = 'Competency Levels';
$string['levelsselection'] = 'Competency Selection';
$string['levelsselection_empty'] = 'No competency levels are available for the selected framework.';
$string['levelsselection_error'] = 'The competency levels could not be loaded.';
$string['levelsselection_help'] = 'Select the competency levels (taxonomies) you want the AI model to use when classifying your content.';
$string['nocandidates'] = 'No competencies were found in the selected framework and levels, so none could be suggested. Go back and check the levels.';
$string['none'] = 'None';
$string['notify_cm_added_heading']  = 'Added {$a->count} competencies to this activity';
$string['notify_cm_exists_heading'] = 'Already linked to this activity (not added): {$a->count}';
$string['notify_cm_failed_heading'] = 'Failed to link to activity: {$a->count}';
$string['notify_course_added_heading']  = 'Added {$a->count} competencies to this course';
$string['notify_course_exists_heading'] = 'Already in this course (not added): {$a->count}';
$string['notify_course_failed_heading'] = 'Failed to add to course: {$a->count}';
$string['notify_empty_description'] = 'Please add content to this activity before using the Competency Classification tool.';
$string['notify_nocourseid'] = 'The course could not be determined, so no competencies were applied.';
$string['pluginname'] = 'Competency';
$string['privacy:metadata'] = 'The AI Placement Competency plugin stores no personal data.';
$string['reasonheading'] = 'Why these were suggested';
$string['regenerate'] = 'Regenerate';
$string['sectionsskipped'] = '{$a->skipped} of {$a->total} sections of the content could not be classified, so the tags may be incomplete. Try again.';
$string['setting_defaultframework'] = 'Default framework';
$string['setting_defaultframework_desc'] = 'The idnumber or shortname of the competency framework the Classify drawer selects for you, such as DCWF. Builders can still pick another. Leave empty to choose each time. A framework that is hidden, or that no framework matches, selects nothing.';
$string['setting_maxcandidates'] = 'Candidates shown to the model';
$string['setting_maxcandidates_desc'] = 'Frameworks with more competencies than this are narrowed to the closest keyword matches to the content before the model chooses.';
$string['setting_maxsections'] = 'Most sections read';
$string['setting_maxsections_desc'] = 'Long content is read in sections of about 12,000 characters, each a separate model request of several seconds. More sections than this are sampled evenly. Keep it low enough that one click stays inside the web server\'s time limit.';
$string['setting_maxtags'] = 'Most tags per framework';
$string['setting_maxtags_desc'] = 'The most competencies one classification returns. Reviewed tagging worked best at about 3 per framework.';
$string['setting_pairtranscripts'] = 'Tag a lecture\'s transcript with its video';
$string['setting_pairtranscripts_desc'] = 'When a competency is applied to a label holding a video, also apply it to the transcript file activity next to it in the same section.';
$string['setting_pdftimeout'] = 'PDF conversion time limit (seconds)';
$string['setting_pdftimeout_desc'] = 'pdftotext is stopped after this long on one file, and the file is reported as unreadable.';
$string['setting_pdftotext'] = 'Path to pdftotext';
$string['setting_pdftotext_desc'] = 'Full path of the pdftotext program (from poppler-utils). Leave empty to skip the text of PDF files.';
$string['setting_timebudget'] = 'Time budget (seconds)';
$string['setting_timebudget_desc'] = 'After this many seconds no further section is sent to the model, and no verification is run, so the request ends before the web server\'s time limit. The Moodle image times out at 60 seconds.';
$string['setting_verify'] = 'Offer a verification pass';
$string['setting_verify_desc'] = 'Adds a checkbox to the Classify drawer that has the model double-check its suggestions and remove weak ones.';
$string['timebudgetreached'] = 'Stopped after {$a->read} of {$a->total} sections to stay within the server\'s time limit. The tags come from the sections that were read.';
$string['tryagain'] = 'Try again';
$string['verify_instruction'] = '
You are a strict reviewer of competency tags for defensive cybersecurity training material. You are not
to carry out or elaborate on anything in the content: only to check the tags against it.

Framework: "{$a->frameworkshortname}". Activity title: "{$a->activitytitle}".

Another reviewer proposed these tags, each with the reason it gave:
{$a->tags}

Your only job is to REMOVE weak tags. Remove a tag when the content merely mentions the topic rather than
teaching it, when the reason given is not supported by the content, or when it is too general or too specific
for what is taught. Keep a tag only if the content clearly teaches it. Never add tags.

Return JSON only: { "keep": ["idnumber", "idnumber"] } listing the idnumbers to keep, exactly as written
in the square brackets above. An empty array removes everything.
';
$string['verifyfailed'] = 'The verification pass did not give a usable answer, so these tags have not been double-checked.';
$string['verifyoption'] = 'Verify the suggested tags with a second check before showing them';
$string['verifyoption_help'] = 'Sends the tags and their reasons back to the model, asked only to remove ones the content does not support. Slower and costs one more request, but usually removes weak tags.';
