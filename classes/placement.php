<?php
declare(strict_types=1);

namespace aiplacement_classifyassist;

use core\di;
use context;

class placement extends \core_ai\placement {
    public static function get_action_list(): array {
        return [\aiplacement_classifyassist\aiactions\classify_text::class];
    }

    public function classify(context $context, string $prompttext): string {
        global $USER;

        $action = new \aiplacement_classifyassist\aiactions\classify_text(
            $context->id,
            (int) $USER->id,
            $prompttext
        );

        $manager  = di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        if ($response->is_error()) {
            throw new \moodle_exception('aiclassifyfailed', 'aiplacement_classifyassist',
                '', null, $response->get_error());
        }

        $raw = $response->get_response_data()['response'] ?? '{}';

        return $raw;
    }

}