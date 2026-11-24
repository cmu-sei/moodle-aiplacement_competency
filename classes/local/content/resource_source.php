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
 * Text of the files in a file activity: transcripts, chapter lists, PDFs and text files.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resource_source extends source {
    #[\Override]
    public function get_content(): string {
        $out = '';
        foreach (self::files($this->context, 'mod_resource', 'content') as $file) {
            $text = filetext::read($file);
            $label = filetext::is_chapter_file($file->get_filename())
                ? 'Chapter list'
                : (filetext::is_vtt($file) ? 'Transcript' : 'File');
            $out .= self::chunk($label, str_replace(':', ' ', $file->get_filename()), $text);
        }

        return trim($out);
    }

    #[\Override]
    public function has_content(): bool {
        foreach (self::files($this->context, 'mod_resource', 'content') as $file) {
            if (filetext::is_readable($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The files in a file area, in name order.
     *
     * @param \context_module $context The activity context.
     * @param string $component The component that owns the files.
     * @param string $filearea The file area.
     * @return \stored_file[] The files, without folders.
     */
    public static function files(\context_module $context, string $component, string $filearea): array {
        return get_file_storage()->get_area_files(
            $context->id,
            $component,
            $filearea,
            false,
            'filepath, filename',
            false
        );
    }
}
