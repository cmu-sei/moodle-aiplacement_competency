<?php
declare(strict_types=1);

namespace aiplacement_classifyassist\local;

class utils {

    /**
     * Build the instruction text for the model.
     */
    public static function build_instruction(int $frameworkid, string $shortname, array $levels): string {
        $seen = [];
        $normlevels = [];
        foreach ($levels as $d) {
            if (!is_string($d)) { continue; }
            $t = trim($d);
            if ($t === '') { continue; }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) { continue; }
            $seen[$k] = true;
            $normlevels[] = $t;
        }

        $a = (object)[
            'frameworkid'        => $frameworkid,
            'frameworkshortname' => $shortname,
            'levels'            => implode(', ', $normlevels),
        ];

        return get_string('action_classify_text_instruction', 'aiplacement_classifyassist', $a);
    }

    /**
     * Extract model output assuming the provider returns raw JSON (no code fences, no prose).
     */
    public static function extract_classification(array $raw): array {
        $payload = $raw['generatedcontent'] ?? ($raw['response'] ?? null);

        if (is_string($payload)) {
            $inner = json_decode($payload, true) ?: [];
        } else if (is_array($payload)) {
            $inner = $payload;
        } else {
            $inner = [];
        }

        if (isset($inner['response'])) {
            if (is_string($inner['response'])) {
                $inner = json_decode($inner['response'], true) ?: $inner;
            } else if (is_array($inner['response'])) {
                $inner = $inner['response'];
            }
        }

        $normStrings = function($rawval): array {
            $arr = is_array($rawval) ? $rawval : (is_string($rawval) ? [$rawval] : []);
            $out = [];
            foreach ($arr as $v) {
                if (!is_string($v)) { continue; }
                $clean = clean_param(trim($v), PARAM_TEXT);
                if ($clean !== '') { $out[] = $clean; }
            }
            $seen = [];
            $uniq = [];
            foreach ($out as $v) {
                $k = mb_strtolower($v);
                if (isset($seen[$k])) { continue; }
                $seen[$k] = true;
                $uniq[] = $v;
            }
            return $uniq;
        };

        $frameworkshortname = '';
        if (!empty($inner['framework']) && is_array($inner['framework'])) {
            $frameworkshortname = clean_param(trim((string)($inner['framework']['shortname'] ?? '')), PARAM_TEXT);
        }

        $levels       = $normStrings($inner['levels'] ?? []);
        $competencies  = $normStrings($inner['competencies'] ?? []);

        return [
            'frameworkshortname' => $frameworkshortname,
            'levels'            => $levels,
            'competencies'       => $competencies,
        ];
    }
}
