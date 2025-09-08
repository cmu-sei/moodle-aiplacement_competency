<?php
$string['pluginname']       = 'Classify Assist Placement';
$string['privacy:metadata'] = 'The Course Classifier plugin stores no personal data.';
$string['action_classify_text_instruction'] = '
You will receive:
- A course/activity description (free text).
- A competency framework shortname: "{$a->frameworkshortname}".
- A list of selected domains (top level within that framework): {$a->domains}

Task
Classify the description using ONLY competencies that belong to "{$a->frameworkshortname}" and fall within the listed domains. If you are not certain a competency exists in the specified framework/domain, DO NOT include it.

Output format
Return JSON only, with these keys:
{
  "framework": { "shortname": "{$a->frameworkshortname}" },
  "domains": {$a->domains},
  "competencies": [
    "CODE - Name"  // If the framework has canonical codes (e.g., NICE K/S/T/A codes). Otherwise just "Name".
  ]
}

Rules
1) Do not invent codes or names. If unsure, leave "competencies": [].
2) Do not add extra keys, explanations, or markdown — JSON only.

Examples (illustrative; not exhaustive)

Example A — single domain
{
  "framework": { "shortname": "NICE-1.0.0" },
  "domains": ["Oversight and Governance"],
  "competencies": [
    "T1119 - Recommend vulnerability remediation strategies",
    "S0415 - Skill in evaluating regulations",
    "K0676 - Knowledge of cybersecurity laws and regulations"
  ]
}

{
  "framework": { "shortname": "MITRE D3FEND" },
  "domains": ["Harden", "Detect"],
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
  "domains": ["Initial Access", "Execution"],
  "competencies": [
    "T1566 - Phishing",
    "T1059 - Command and Scripting Interpreter",
    "T1204 - User Execution"
  ]
}

Example B — multiple domains
{
  "framework": { "shortname": "NICE-1.0.0" },
  "domains": ["Protect and Defend", "Investigate"],
  "competencies": [
    "T1241 - Document cybersecurity incidents",
    "S0385 - Skill in communicating complex concepts",
    "K0674 - Knowledge of computer networking protocols"
  ]
}

Example C — no clear matches
{
  "framework": { "shortname": "NICE-1.0.0" },
  "domains": ["Analyze"],
  "competencies": []
}
';
$string['classifybutton'] = 'Classify Text';
$string['classifyheading'] = 'AI Classification Result';
$string['generatefailtitle'] = 'Something went wrong';
$string['generating'] = 'Generating your response';
$string['regenerate'] = 'Regenerate';
$string['classify_tooltips'] = 'Classify content based on competency framework.';
$string['tryagain'] = 'Try again';
$string['copy'] = 'Copy';
$string['classifyassist:classify_text'] = 'Classify Text';
$string['tasks'] = 'Tasks';
$string['skills'] = 'Skills';
$string['knowledge'] = 'Knowledge';
$string['none'] = 'None';
$string['aiclassificationlabel'] = 'AI Classification';
$string['aiclassification_help'] = 'Uses AI to classify course context according to the NICE Framework Competencies.';
$string['help'] = 'Help';
$string['applycmps'] = 'Apply CMPs';
$string['applycmps_title'] = 'Apply classification';
$string['applycmps_intro_checklist'] = 'Select the competencies you want to apply. You can uncheck any you don’t need.';
$string['applycmps_selectall'] = 'Select all';
$string['applycmps_clearall'] = 'Clear all';
$string['applycmps_clicktotoggle'] = 'Click the checkboxes to toggle competency selection.';
$string['applynow'] = 'Apply';
$string['frameworkshortname'] = 'Framework';
$string['notify_course_added_heading']  = 'Added {$a->count} competencies to this course';
$string['notify_course_exists_heading'] = 'Already in this course (not added): {$a->count}';
$string['notify_course_failed_heading'] = 'Failed to add to course: {$a->count}';
$string['notify_cm_added_heading']  = 'Added {$a->count} competencies to this activity';
$string['notify_cm_exists_heading'] = 'Already linked to this activity (not added): {$a->count}';
$string['notify_cm_failed_heading'] = 'Failed to link to activity: {$a->count}';
$string['domainsselection'] = 'Competency Selection';
$string['domainsselection_help'] = 'Select the competency levels (taxonomies) you want the AI model to use when classifying your content.';
$string['frameworkselection'] = 'Competency Framework Selection';
$string['frameworkselection_help'] = 'Choose the competency framework that the AI model should use to classify your content.';
$string['frameworkselection_label'] = 'Available competency frameworks:';
$string['frameworkselection_placeholder'] = 'Please choose a framework…';
$string['domains'] = 'Competency Levels';
$string['competencies'] = 'Competencies';
$string['applycompetencies'] = 'Apply';
$string['classify_note_newactivity'] = 'Once this activity has been saved, the Competency Classification tool will be enabled.';
$string['notify_empty_description'] = 'Please add a description before using the Competency Classification tool.';


