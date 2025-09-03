<?php
// ai/placement/classifyassist/cli/test_classifyassist.php
//
// Usage examples:
//
// 1) List frameworks (from system context):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --listframeworks
//
// 2) List top-level domains (parent competencies) for a framework:
//    php ai/placement/classifyassist/cli/test_classifyassist.php --frameworkid=42 --listdomains
//
// 3) Placement path (raw provider JSON):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --courseid=8 \
//      --frameworkid=42 --frameworkshortname="NICE-1.0.0" --domains="Tasks,Skills" \
//      --text="Blue-team IR lab." --pretty
//
// 4) External path (parsed result from externallib):
//    php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 \
//      --frameworkid=42 --frameworkshortname="NICE-1.0.0" --domains="Tasks,Knowledges" \
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
        'domains'              => null,   // CSV list of domain names
        'listframeworks'       => false,  // list frameworks and exit
        'listdomains'          => false,  // list top-level domains for --frameworkid and exit
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
        'd' => 'domains',
        'l' => 'listframeworks',
        'L' => 'listdomains',
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
  --text="..."                Text to classify (REQUIRED unless --listframeworks/--listdomains)
  --frameworkid=ID            REQUIRED with classification and --listdomains
  --frameworkshortname="..."  REQUIRED with classification
  --domains="A,B,C"           Comma-separated domain names to constrain the classification
  --listframeworks            List frameworks from system context and exit
  --listdomains               List top-level domains (parent competencies) for --frameworkid and exit
  --mode=placement|external   placement=provider raw JSON (default), external=parsed WS
  --pretty                    Pretty-print JSON
  -h, --help                  Show help

Examples:
  # List available frameworks:
  php ai/placement/classifyassist/cli/test_classifyassist.php --listframeworks

  # List top-level domains for a framework:
  php ai/placement/classifyassist/cli/test_classifyassist.php --frameworkid=42 --listdomains

  # Placement (raw provider JSON back):
  php tests/test_classifyassist.php --courseid=8 --frameworkid=1 --frameworkshortname="NICE-1.0.0" --domains="Tasks,Skills" --text="Blue-team IR lab." --pretty

  # External (parsed fields from your externallib):
  php ai/placement/classifyassist/cli/test_classifyassist.php --contextid=45 \\
      --frameworkid=42 --frameworkshortname="NICE-1.0.0" --domains="Tasks,Knowledges" \\
      --text="Classify this." --mode=external --pretty
EOF;
    cli_writeln($help);
    exit(0);
}

// Log in as admin (capabilities for listing/searching).
$USER = get_admin();
\core\session\manager::set_user($USER);

// ---------- Optional: list frameworks ----------
if (!empty($options['listframeworks'])) {
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

// ---------- Optional: list top-level domains for a framework ----------
if (!empty($options['listdomains'])) {
    $fwid = isset($options['frameworkid']) ? (int)$options['frameworkid'] : 0;
    if ($fwid <= 0) {
        cli_error('Provide --frameworkid to use --listdomains.');
    }
    $filters = [
        ['name' => 'competencyframeworkid', 'value' => $fwid],
        ['name' => 'parentid',              'value' => 0],
    ];
    $rows = \core_competency\api::list_competencies($filters, 'shortname', 'ASC', 0, 0);
    cli_writeln("Top-level domains for framework {$fwid}:");
    foreach ($rows as $row) {
        /** @var \core_competency\competency $row */
        $id = (int)$row->get('id');
        $sn = (string)$row->get('shortname');
        $nm = (string)$row->get('competencyname');
        cli_writeln(sprintf("  id=%d  shortname=%s  name=%s", $id, $sn, $nm));
    }
    exit(0);
}

// ---------- Validate inputs (for classification run) ----------
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

// Required framework info.
$fwid   = isset($options['frameworkid']) ? (int)$options['frameworkid'] : 0;
$fwshort= isset($options['frameworkshortname']) ? (string)$options['frameworkshortname'] : '';
if ($fwid <= 0 || $fwshort === '') {
    cli_error('Provide --frameworkid and --frameworkshortname (both required).');
}

// Parse domains CSV -> array of trimmed names.
$domaincsv = isset($options['domains']) ? (string)$options['domains'] : '';
$domains = array_values(array_filter(array_map('trim',
    preg_split('/\s*,\s*/', $domaincsv, -1, PREG_SPLIT_NO_EMPTY)
)));

$mode   = strtolower((string)$options['mode']);
$prompt = (string)$options['text'];
$pretty = !empty($options['pretty']);

// ---------- Build the exact prompt that the action will send (for visibility) ----------
$instr = get_string('action_classify_text_instruction', 'aiplacement_classifyassist', (object)[
    'frameworkid' => $fwid,
    'frameworkshortname' => $fwshort,
    'domains' => implode(', ', $domains),
]);
$finalprompt = $instr . "\n\nTEXT TO CLASSIFY:\n" . $prompt;

cli_writeln("=== Built Prompt (preview) ===");
cli_writeln($finalprompt);
cli_writeln(str_repeat('=', 40));

// ---------- Run according to mode ----------
try {
    switch ($mode) {
        case 'external':
            // Mirrors AJAX route (externallib): returns parsed fields.
            // Signature: execute(int $contextid, string $prompttext, int $selectedframeworkid,
            //                   string $selectedframeworkshortname, array $domains)
            $result = ExternalClassify::execute($context->id, $prompt, $fwid, $fwshort, $domains);

            cli_writeln('=== External (parsed) result ===');
            if ($pretty) {
                cli_writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                print_r($result);
            }
            break;

        case 'placement':
        default:
            // Direct provider call: returns raw provider JSON string.
            $placement = new \aiplacement_classifyassist\placement();
            // placement->classify($context, $prompt, $fwid, $fwshort, array $domains = [])
            $raw = $placement->classify($context, $prompt, $fwid, $fwshort, $domains);

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
