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
 * Slide captions of a PPT Book.
 *
 * The captions are the only text an author writes about the slides, since the
 * slides themselves are images. They live in pptbook.captionsjson as a map of
 * image filename to caption, which is what mod_pptbook's own view page reads.
 *
 * The pptbook_item table looks like a better source but is not: nothing in the
 * activity writes it except a course restore, which also restores captionsjson,
 * so reading both would send every caption twice.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pptbook_source extends source {
    #[\Override]
    public function get_content(): string {
        $captions = $this->get_captions();

        $content = '';
        $number = 0;

        foreach ($captions as $caption) {
            $number++;
            $content .= self::chunk("Slide {$number}", null, $caption);
        }

        return trim($content);
    }

    /**
     * The activity's captions, in the order the slides are shown.
     *
     * @return string[] The non-empty captions, in slide order.
     */
    private function get_captions(): array {
        $instance = $this->get_instance('id, captionsjson');
        if (!$instance || trim((string)$instance->captionsjson) === '') {
            return [];
        }

        $decoded = json_decode((string)$instance->captionsjson, true);
        if (!is_array($decoded)) {
            return [];
        }

        // The view page sorts the slide images by filename with a natural
        // compare, so the captions are put back into that same order rather
        // than the order they happened to be saved in.
        // Cast first: a filename made only of digits comes back from
        // json_decode() as an integer key.
        $keys = array_map('strval', array_keys($decoded));
        usort($keys, 'strnatcasecmp');

        $captions = [];
        foreach ($keys as $key) {
            $caption = $decoded[$key];
            if (!is_string($caption) || self::clean($caption) === '') {
                continue;
            }
            $captions[] = $caption;
        }

        return $captions;
    }
}
