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
 * Reads the text out of files that activities hold.
 *
 * Lecture transcripts and chapter lists are WebVTT files, handouts are PDFs
 * or plain text. Everything else, images and video included, has no text to read.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class filetext {
    /** Files bigger than this are not read. A transcript of a whole day is well under it. */
    private const MAX_BYTES = 20 * 1024 * 1024;

    /** Extensions whose content is read directly. */
    private const PLAIN = ['txt', 'md', 'html', 'htm', 'csv'];

    /**
     * Whether this plugin can get text out of the file.
     *
     * Cheap: looks only at the name, so it can run on every settings page.
     *
     * @param \stored_file $file The file.
     * @return bool True when it is a kind of file with readable text.
     */
    public static function is_readable(\stored_file $file): bool {
        if ($file->is_directory() || resolver::is_solution_file($file->get_filename())) {
            return false;
        }
        $ext = self::extension($file->get_filename());
        if ($ext === 'pdf') {
            return self::pdftotext() !== '';
        }

        return $ext === 'vtt' || in_array($ext, self::PLAIN, true);
    }

    /**
     * Whether the file is a WebVTT caption, transcript or chapter file.
     *
     * @param \stored_file $file The file.
     * @return bool True for .vtt files.
     */
    public static function is_vtt(\stored_file $file): bool {
        return !$file->is_directory() && self::extension($file->get_filename()) === 'vtt';
    }

    /**
     * Returns the text of a file.
     *
     * @param \stored_file $file The file.
     * @return string The text, or an empty string when it cannot be read.
     */
    public static function read(\stored_file $file): string {
        if (!self::is_readable($file) || $file->get_filesize() > self::MAX_BYTES) {
            return '';
        }

        $ext = self::extension($file->get_filename());
        if ($ext === 'pdf') {
            return self::read_pdf($file);
        }

        $raw = $file->get_content();
        if ($ext === 'vtt') {
            return self::parse_vtt($raw, self::is_chapter_file($file->get_filename()));
        }
        if ($ext === 'html' || $ext === 'htm') {
            return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return trim($raw);
    }

    /**
     * Turns WebVTT into running text.
     *
     * Headers, notes, cue numbers, timings and styling tags go. Rolling
     * captions repeat the previous line, so a line that repeats is dropped.
     * A chapter file has its cue text as chapter titles, which are listed.
     *
     * @param string $vtt The file's content.
     * @param bool $chapters Whether this is a chapter file.
     * @return string The text.
     */
    public static function parse_vtt(string $vtt, bool $chapters = false): string {
        $vtt = preg_replace('/^\xEF\xBB\xBF/', '', $vtt);
        $blocks = preg_split('/\R{2,}/u', trim((string)$vtt)) ?: [];

        $lines = [];
        $last = '';
        foreach ($blocks as $block) {
            $first = trim(strtok($block, "\n") ?: '');
            if (preg_match('/^(WEBVTT|NOTE|STYLE|REGION)\b/', $first)) {
                continue;
            }

            foreach (preg_split('/\R/u', $block) ?: [] as $line) {
                $line = trim($line);
                // Timings, and the optional numeric cue identifier before them.
                if ($line === '' || str_contains($line, '-->') || ctype_digit($line)) {
                    continue;
                }
                $line = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($line === '' || $line === $last) {
                    continue;
                }
                $lines[] = $line;
                $last = $line;
            }
        }

        if ($chapters) {
            return $lines ? 'Chapters: ' . implode('; ', $lines) : '';
        }

        return preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '';
    }

    /**
     * Whether a file name is that of a chapter list: lecture.chp.vtt.
     *
     * @param string $filename The file name.
     * @return bool True for chapter files.
     */
    public static function is_chapter_file(string $filename): bool {
        return (bool)preg_match('/\.chp\.vtt$|chapters?\.vtt$/i', $filename);
    }

    /**
     * Reads a PDF with pdftotext.
     *
     * @param \stored_file $file The file.
     * @return string The text, or an empty string when the program failed.
     */
    private static function read_pdf(\stored_file $file): string {
        $program = self::pdftotext();
        if ($program === '') {
            return '';
        }

        $tmp = $file->copy_content_to_temp();
        if ($tmp === false) {
            return '';
        }

        $output = [];
        $status = 1;
        // Arguments are not user input: a configured path and a temp file Moodle named.
        exec(escapeshellarg($program) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($tmp) . ' - 2>/dev/null', $output, $status);
        @unlink($tmp);

        return $status === 0 ? trim((string)preg_replace('/[ \t]+/u', ' ', implode("\n", $output))) : '';
    }

    /**
     * The configured pdftotext program, if it exists.
     *
     * @return string The path, or an empty string.
     */
    private static function pdftotext(): string {
        $path = trim((string)get_config('aiplacement_competency', 'pdftotext'));

        return $path !== '' && is_executable($path) ? $path : '';
    }

    /**
     * The lower case extension of a file name.
     *
     * @param string $filename The file name.
     * @return string The extension, without the dot.
     */
    private static function extension(string $filename): string {
        return \core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
