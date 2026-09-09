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
 * Questions of a feedback activity.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_source extends source {
    /**
     * Item types that carry no text of their own.
     *
     * A page break has nothing to say, and a captcha's presentation holds the
     * number of characters to display.
     */
    private const SKIPPED_TYPES = ['pagebreak', 'captcha'];

    #[\Override]
    public function get_content(): string {
        global $DB;

        $items = $DB->get_records(
            'feedback_item',
            ['feedback' => $this->cm->instance],
            'position ASC, id ASC',
            'id, name, presentation, typ'
        );

        $content = '';
        $number = 0;

        foreach ($items as $item) {
            $typ = (string)$item->typ;
            if (in_array($typ, self::SKIPPED_TYPES, true)) {
                continue;
            }

            // A label item asks nothing: its text is the whole of it, and it is
            // held in presentation, which every other type uses for display
            // settings such as field widths.
            if ($typ === 'label') {
                $content .= self::chunk('Information', null, (string)$item->presentation);
                continue;
            }

            $body = self::clean($item->name);
            $options = self::get_options($typ, (string)$item->presentation);
            if ($options !== '') {
                $body = $body === '' ? $options : $body . ' Options: ' . $options;
            }
            if ($body === '') {
                continue;
            }

            $number++;
            $content .= self::chunk("Question {$number}", null, $body);
        }

        return trim($content);
    }

    /**
     * The answers a multiple choice item offers.
     *
     * @param string $typ The item type.
     * @param string $presentation The item's presentation field.
     * @return string The options, separated by semicolons, or an empty string.
     */
    private static function get_options(string $typ, string $presentation): string {
        if (!str_starts_with($typ, 'multichoice')) {
            return '';
        }

        // 'r>>>>>Yes|No|Maybe<<<<<1': the subtype, then the options, then
        // whether to lay them out horizontally.
        $parts = explode('>>>>>', $presentation);
        $list = explode('<<<<<', (string)array_pop($parts))[0];

        $options = [];
        foreach (explode('|', $list) as $option) {
            // A rated option carries the value it scores in front of its text,
            // as '0####Poor'.
            $rated = explode('####', $option, 2);
            $option = self::clean($rated[1] ?? $option);
            if ($option !== '') {
                $options[] = $option;
            }
        }

        return implode('; ', $options);
    }
}
