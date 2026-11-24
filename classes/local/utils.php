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

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS"
BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER
INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM
USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM
PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.

Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact
permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see
Copyright notice for non-US Government use and distribution.

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
    /** The most competencies shown the model at once. Longer lists are narrowed to the best matches. */
    public const DEFAULT_MAX_CANDIDATES = 80;

    /**
     * Build the instruction text for the model.
     *
     * @param int $frameworkid The framework id.
     * @param string $shortname The framework shortname.
     * @param array $levels The selected level names.
     * @param string $text The content being classified, used to narrow a long list of competencies.
     * @return string The instruction.
     */
    public static function build_instruction(int $frameworkid, string $shortname, array $levels, string $text = ''): string {
        return self::prepare($frameworkid, $shortname, $levels, $text)['instruction'];
    }

    /**
     * Builds the instruction and works out which competencies the model may choose from.
     *
     * @param int $frameworkid The framework id.
     * @param string $shortname The framework shortname.
     * @param array $levels The selected level names.
     * @param string $text The content being classified. Narrows the list when it is long,
     *                     and is searched for codes the activity's author already stated.
     * @param string $title The activity's title, empty when there is none.
     * @return array With 'instruction' (string) and 'candidates' (competencies by id,
     *               each with a 'line' property), the ones the model was shown.
     */
    public static function prepare(
        int $frameworkid,
        string $shortname,
        array $levels,
        string $text = '',
        string $title = ''
    ): array {
        $normlevels = self::normalise_levels($levels);
        $set = self::get_candidates($frameworkid, $normlevels);
        $candidates = $set['candidates'];

        $max = (int)get_config('aiplacement_competency', 'maxcandidates');
        if ($max <= 0) {
            $max = self::DEFAULT_MAX_CANDIDATES;
        }
        $total = count($candidates);
        $candidates = narrower::top($candidates, $text, $max);

        $availablecompetencies = '';
        if (!empty($candidates)) {
            $lines = [];
            foreach ($candidates as $candidate) {
                $lines[] = '[' . $candidate->idnumber . '] ' . $candidate->line;
            }
            $availablecompetencies = "\n\nAVAILABLE COMPETENCIES (one per line, as [idnumber] name):\n" .
                implode("\n", $lines) .
                "\n\nYou MUST only use idnumbers from the list above.";
            if ($total > count($candidates)) {
                $availablecompetencies .= " The list is the " . count($candidates) . " closest matches to the content," .
                    " chosen from {$total} in the selected levels.";
            }
            if ($set['linked']) {
                $availablecompetencies .= " Some of them sit outside the selected levels in the framework," .
                    " but are listed because a competency in a selected level groups them, the way a work role" .
                    " groups its tasks, knowledge and skills. They count as part of the selected levels.";
            }

            $stated = self::stated_codes($candidates, $text);
            if (!empty($stated)) {
                $availablecompetencies .= "\n\nCODES NAMED IN THE CONTENT ITSELF: " . implode(', ', $stated) .
                    ". If the content presents these as the activity's own mapping, the author's mapping is the" .
                    " strongest evidence there is: include them. If it only mentions them in passing, do not.";
            }
        }

        $a = (object)[
            'frameworkid'        => $frameworkid,
            'frameworkshortname' => $shortname,
            'levels'            => implode(', ', $set['displaylevels']),
            'availablecompetencies' => $availablecompetencies,
            'maxtags'            => self::max_tags(),
            'activitytitle'      => trim($title),
        ];

        return [
            'instruction' => get_string('action_classify_text_instruction', 'aiplacement_competency', $a),
            'candidates' => $candidates,
        ];
    }

    /**
     * The framework the administrator chose as the default, by its idnumber or shortname.
     *
     * The idnumber is tried first, since it is meant to be unique, then the shortname, with case ignored. A hidden
     * framework is not offered by the drawer, so it is not a default either.
     *
     * @return int The framework id, or 0 when none is set or none matches.
     */
    public static function default_framework_id(): int {
        global $DB;

        $wanted = \core_text::strtolower(trim((string)get_config('aiplacement_competency', 'defaultframework')));
        if ($wanted === '') {
            return 0;
        }

        // Two small queries, the idnumber first, with the lowest id winning if a shortname is shared.
        foreach (['idnumber', 'shortname'] as $field) {
            $found = $DB->get_records_select(
                'competency_framework',
                'visible = 1 AND ' . $DB->sql_equal($field, ':wanted', false, false),
                ['wanted' => $wanted],
                'id ASC',
                'id',
                0,
                1
            );
            if ($found) {
                return (int)array_key_first($found);
            }
        }

        return 0;
    }

    /**
     * The most competencies one classification should return per framework.
     *
     * @return int The cap, at least one.
     */
    public static function max_tags(): int {
        $max = (int)get_config('aiplacement_competency', 'maxtags');

        return $max > 0 ? $max : 3;
    }

    /**
     * Trims and de-duplicates the level names a caller sent.
     *
     * @param array $levels The level names.
     * @return string[] The cleaned names.
     */
    private static function normalise_levels(array $levels): array {
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

        return $normlevels;
    }

    /**
     * The competencies of a framework that the selected levels cover.
     *
     * @param int $frameworkid The framework id.
     * @param string[] $normlevels The selected level names, already cleaned.
     * @return array With 'candidates' (competencies by id, each with a 'line'), 'displaylevels'
     *               (the level names to show) and 'linked' (whether any were reached through a grouping).
     */
    public static function get_candidates(int $frameworkid, array $normlevels): array {
        global $DB;

        $competencylist = [];
        $displaylevels = $normlevels;
        $linked = false;

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
                foreach ($matchedparents as $parent) {
                    $children = $DB->get_records('competency', [
                        'competencyframeworkid' => $frameworkid,
                        'parentid' => $parent->id,
                    ], 'shortname ASC');

                    foreach ($children as $child) {
                        $line = self::competency_line($child);
                        if ($line !== '') {
                            $child->line = $line;
                            $competencylist[$child->id] = $child;
                        }
                    }

                    foreach (self::get_grouped_competencies($frameworkid, array_keys($children)) as $grouped) {
                        $line = self::competency_line($grouped);
                        if ($line !== '' && !isset($competencylist[$grouped->id])) {
                            $grouped->line = $line;
                            $competencylist[$grouped->id] = $grouped;
                            $linked = true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // If there's an error fetching competencies, continue without the list.
                debugging('Failed to fetch competencies for AI instruction: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return ['candidates' => $competencylist, 'displaylevels' => $displaylevels, 'linked' => $linked];
    }

    /**
     * Codes of the offered competencies that the content itself contains.
     *
     * Lab authors sometimes publish their own task mapping alongside the lab.
     * Spotting the codes lets the prompt call that out as the strongest evidence.
     *
     * @param \stdClass[] $candidates The offered competencies.
     * @param string $text The content.
     * @return string[] The idnumbers found.
     */
    private static function stated_codes(array $candidates, string $text): array {
        if (trim($text) === '') {
            return [];
        }
        $lower = \core_text::strtolower($text);
        $found = [];
        foreach ($candidates as $candidate) {
            $code = narrower::code($candidate);
            if ($code !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($code, '/') . '(?![a-z0-9])/u', $lower)) {
                $found[] = $candidate->idnumber;
            }
        }

        return $found;
    }

    /**
     * Turns what the model returned into competencies the framework really has.
     *
     * The model is asked for idnumbers, but may give "CODE - Name" or a bare name.
     * Anything that is not one of the competencies it was offered is dropped,
     * rather than trusting the prompt's "only use these".
     *
     * @param array $returned The model's list: idnumber strings, "CODE - Name" strings,
     *                        or arrays with 'idnumber' and optionally 'reason'.
     * @param \stdClass[] $candidates The competencies the model was offered, by id.
     * @return array[] Each with 'id', 'idnumber', 'label' (the "CODE - Name" line) and 'reason'.
     */
    public static function resolve_competencies(array $returned, array $candidates): array {
        $index = [];
        foreach ($candidates as $candidate) {
            foreach ([$candidate->idnumber ?? '', $candidate->shortname ?? '', $candidate->line ?? ''] as $key) {
                $key = self::level_key((string)$key);
                if ($key !== '' && !isset($index[$key])) {
                    $index[$key] = $candidate;
                }
            }
        }

        $resolved = [];
        foreach ($returned as $item) {
            $reason = '';
            if (is_array($item)) {
                $reason = trim((string)($item['reason'] ?? ''));
                $item = $item['idnumber'] ?? ($item['id'] ?? ($item['competency'] ?? ''));
            }
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $item = trim($item, "[] \t");

            $candidate = $index[self::level_key($item)] ?? null;
            if ($candidate === null) {
                // The model gave "CODE - Name": try the code on its own.
                $code = trim(explode(' - ', $item, 2)[0], "[] \t");
                $candidate = $index[self::level_key($code)] ?? null;
            }
            if ($candidate === null || isset($resolved[$candidate->id])) {
                continue;
            }

            $resolved[$candidate->id] = [
                'id' => (int)$candidate->id,
                'idnumber' => (string)$candidate->idnumber,
                'label' => (string)$candidate->line,
                'reason' => clean_param($reason, PARAM_TEXT),
            ];
        }

        return array_values($resolved);
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
     * than a shortname, and only when it picks out a single group. On its own it matches far too much, because a short level
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

            // A level that is no shortname is matched as part of one only when it
            // picks out a single top level group. 'IT' sits inside both 'Monitoring'
            // and 'Security', and guessing between them offers the model competencies
            // of a level nobody selected.
            $partial = [];
            foreach ($parents as $id => $parent) {
                $shortname = trim((string)($parent->shortname ?? ''));
                if ($shortname === '') {
                    continue;
                }
                if (stripos($shortname, $level) !== false || stripos($level, $shortname) !== false) {
                    $partial[$id] = $parent;
                }
            }
            if (count($partial) === 1) {
                $matched += $partial;
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
