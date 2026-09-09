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
 * Pages of a lesson.
 *
 * Lesson pages hold the teaching content, and as with books none of it was
 * being sent even though the pages were counted when deciding whether to offer
 * classification. Pages are a linked list rather than an ordered column, so
 * they are walked from the page with no predecessor.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_source extends source {
    #[\Override]
    public function get_content(): string {
        global $DB;

        $pages = $DB->get_records(
            'lesson_pages',
            ['lessonid' => $this->cm->instance],
            'id ASC',
            'id, title, contents, prevpageid, nextpageid'
        );
        if (empty($pages)) {
            return '';
        }

        $content = '';
        $number = 0;

        foreach ($this->in_lesson_order($pages) as $page) {
            $number++;
            $content .= self::chunk("Page {$number}", $page->title, $page->contents ?? '');
        }

        return trim($content);
    }

    #[\Override]
    public function has_content(): bool {
        global $DB;

        return $DB->record_exists('lesson_pages', ['lessonid' => $this->cm->instance]);
    }

    /**
     * Walks the page list in the order the lesson presents it.
     *
     * Any page not reachable from the first one is returned afterwards, so a
     * lesson with a broken chain still contributes all of its text.
     *
     * @param array $pages Page records keyed by id.
     * @return array The pages in presentation order.
     */
    private function in_lesson_order(array $pages): array {
        $ordered = [];
        $seen = [];

        $first = null;
        foreach ($pages as $page) {
            if ((int)$page->prevpageid === 0) {
                $first = $page;
                break;
            }
        }

        $current = $first;
        while ($current !== null && !isset($seen[$current->id])) {
            $seen[$current->id] = true;
            $ordered[] = $current;
            $next = (int)$current->nextpageid;
            $current = $next > 0 && isset($pages[$next]) ? $pages[$next] : null;
        }

        foreach ($pages as $page) {
            if (!isset($seen[$page->id])) {
                $ordered[] = $page;
            }
        }

        return $ordered;
    }
}
