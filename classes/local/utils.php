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

                // Where a level named a competency, that competency's own shortname is
                // the better thing to put in front of the model than whatever casing
                // the caller happened to send.
                if (!empty($normlevels) && !empty($matchedparents)) {
                    $named = [];
                    foreach ($matchedparents as $parent) {
                        // Deliberately not $shortname: that is the framework's own, and
                        // reusing it here told the model the framework was called after
                        // the last matched level.
                        $parentname = trim((string)($parent->shortname ?? ''));
                        if ($parentname !== '') {
                            $named[] = $parentname;
                        }
                    }
                    if (!empty($named)) {
                        $displaylevels = $named;
                    }
                }

                // Keyed by competency id, so a competency reached twice is listed once.
                $competencylist = [];
                $linked = false;
                foreach ($matchedparents as $parent) {
                    // Get all child competencies under this parent.
                    $children = $DB->get_records('competency', [
                        'competencyframeworkid' => $frameworkid,
                        'parentid' => $parent->id,
                    ], 'shortname ASC');

                    foreach ($children as $child) {
                        $line = self::competency_line($child);
                        if ($line !== '') {
                            $competencylist[$child->id] = $line;
                        }
                    }

                    foreach (self::get_grouped_competencies($frameworkid, array_keys($children)) as $grouped) {
                        $line = self::competency_line($grouped);
                        if ($line !== '' && !isset($competencylist[$grouped->id])) {
                            $competencylist[$grouped->id] = $line;
                            $linked = true;
                        }
                    }
                }

                if (!empty($competencylist)) {
                    // The whole list, however long. Cutting it short used to leave the
                    // model to choose from whatever sorted first: the first 200 of
                    // NICE's 942 tasks, and no knowledge or skill at all when every
                    // level was selected.
                    $availablecompetencies = "\n\nAVAILABLE COMPETENCIES IN THIS FRAMEWORK/LEVEL:\n" .
                                           implode("\n", $competencylist) .
                                           "\n\nYou MUST only use competencies from the list above.";
                    if ($linked) {
                        $availablecompetencies .= " Some of them sit outside the selected levels in the framework," .
                            " but are listed because a competency in a selected level groups them, the way a work role" .
                            " groups its tasks, knowledge and skills. They count as part of the selected levels.";
                    }
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
     * collapsed key, which keeps it working for a caller that sends its own casing.
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
     * One competency as the model is shown it, "CODE - Name".
     *
     * A framework whose shortnames already carry the name, as NICE's
     * "T1406 - Construct cyber defense network tool signatures" does, is not
     * sent the description a second time.
     *
     * @param \stdClass $competency The competency record.
     * @return string The line, or an empty string for a competency with no shortname.
     */
    private static function competency_line(\stdClass $competency): string {
        $code = trim((string)($competency->shortname ?? ''));
        if ($code === '') {
            return '';
        }

        $name = trim(strip_tags((string)($competency->description ?? '')));
        if ($name === '') {
            $name = trim((string)($competency->idnumber ?? ''));
        }

        if ($name === '' || mb_stripos($code, $name) !== false) {
            return $code;
        }

        return $code . ' - ' . $name;
    }

    /**
     * The competencies that the given ones group through related competencies.
     *
     * A NICE work role is a competency whose related competencies are the
     * tasks, knowledge and skills the role is made of, and selecting the level
     * that holds the work roles means those, not the bare role names. A
     * competency counts as grouping others when it lists related competencies
     * and none lists it, which keeps a task, which the work roles list, from
     * pulling every work role it belongs to in after it.
     *
     * @param int $frameworkid The framework id.
     * @param int[] $competencyids The ids of a level's competencies.
     * @return \stdClass[] The grouped competencies in the same framework, by shortname.
     */
    private static function get_grouped_competencies(int $frameworkid, array $competencyids): array {
        global $DB;

        if (empty($competencyids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED);
        $params['frameworkid'] = $frameworkid;

        $sql = "SELECT DISTINCT c.id, c.shortname, c.description, c.idnumber
                  FROM {competency_relatedcomp} rc
                  JOIN {competency} c ON c.id = rc.relatedcompetencyid
                 WHERE rc.competencyid {$insql}
                   AND c.competencyframeworkid = :frameworkid
                   AND NOT EXISTS (
                           SELECT 1
                             FROM {competency_relatedcomp} up
                            WHERE up.relatedcompetencyid = rc.competencyid
                       )
              ORDER BY c.shortname";

        return array_values($DB->get_records_sql($sql, $params));
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
