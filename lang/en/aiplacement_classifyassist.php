<?php
$string['pluginname']       = 'Classify Assist';
$string['classify']         = 'AI classify';
$string['aiclassifyfailed'] = 'AI classification failed: {$a}.';
$string['privacy:metadata'] = 'The Course Classifier plugin stores no personal data.';
$string['aiaction_classify_content_name'] = 'Classify Content';
$string['aiaction_classify_content_desc'] = 'Analyses text and returns role / work-role labels.';
$string['action_classify_text_instruction'] = '
You will receive a course or activity description. Your task is to classify it according to the NICE Cybersecurity Workforce Framework. Follow these important instructions:
1. Return only a valid JSON object with the following structure:
{
  "role": "attack" | "defend" | "neutral",
  "work_role": one of [
    "Cyber Defense Analyst",
    "Penetration Tester",
    "Cyber Crime Investigator",
    "Cyber Defense Forensics Analyst",
    "Risk Manager",
    "Incident Responder",
    "Security Architect",
    null
  ],
  "sources_required": true | false
}
2. Only use job roles from the list above. Respond with null for work_role if none match.
3. Do not include explanations, reasoning, or commentary.
4. Output only the JSON. No extra text, markdown, or formatting.';
$string['classifybutton'] = 'Classify';
$string['classifyheading'] = 'AI Classification Result';
$string['role']            = 'Role';
$string['workrole']        = 'Work role';
$string['generatefailtitle'] = 'Something went wrong';
$string['generating'] = 'Generating your response';
$string['regenerate'] = 'Regenerate';
$string['classify_tooltips'] = 'Classify content based on work roles.';
$string['tryagain'] = 'Try again';
$string['copy'] = 'Copy';

