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
 * Chapters of a book.
 *
 * Chapters carry the whole of a book's teaching content, and until now none of
 * it was sent: the Classify button appeared because the chapters were counted,
 * but only the intro was classified. Hidden chapters are left out, since
 * students never see them.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_source extends source {
    #[\Override]
    public function get_content(): string {
        global $DB;

        $chapters = $DB->get_records(
            'book_chapters',
            ['bookid' => $this->cm->instance, 'hidden' => 0],
            'pagenum ASC, id ASC',
            'id, title, content, subchapter'
        );

        $content = '';
        $number = 0;

        foreach ($chapters as $chapter) {
            $number++;
            $label = empty($chapter->subchapter) ? "Chapter {$number}" : "Section {$number}";
            $content .= self::chunk($label, $chapter->title, $chapter->content ?? '');
        }

        return trim($content);
    }

    #[\Override]
    public function has_content(): bool {
        global $DB;

        return $DB->record_exists('book_chapters', ['bookid' => $this->cm->instance, 'hidden' => 0]);
    }
}
