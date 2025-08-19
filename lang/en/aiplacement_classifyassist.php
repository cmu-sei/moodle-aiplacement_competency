<?php
$string['pluginname']       = 'Classify Assist Placement';
$string['classify']         = 'AI classify';
$string['aiclassifyfailed'] = 'AI classification failed: {$a}.';
$string['privacy:metadata'] = 'The Course Classifier plugin stores no personal data.';
$string['aiaction_classify_content_name'] = 'Classify Content';
$string['aiaction_classify_content_desc'] = 'Analyses text and returns role / work-role labels.';
$string['action_classify_text_instruction'] = '
You will receive a course or activity description. Your task is to classify it according to the NICE Cybersecurity Workforce Framework.

Follow these important instructions:

1. Return only a valid JSON object with the following structure:
{
    "tasks": [
        "<Task code and name">,
        "<Task code and name">,
        ...
    "skills": [
        "<Skill code and name>",
        "<Skill code and name>",
        ...
    ]
    "knowledge": [
        "<Knowledge skill and name>",
        "<Knowledge skill and name>",
        ...
    ]
}

2. Only use official NICE Framework work roles, task codes/names, and skill codes/names.
3. If there is no clear match, respond with null for that field.
4. Do not include explanations, reasoning, or commentary.
5. Output only the JSON. No extra text, markdown, or formatting.

Example:

{
    "tasks": [
        "T1112 - Validate network alerts",
        "T1119 - Recommend vulnerability remediation strategies",
        "T1241 - Document cybersecurity incidents"
    ]
    "skills": [
        "S0385 - Skill in communicating complex concepts",
        "S0186 - Skill in applying crisis planning procedures",
        "S0415 - Skill in evaluating regulations"
    ]
    "knowledge": [
        "K0674 - Knowledge of computer networking protocols",
        "K0676 - Knowledge of cybersecurity laws and regulations",
        "K0677 - Knowledge of cybersecurity policies and procedures"
    ]
}
';
$string['classifybutton'] = 'Classify Text';
$string['classifyheading'] = 'AI Classification Result';
$string['generatefailtitle'] = 'Something went wrong';
$string['generating'] = 'Generating your response';
$string['regenerate'] = 'Regenerate';
$string['classify_tooltips'] = 'Classify content based on work roles.';
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
$string['applytsks'] = 'Apply TSKs';
$string['applytsks_title'] = 'Apply classification';
$string['applytsks_intro_checklist'] = 'Select the competencies you want to apply. You can uncheck any you don’t need.';
$string['applytsks_selectall'] = 'Select all';
$string['applytsks_clearall'] = 'Clear all';
$string['applytsks_clicktotoggle'] = 'Click the checkboxes to toggle competency selection.';
$string['applynow'] = 'Apply';
$string['frameworkshortname'] = 'Framework';




