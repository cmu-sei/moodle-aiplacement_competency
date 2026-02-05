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
        if ($frameworkid > 0) {
            try {
                // Get parent competencies (levels) that match the selected levels.
                $parentcompetencies = $DB->get_records('competency', [
                    'competencyframeworkid' => $frameworkid,
                    'parentid' => 0
                ]);

                $competencylist = [];
                foreach ($parentcompetencies as $parent) {
                    $parentshortname = trim($parent->shortname ?? '');

                    // Check if this parent matches one of the selected levels.
                    $matches = false;
                    foreach ($normlevels as $level) {
                        if (stripos($parentshortname, $level) !== false || stripos($level, $parentshortname) !== false) {
                            $matches = true;
                            break;
                        }
                    }

                    if ($matches || empty($normlevels)) {
                        // Get all child competencies under this parent.
                        $children = $DB->get_records('competency', [
                            'competencyframeworkid' => $frameworkid,
                            'parentid' => $parent->id
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
            'levels'            => implode(', ', $normlevels),
            'availablecompetencies' => $availablecompetencies,
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

    /**
     * Check if a course module has content suitable for classification.
     *
     * @param int $cmid Course module ID
     * @return bool True if the module has content, false otherwise
     */
    public static function has_module_content(int $cmid): bool {
        global $DB;

        if (!$cmid) {
            return false;
        }

        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (!$cm) {
            return false;
        }

        $modinfo = get_fast_modinfo($cm->course);
        $cminfo = $modinfo->get_cm($cmid);
        $modname = $cminfo->modname;

        // Get the module instance.
        $instance = $DB->get_record($modname, ['id' => $cm->instance]);
        if (!$instance) {
            return false;
        }

        // Check intro field (common to most activities).
        $hasintro = !empty($instance->intro) && trim(strip_tags($instance->intro)) !== '';

        // Check activity-specific content fields.
        $hasspecificcontent = false;

        switch ($modname) {
            case 'assign':
                // Assignment instructions.
                $hasspecificcontent = !empty($instance->intro) || !empty($instance->activity);
                break;

            case 'quiz':
                // Check if quiz has questions.
                $questioncount = $DB->count_records('quiz_slots', ['quizid' => $instance->id]);
                $hasspecificcontent = $questioncount > 0;
                break;

            case 'page':
                // Page content.
                $hasspecificcontent = !empty($instance->content) && trim(strip_tags($instance->content)) !== '';
                break;

            case 'book':
                // Check if book has chapters.
                $chaptercount = $DB->count_records('book_chapters', ['bookid' => $instance->id]);
                $hasspecificcontent = $chaptercount > 0;
                break;

            case 'forum':
                // Forum intro or discussions.
                $hasspecificcontent = $hasintro;
                break;

            case 'lesson':
                // Check if lesson has pages.
                $pagecount = $DB->count_records('lesson_pages', ['lessonid' => $instance->id]);
                $hasspecificcontent = $pagecount > 0;
                break;

            case 'workshop':
                // Workshop instructions.
                $hasspecificcontent = !empty($instance->instructauthors) ||
                                     !empty($instance->instructreviewers);
                break;

            case 'glossary':
            case 'wiki':
            case 'data':
                // These grow with user contributions, intro is sufficient.
                $hasspecificcontent = $hasintro;
                break;

            case 'resource':
            case 'url':
            case 'folder':
                // These have intro + files/links, intro is sufficient.
                $hasspecificcontent = $hasintro;
                break;

            case 'topomojo':
                // TopoMojo content is synced from TopoMojo API.
                $hasspecificcontent = !empty($instance->content) && trim(strip_tags($instance->content)) !== '';
                break;

            default:
                // For unknown activity types, check if intro exists.
                $hasspecificcontent = $hasintro;
                break;
        }

        return $hasintro || $hasspecificcontent;
    }
}
