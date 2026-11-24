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
 * What a label teaches: the captions of its video, and its transcript.
 *
 * A lecture label holds an embedded video and almost no text. Its words are in
 * caption files attached to the label and in the file activities that sit next
 * to it in the section, which are read here.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class label_source extends source {
    #[\Override]
    public function get_content(): string {
        $out = '';

        foreach (resource_source::files($this->context, 'mod_label', 'intro') as $file) {
            $out .= self::chunk('Attachment', str_replace(':', ' ', $file->get_filename()), filetext::read($file));
        }

        foreach (transcripts::for_label($this->cm) as $pair) {
            $source = new resource_source($pair->get_course_module_record(), \context_module::instance($pair->id));
            $out .= $source->get_content() . "\n\n";
        }

        return trim($out);
    }

    #[\Override]
    public function has_content(): bool {
        foreach (resource_source::files($this->context, 'mod_label', 'intro') as $file) {
            if (filetext::is_readable($file)) {
                return true;
            }
        }

        return !empty(transcripts::for_label($this->cm));
    }
}
