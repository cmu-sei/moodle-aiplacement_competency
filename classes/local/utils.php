<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
AI Placement Plugin for Moodle Competencies

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY
DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/017
*/

declare(strict_types=1);

namespace aiplacement_competency\local;

/**
 * Utility methods for AI Placement Competency plugin.
 *
 * Provides helper functions for building model prompts and
 * extracting classification data from provider responses.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils {

    /**
     * Build the instruction text for the model.
     */
    public static function build_instruction(int $frameworkid, string $shortname, array $levels): string {
        $seen = [];
        $normlevels = [];
        foreach ($levels as $d) {
            if (!is_string($d)) {
                continue;
            }
            $t = trim($d);
            if ($t === '') {
                continue;
            }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $normlevels[] = $t;
        }

        $a = (object)[
            'frameworkid'        => $frameworkid,
            'frameworkshortname' => $shortname,
            'levels'            => implode(', ', $normlevels),
        ];

        return get_string('action_classify_text_instruction', 'aiplacement_competency', $a);
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

        $normstrings = function($rawval): array {
            $arr = is_array($rawval) ? $rawval : (is_string($rawval) ? [$rawval] : []);
            $out = [];
            foreach ($arr as $v) {
                if (!is_string($v)) {
                    continue;
                }
                $clean = clean_param(trim($v), PARAM_TEXT);
                if ($clean !== '') {
                    $out[] = $clean;
                }
            }
            $seen = [];
            $uniq = [];
            foreach ($out as $v) {
                $k = mb_strtolower($v);
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $uniq[] = $v;
            }
            return $uniq;
        };

        $frameworkshortname = '';
        if (!empty($inner['framework']) && is_array($inner['framework'])) {
            $frameworkshortname = clean_param(trim((string)($inner['framework']['shortname'] ?? '')), PARAM_TEXT);
        }

        $levels       = $normstrings($inner['levels'] ?? []);
        $competencies  = $normstrings($inner['competencies'] ?? []);

        return [
            'frameworkshortname' => $frameworkshortname,
            'levels'            => $levels,
            'competencies'       => $competencies,
        ];
    }
}
