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
 * Lab guide and questions of a TopoMojo activity.
 *
 * The content field holds the workspace document synced from the TopoMojo API.
 * The questions are held the way group quizzes hold theirs: a link table,
 * topomojo_questions, whose questionid points straight at the question version
 * the activity uses, ordered by the comma separated list of link ids in
 * topomojo.questionorder.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topomojo_source extends source {
    #[\Override]
    public function get_content(): string {
        $instance = $this->get_instance('id, content, questionorder');
        if (!$instance) {
            return '';
        }

        $parts = [];

        $guide = self::clean($instance->content);
        if ($guide !== '') {
            $parts[] = $guide;
        }

        $questions = $this->get_questions((string)$instance->questionorder);
        if ($questions !== '') {
            $parts[] = $questions;
        }

        return implode("\n\n", $parts);
    }

    #[\Override]
    public function has_content(): bool {
        global $DB;

        $instance = $this->get_instance('id, content');
        if ($instance && self::clean($instance->content) !== '') {
            return true;
        }

        return $DB->record_exists('topomojo_questions', ['topomojoid' => $this->cm->instance]);
    }

    /**
     * The activity's questions, in the order it presents them.
     *
     * @param string $questionorder The comma separated topomojo_questions ids.
     * @return string The question text, or an empty string when there is none.
     */
    private function get_questions(string $questionorder): string {
        global $DB;

        if (trim($questionorder) === '') {
            return '';
        }

        $order = array_values(array_filter(array_map('intval', explode(',', $questionorder))));
        if (empty($order)) {
            return '';
        }

        $links = $DB->get_records('topomojo_questions', ['topomojoid' => $this->cm->instance], '', 'id, questionid');
        if (empty($links)) {
            return '';
        }

        $content = '';
        $number = 0;

        foreach ($order as $linkid) {
            // Ids can outlive the link they name, so a stale order entry is skipped.
            if (!isset($links[$linkid])) {
                continue;
            }

            $question = $DB->get_record(
                'question',
                ['id' => $links[$linkid]->questionid],
                'id, name, qtype, questiontext'
            );
            if (!$question || $question->qtype === 'missingtype') {
                continue;
            }

            $chunk = self::chunk('Question ' . ($number + 1), $question->name, $question->questiontext ?? '');
            if ($chunk !== '') {
                $number++;
                $content .= $chunk;
            }
        }

        return trim($content);
    }
}
