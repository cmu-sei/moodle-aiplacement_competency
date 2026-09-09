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

namespace aiplacement_competency\local\content;

/**
 * Assignable units of a cmi5 course.
 *
 * The titles and descriptions come from the course package's own manifest, so
 * they describe the learning content itself rather than how it was set up in
 * Moodle. They are read from the JSON on the activity row: the cmi5launch_aus
 * table holds one row per user and attempt, so reading it would depend on who
 * has launched the course and how often.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cmi5launch_source extends source {
    #[\Override]
    public function get_content(): string {
        $instance = $this->get_instance('id, aus, courseinfo');
        if (!$instance) {
            return '';
        }

        $content = '';
        $number = 0;

        foreach ($this->get_aus($instance) as $au) {
            $title = self::langstring($au['title'] ?? null);
            $description = self::langstring($au['description'] ?? null);
            if ($title === '' && $description === '') {
                continue;
            }

            $number++;
            $content .= $description === ''
                ? self::chunk("Assignable unit {$number}", null, $title)
                : self::chunk("Assignable unit {$number}", $title, $description);
        }

        return trim($content);
    }

    /**
     * The course's assignable units, one entry each.
     *
     * @param \stdClass $instance The activity row, with aus and courseinfo.
     * @return array[] The decoded assignable units.
     */
    private function get_aus(\stdClass $instance): array {
        $aus = self::flatten(json_decode((string)$instance->aus, true));
        if (empty($aus)) {
            // Older activities were saved before aus was split out, and the
            // same units are in the course metadata.
            $courseinfo = json_decode((string)$instance->courseinfo, true);
            $aus = self::flatten($courseinfo['metadata']['aus'] ?? null);
        }

        // A unit reachable from more than one block appears once per block,
        // and auIndex is what identifies it within the course.
        $unique = [];
        foreach ($aus as $index => $au) {
            $key = $au['auIndex'] ?? $au['auindex'] ?? $au['id'] ?? $index;
            if (!is_int($key) && !is_string($key)) {
                $key = $index;
            }
            if (array_key_exists($key, $unique)) {
                continue;
            }
            $unique[$key] = $au;
        }

        return array_values($unique);
    }

    /**
     * Pulls the assignable units out of the nesting the package puts them in.
     *
     * A cmi5 course groups its units into blocks, so aus arrives as a list of
     * lists whose depth follows the package rather than anything predictable.
     *
     * @param mixed $items The decoded JSON to walk.
     * @return array[] The units found, in the order they appear.
     */
    private static function flatten($items): array {
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (array_key_exists('title', $item) || array_key_exists('auIndex', $item)) {
                $out[] = $item;
                continue;
            }
            foreach (self::flatten($item) as $nested) {
                $out[] = $nested;
            }
        }

        return $out;
    }

    /**
     * Reads one text out of a cmi5 language map.
     *
     * Titles and descriptions are lists of {lang, text} pairs. English is
     * preferred because the instruction sent with the prompt is English, but
     * any language says more about the activity than nothing does.
     *
     * @param mixed $value The language map, or a plain string.
     * @return string The text, or an empty string when there is none.
     */
    private static function langstring($value): string {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (!is_array($value)) {
            return '';
        }

        $fallback = '';
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['text']) || !is_string($entry['text'])) {
                continue;
            }

            $text = self::clean($entry['text']);
            if ($text === '') {
                continue;
            }
            if (str_starts_with(strtolower((string)($entry['lang'] ?? '')), 'en')) {
                return $text;
            }
            if ($fallback === '') {
                $fallback = $text;
            }
        }

        return $fallback;
    }
}
