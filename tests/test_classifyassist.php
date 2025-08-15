<?php
// ai/placement/classifyassist/cli/test_classifyassist.php
// Run from Moodle root like:
// php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 --text="Sample text"
// or with course id:
// php ai/placement/classifyassist/cli/test_classifyassist.php --courseid=8 --text="..." --mode=external

define('CLI_SCRIPT', true);

// Adjust config path relative to this file.
require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use aiplacement_classifyassist\external\classify_text;

// --- Parse CLI params ---
list($options, $unrecognized) = cli_get_params(
    [
        'contextid' => null,
        'courseid'  => null,
        'text'      => null,
        'mode'      => 'placement', // placement | external
        'pretty'    => false,       // pretty-print json where relevant
        'help'      => false,
    ],
    [
        'c' => 'contextid',
        'C' => 'courseid',
        't' => 'text',
        'm' => 'mode',
        'p' => 'pretty',
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    $help = <<<EOF
Test Classify Assist (CLI)

Usage:
  php ai/placement/classifyassist/cli/test_classifyassist.php [options]

Options:
  --contextid=ID          Context ID to use (course or module context)
  --courseid=ID           Course ID (used to derive a course context if --contextid not given)
  --text="..."            Prompt text to classify (REQUIRED)
  --mode=placement        Call placement directly (default). Returns provider raw JSON string.
  --mode=external         Call external function (same as AJAX). Returns parsed fields.
  --pretty                Pretty-print JSON where appropriate
  -h, --help              Show this help

Examples:
  # Placement path (raw provider JSON back):
  php ai/placement/classifyassist/cli/test_classifyassist.php --courseid=8 --text="Blue-team IR lab."

  # External path (work_role/task/skills back from your external function):
  php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 --text="Classify this course." --mode=external

EOF;
    cli_writeln($help);
    exit(0);
}

// --- Validate inputs ---
if (empty($options['text'])) {
    cli_error('Missing --text argument.');
}

$context = null;
if (!empty($options['contextid'])) {
    $context = context::instance_by_id((int)$options['contextid'], MUST_EXIST);
} else if (!empty($options['courseid'])) {
    $context = context_course::instance((int)$options['courseid'], MUST_EXIST);
} else {
    cli_error('Provide either --contextid or --courseid.');
}

// Emulate admin user for capabilities.
$USER = get_admin();

// --- Run according to mode ---
$mode    = strtolower((string)$options['mode']);
$prompt  = (string)$options['text'];
$pretty  = !empty($options['pretty']);

try {
    switch ($mode) {
        case 'external':
            // This mirrors your AJAX call route.
            $result = classify_text::execute($context->id, $prompt);

            cli_writeln('=== External (parsed) result ===');
            if ($pretty) {
                cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                print_r($result);
            }
            break;

        case 'placement':
        default:
            // Direct placement -> provider. Usually returns
            // a provider JSON string like {"model":...,"response":"{...}"}.
            $placement = new \aiplacement_classifyassist\placement();
            $raw = $placement->classify($context, $prompt);

            cli_writeln('=== Placement raw JSON ===');
            if ($pretty) {
                // Pretty-print if it is valid JSON.
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    cli_writeln(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    // If provider returned non-JSON, just echo as-is.
                    cli_writeln($raw);
                }
            } else {
                cli_writeln($raw);
            }
    }
} catch (Throwable $e) {
    cli_writeln('ERROR: ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
    exit(1);
}
