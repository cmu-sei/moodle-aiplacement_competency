<?php
declare(strict_types=1);

namespace aiplacement_classifyassist\local;

class utils {
    // Send system instruction with selected competency framework
    public static function build_instruction(int $frameworkid, string $shortname): string {
        $a = (object)['frameworkid' => $frameworkid, 'frameworkshortname' => $shortname];
        return get_string('action_classify_text_instruction', 'aiplacement_classifyassist', $a);
    }

    // Extract TSK
    public static function extract_classification(array $raw): array {
        $payload = $raw['generatedcontent']
            ?? ($raw['response'] ?? null);

        $inner = [];

        if (is_string($payload)) {
            $inner = self::decode_json_maybe($payload) ?? [];
        } elseif (is_array($payload)) {
            $inner = $payload;
        }

        if (isset($inner['response']) && is_string($inner['response'])) {
            $maybe = self::decode_json_maybe($inner['response']);
            if (is_array($maybe)) {
                $inner = $maybe;
            }
        }

        $norm = function($rawval) {
            $arr = is_array($rawval) ? $rawval : (is_string($rawval) ? [$rawval] : []);
            $out = [];
            foreach ($arr as $v) {
                if (!is_string($v)) { continue; }
                $clean = clean_param(trim($v), PARAM_TEXT);
                if ($clean !== '') { $out[] = $clean; }
            }
            return array_values(array_unique($out));
        };

        return [
            'tasks'     => $norm($inner['tasks'] ?? []),
            'skills'    => $norm($inner['skills'] ?? []),
            'knowledge' => $norm($inner['knowledge'] ?? []),
        ];
    }

    // Decode JSON Response
    private static function decode_json_maybe(string $s): ?array {
        $s = trim($s);

        if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $s, $m)) {
            $s = $m[1];
        }

        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $p1 = strpos($s, '{'); $p2 = strrpos($s, '}');
        if ($p1 !== false && $p2 !== false && $p2 > $p1) {
            $slice = substr($s, $p1, $p2 - $p1 + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }
}
