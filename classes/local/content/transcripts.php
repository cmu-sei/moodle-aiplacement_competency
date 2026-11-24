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

namespace aiplacement_competency\local\content;

/**
 * Finds the transcript activities that belong to a video.
 *
 * A lecture is a label holding the embedded video, followed in its section by
 * file activities holding the .vtt transcript and chapter files. The label has
 * no text of its own, so its content is read from those files, and the tags it
 * receives belong to them too.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class transcripts {
    /**
     * The file activities holding captions that sit with a label in its section.
     *
     * Looks forward to the next label, which is the next lecture, and only
     * when nothing is there looks backward to the previous one.
     *
     * @param \stdClass $label The label's course module record.
     * @return \cm_info[] The file activities with a .vtt file, in section order.
     */
    public static function for_label(\stdClass $label): array {
        $modinfo = get_fast_modinfo($label->course);
        $sectionnum = $modinfo->get_cm($label->id)->sectionnum;
        $ids = $modinfo->sections[$sectionnum] ?? [];

        $position = array_search((int)$label->id, array_map('intval', $ids), true);
        if ($position === false) {
            return [];
        }

        $after = self::scan($modinfo, array_slice($ids, $position + 1));
        if (!empty($after)) {
            return $after;
        }

        return array_reverse(self::scan($modinfo, array_reverse(array_slice($ids, 0, $position))));
    }

    /**
     * The labels a transcript activity belongs to, as the inverse of for_label().
     *
     * @param \stdClass $resource The file activity's course module record.
     * @return \cm_info[] The labels that have this activity as a transcript.
     */
    public static function labels_for(\stdClass $resource): array {
        $modinfo = get_fast_modinfo($resource->course);
        $ids = $modinfo->sections[$modinfo->get_cm($resource->id)->sectionnum] ?? [];

        $labels = [];
        foreach ($ids as $id) {
            $cm = $modinfo->get_cm((int)$id);
            if ($cm->modname !== 'label') {
                continue;
            }
            foreach (self::for_label($cm->get_course_module_record()) as $pair) {
                if ((int)$pair->id === (int)$resource->id) {
                    $labels[] = $cm;
                }
            }
        }

        return $labels;
    }

    /**
     * Walks course module ids until the next label, collecting caption file activities.
     *
     * @param \course_modinfo $modinfo The course's module info.
     * @param array $ids The course module ids to walk, nearest first.
     * @return \cm_info[] The caption activities found.
     */
    private static function scan(\course_modinfo $modinfo, array $ids): array {
        $found = [];
        foreach ($ids as $id) {
            $cm = $modinfo->get_cm((int)$id);
            if ($cm->modname === 'label') {
                break;
            }
            if ($cm->modname === 'resource' && self::has_captions($cm)) {
                $found[] = $cm;
            }
        }

        return $found;
    }

    /**
     * Whether a file activity holds a .vtt file.
     *
     * @param \cm_info $cm The file activity.
     * @return bool True when it holds captions.
     */
    private static function has_captions(\cm_info $cm): bool {
        foreach (resource_source::files(\context_module::instance($cm->id), 'mod_resource', 'content') as $file) {
            if (filetext::is_vtt($file)) {
                return true;
            }
        }

        return false;
    }
}
