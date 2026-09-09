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
        global $DB;

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

        // Fetch available competencies from the framework to provide to the AI.
        $availablecompetencies = '';
        $displaylevels = $normlevels;
        if ($frameworkid > 0) {
            try {
                // Get parent competencies (levels) that match the selected levels.
                $parentcompetencies = $DB->get_records('competency', [
                    'competencyframeworkid' => $frameworkid,
                    'parentid' => 0,
                ]);

                $matchedparents = self::match_levels($parentcompetencies, $normlevels);

                // The drawer lowercases the levels before sending them, so where a
                // level named a competency, that competency's own shortname is the
                // better thing to put in front of the model.
                if (!empty($normlevels) && !empty($matchedparents)) {
                    $named = [];
                    foreach ($matchedparents as $parent) {
                        $shortname = trim((string)($parent->shortname ?? ''));
                        if ($shortname !== '') {
                            $named[] = $shortname;
                        }
                    }
                    if (!empty($named)) {
                        $displaylevels = $named;
                    }
                }

                $competencylist = [];
                foreach ($matchedparents as $parent) {
                    // Get all child competencies under this parent.
                    $children = $DB->get_records('competency', [
                        'competencyframeworkid' => $frameworkid,
                        'parentid' => $parent->id,
                    ], 'shortname ASC');

                    foreach ($children as $child) {
                        $code = trim($child->shortname ?? '');
                        $name = trim(strip_tags($child->description ?? ''));
                        if (empty($name)) {
                            $name = trim($child->idnumber ?? '');
                        }

                        if ($code) {
                            $competencylist[] = $code . ($name ? ' - ' . $name : '');
                        }
                    }
                }

                if (!empty($competencylist)) {
                    // Limit to first 200 competencies to avoid token limits.
                    $competencylist = array_slice($competencylist, 0, 200);
                    $availablecompetencies = "\n\nAVAILABLE COMPETENCIES IN THIS FRAMEWORK/LEVEL:\n" .
                                           implode("\n", $competencylist) .
                                           "\n\nYou MUST only use competencies from the list above.";
                }
            } catch (\Exception $e) {
                // If there's an error fetching competencies, continue without the list.
                debugging('Failed to fetch competencies for AI instruction: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $a = (object)[
            'frameworkid'        => $frameworkid,
            'frameworkshortname' => $shortname,
            'levels'            => implode(', ', $displaylevels),
            'availablecompetencies' => $availablecompetencies,
        ];

        return get_string('action_classify_text_instruction', 'aiplacement_competency', $a);
    }

    /**
     * The framework's top level competencies that the selected levels name.
     *
     * The checkbox values in the drawer are these competencies' own shortnames,
     * so the comparison is an equality one, on a case folded and whitespace
     * collapsed key: the drawer lowercases what it sends.
     *
     * Substring matching, which is what this did for every level unconditionally,
     * is kept only as a per level fallback for a caller that sent something other
     * than a shortname. On its own it matches far too much, because a short level
     * name turns up inside unrelated ones: 'IT' is inside 'Monitoring', and the
     * competencies of a level nobody asked for would then be sent to the model.
     *
     * @param array $parents The framework's top level competency records, keyed by id.
     * @param array $levels The level names the caller selected, already normalised.
     * @return array The matching parents, keyed by id. All of them when no level was selected.
     */
    private static function match_levels(array $parents, array $levels): array {
        if (empty($levels)) {
            return $parents;
        }

        $matched = [];

        foreach ($levels as $level) {
            $key = self::level_key($level);
            $found = false;

            foreach ($parents as $id => $parent) {
                if ($key !== '' && self::level_key((string)($parent->shortname ?? '')) === $key) {
                    $matched[$id] = $parent;
                    $found = true;
                }
            }

            if ($found) {
                continue;
            }

            foreach ($parents as $id => $parent) {
                $shortname = trim((string)($parent->shortname ?? ''));
                if ($shortname === '') {
                    continue;
                }
                if (stripos($shortname, $level) !== false || stripos($level, $shortname) !== false) {
                    $matched[$id] = $parent;
                }
            }
        }

        return $matched;
    }

    /**
     * Reduces a level name to what it says, for comparing one against another.
     *
     * @param string $name The level or competency shortname.
     * @return string The comparison key.
     */
    private static function level_key(string $name): string {
        return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $name)));
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

        $normstrings = function ($rawval): array {
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

    /**
     * Check if a course module has content suitable for classification.
     *
     * Both this and the classification request itself ask
     * \aiplacement_competency\local\content\resolver, so the Classify button
     * cannot be offered for content that would then not be sent, or withheld
     * from content that would be.
     *
     * @param int $cmid Course module ID
     * @return bool True if the module has content, false otherwise
     */
    public static function has_module_content(int $cmid): bool {
        if (!$cmid) {
            return false;
        }

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return false;
        }

        return content\resolver::has_content($cm, \context_module::instance($cmid));
    }
}
