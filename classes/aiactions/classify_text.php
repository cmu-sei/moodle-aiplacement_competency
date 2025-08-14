<?php
declare(strict_types=1);

namespace aiplacement_classifyassist\aiactions;

use core_ai\aiactions\base;
use core_ai\aiactions\responses\response_base;

class classify_text extends base {

    protected int $userid;
    protected string $prompttext;

    public static function get_required_properties(): array {
        return ['contextid', 'userid', 'prompttext'];
    }

    public function __construct(int $contextid, int $userid, string $prompttext) {
        $this->userid = $userid;
        $this->prompttext = $prompttext;
        parent::__construct($contextid);
    }

    public static function get_name(): string {
        return get_string('aiaction_classify_content_name', 'aiplacement_classifyassist');
    }
    public static function get_description(): string {
        return get_string('aiaction_classify_content_desc', 'aiplacement_classifyassist');
    }

    public function store(response_base $response): int {
        return hrtime(true);
    }

    public static function get_response_classname(): string {
        return response_classify_text::class;
    }

    public static function get_system_instruction(): string {
        return get_string('action_classify_text_instruction', 'aiplacement_classifyassist');
    }

    public function process(): \core_ai\local\responses\response_base {
        $context      = \context::instance_by_id($this->contextid);

        $client       = $this->get_client();
        $airesponse   = $client->execute_action('classify_text', [
            'contextid' => $this->contextid,
            'userid'    => $this->userid,
            'text'      => $contexttext,   // Sent to the model
        ]);

        $rawdata = $airesponse->get_response_data();
        $json    = $rawdata['response'] ?? null;

        if (!$json) {
            throw new \moodle_exception("Missing 'response' from AI.");
        }
        if (!is_array(json_decode($json, true))) {
            throw new \moodle_exception("Invalid JSON returned by AI: {$json}");
        }

        $response = new response_classify_text(true);
        $response->set_actionname('classify_text');
        $response->set_generatedcontent($json);
        $response->set_response_data($rawdata);

        return $response;
    }

    private function collect_context_text(\context $context): string {
        global $DB;
    
        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                $course   = get_course($context->instanceid);
                $sections = $DB->get_records('course_sections', ['course' => $course->id]);
    
                $buffer = [$course->fullname];
                foreach ($sections as $sec) {
                    $name   = format_string($sec->name, true, ['context' => $context]);
                    $summary= trim(strip_tags($sec->summary));
                    if ($name)     { $buffer[] = $name; }
                    if ($summary)  { $buffer[] = $summary; }
                }
                return implode("\n", $buffer);
    
            case CONTEXT_MODULE:
                $cm       = get_coursemodule_from_id(null, $context->instanceid, 0, false, MUST_EXIST);
                $module   = $DB->get_record('modules', ['id' => $cm->module], '*', MUST_EXIST);
                $table    = $module->name;
                $record   = $DB->get_record($table, ['id' => $cm->instance], '*', MUST_EXIST);
    
                $name     = format_string($cm->name, true, ['context' => $context]);
                $intro    = trim(strip_tags($record->intro ?? ''));
                return ($name ?: '[Unnamed activity]') . "\n" . ($intro ?: '[No description]');
    
            default:
                return $context->get_context_name() ?: '[Unnamed context]';
        }
    }
    
}