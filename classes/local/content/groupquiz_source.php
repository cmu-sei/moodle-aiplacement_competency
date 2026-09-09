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
 * Question text of a group quiz.
 *
 * Group quizzes do not use quiz slots. They keep their own link table,
 * groupquiz_questions, whose questionid points straight at the question version
 * the activity uses, and they order those links with the comma separated id
 * list in groupquiz.questionorder. This mirrors how
 * \mod_groupquiz\questionmanager loads questions, so the text sent for
 * classification is the text the activity presents.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class groupquiz_source extends source {
    #[\Override]
    public function get_content(): string {
        global $DB;

        $instance = $this->get_instance('id, questionorder');
        if (!$instance || trim((string)$instance->questionorder) === '') {
            return '';
        }

        $order = array_values(array_filter(array_map('intval', explode(',', $instance->questionorder))));
        if (empty($order)) {
            return '';
        }

        $links = $DB->get_records('groupquiz_questions', ['groupquizid' => $this->cm->instance], '', 'id, questionid');
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

    #[\Override]
    public function has_content(): bool {
        global $DB;

        return $DB->record_exists('groupquiz_questions', ['groupquizid' => $this->cm->instance]);
    }
}
