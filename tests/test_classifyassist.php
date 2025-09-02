<?php
// ai/placement/classifyassist/cli/test_classifyassist.php
// Usage examples:
//
// 1) List frameworks (from system context):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --listframeworks
//
// 2) Placement path (raw provider JSON):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --courseid=8 \
//      --frameworkid=42 --frameworkshortname="NICE" \
//      --text="Blue-team IR lab." --pretty
//
// 3) External path (parsed result from externallib):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 \
//      --frameworkid=42 --frameworkshortname="NICE" \
//      --text="Classify this." --mode=external --pretty

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use aiplacement_classifyassist\external\classify_text as ExternalClassify;

// ---------- Parse CLI params ----------
list($options, $unrecognized) = cli_get_params(
    [
        'contextid'            => null,
        'courseid'             => null,
        'text'                 => null,
        'frameworkid'          => null,
        'frameworkshortname'   => null,
        'listframeworks'       => false,  // list and exit
        'mode'                 => 'placement', // placement | external
        'pretty'               => false,
        'help'                 => false,
    ],
    [
        'c' => 'contextid',
        'C' => 'courseid',
        't' => 'text',
        'f' => 'frameworkid',
        's' => 'frameworkshortname',
        'l' => 'listframeworks',
        'm' => 'mode',
        'p' => 'pretty',
        'h' => 'help',
    ]
);

if (!empty($options['help'])) {
    $help = <<<EOF
Test Classify Assist (CLI)

Options:
  --contextid=ID              Context ID (course or module)
  --courseid=ID               Course ID (used if --contextid not given)
  --text="..."                Text to classify (REQUIRED unless --listframeworks)
  --frameworkid=ID            REQUIRED: competency framework id
  --frameworkshortname="..."  REQUIRED: competency framework shortname
  --listframeworks            List frameworks from system context and exit
  --mode=placement|external   placement=provider raw JSON (default), external=parsed WS
  --pretty                    Pretty-print JSON
  -h, --help                  Show help

Examples:
  # List available frameworks:
  php ai/placement/classifyassist/cli/test_classifyassist.php --listframeworks

  # Placement (raw provider JSON back):
  php ai/placement/classifyassist/cli/test_classifyassist.php --courseid=8 \\
      --frameworkid=42 --frameworkshortname="NICE" \\
      --text="Blue-team IR lab." --pretty

  # External (parsed fields from your externallib):
  php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 \\
      --frameworkid=42 --frameworkshortname="NICE" \\
      --text="Classify this." --mode=external --pretty
EOF;
    cli_writeln($help);
    exit(0);
}

// ---------- Optional: list frameworks ----------
if (!empty($options['listframeworks'])) {
    $USER = get_admin();
    \core\session\manager::set_user($USER); // loads capabilities for $USER
    $sys = \context_system::instance();
    $frameworks = \core_competency\api::list_frameworks('id', 'ASC', 0, 0, $sys);
    cli_writeln("Frameworks (system context):");
    foreach ($frameworks as $fw) {
        /** @var \core_competency\competency_framework $fw */
        cli_writeln(sprintf("  id=%d  shortname=%s  idnumber=%s",
            (int)$fw->get('id'),
            (string)$fw->get('shortname'),
            (string)$fw->get('idnumber')
        ));
    }
    exit(0);
}

// ---------- Validate inputs ----------
if (empty($options['text'])) {
    cli_error('Missing --text argument.');
}

$context = null;
if (!empty($options['contextid'])) {
    $context = \context::instance_by_id((int)$options['contextid'], MUST_EXIST);
} else if (!empty($options['courseid'])) {
    $context = \context_course::instance((int)$options['courseid'], MUST_EXIST);
} else {
    cli_error('Provide either --contextid or --courseid.');
}

// The action now REQUIRES a framework id + shortname.
$fwid   = isset($options['frameworkid']) ? (int)$options['frameworkid'] : 0;
$fwshort= isset($options['frameworkshortname']) ? (string)$options['frameworkshortname'] : '';
if ($fwid <= 0 || $fwshort === '') {
    cli_error('Provide --frameworkid and --frameworkshortname (both required).');
}

// Emulate admin user for capability checks if needed.
$USER = get_admin();

$mode   = strtolower((string)$options['mode']);
$prompt = (string)$options['text'];
$pretty = !empty($options['pretty']);

// ---------- Build the exact prompt that the action will send ----------
$instr = get_string('action_classify_text_instruction', 'aiplacement_classifyassist', (object)[
    'frameworkid' => $fwid,
    'frameworkshortname' => $fwshort,
]);
$finalprompt = $instr . "\n\nTEXT TO CLASSIFY:\n" . $prompt;

cli_writeln("=== Built Prompt (preview) ===");
cli_writeln($finalprompt);
cli_writeln(str_repeat('=', 40));

// ---------- Run according to mode ----------
try {
    switch ($mode) {
        case 'external':
            // Mirrors AJAX route (externallib): returns parsed arrays.
            $result = ExternalClassify::execute($context->id, $prompt, $fwid, $fwshort);

            cli_writeln('=== External (parsed) result ===');
            if ($pretty) {
                cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                print_r($result);
            }
            break;

        case 'placement':
        default:
            // Direct placement -> provider (raw provider JSON struct).
            $placement = new \aiplacement_classifyassist\placement();
            $raw = $placement->classify($context, $prompt, $fwid, $fwshort);

            cli_writeln('=== Placement raw JSON ===');
            $decoded = json_decode($raw, true);
            if ($pretty && is_array($decoded)) {
                cli_writeln(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                cli_writeln($raw);
            }
            break;
    }
} catch (\Throwable $e) {
    cli_writeln('ERROR: ' . $e->getMessage());
    cli_writeln($e->getTraceAsString());
    exit(1);
}
