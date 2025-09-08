<?php
declare(strict_types=1);

namespace aiplacement_classifyassist;

use core\di;
use context;
use aiplacement_classifyassist\local\utils;

class placement extends \core_ai\placement {
    public static function get_action_list(): array {
        return [\core_ai\aiactions\generate_text::class];
    }

    public function classify(
        context $context,
        string $prompttext,
        int $selectedframeworkid = 0,
        string $selectedframeworkshortname = '',
        array $levels = []
    ): string {
        global $USER;

        // Builds system instruction with course description
        $instruction = utils::build_instruction($selectedframeworkid, $selectedframeworkshortname, $levels);
        $finalprompt = $instruction . "\n\nTEXT TO CLASSIFY:\n" . $prompttext;

        $action = new \core_ai\aiactions\generate_text(
            $context->id,
            (int)$USER->id,
            $finalprompt
        );

        if (method_exists($action, 'set_meta')) {
            $action->set_meta([
                'frameworkid'        => $selectedframeworkid,
                'frameworkshortname' => $selectedframeworkshortname,
                'levels'            => $levels
            ]);
        }

        $manager  = di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        $success = method_exists($response, 'get_success')
            ? (bool)$response->get_success()
            : true;

        if (!$success) {
            $msg = method_exists($response, 'get_errormessage')
                ? (string)($response->get_errormessage() ?? '')
                : 'Unknown AI error';
            throw new \moodle_exception('aiclassifyfailed', 'aiplacement_classifyassist', '', null, $msg);
        }

        $rawdata = $response->get_response_data();
        return json_encode($rawdata, JSON_UNESCAPED_UNICODE);
    }
}