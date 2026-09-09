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
 * Base class for activity content sources.
 *
 * A source knows how to read the saved, author-written text of one kind of
 * activity: the chapters of a book, the questions of a quiz, the tasks of a
 * Crucible exercise. Subclasses are discovered by name, so supporting a new
 * activity means adding one class here and nothing else.
 *
 * Sources deliberately return content beyond the activity intro. The intro is
 * handled once, centrally, because the drawer sends the value from the settings
 * form rather than the saved value so that unsaved edits are classified too.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class source {
    /**
     * Constructor.
     *
     * @param \stdClass $cm The course module record, as returned by get_coursemodule_from_id().
     * @param \context_module $context The context of the activity.
     */
    public function __construct(
        protected \stdClass $cm,
        protected \context_module $context
    ) {
    }

    /**
     * The activity's saved content, beyond its intro, as plain text.
     *
     * @return string The content, or an empty string when there is none.
     */
    abstract public function get_content(): string;

    /**
     * Whether the activity has content beyond its intro.
     *
     * Subclasses whose content is expensive to assemble should override this
     * with a cheaper probe, typically a row count, because this runs on every
     * activity settings page to decide whether to offer the Classify button.
     *
     * @return bool True if there is content to classify.
     */
    public function has_content(): bool {
        return $this->get_content() !== '';
    }

    /**
     * Reduces authored HTML to the plain text worth sending to a model.
     *
     * @param string|null $html The raw field value.
     * @return string The cleaned text.
     */
    protected static function clean(?string $html): string {
        $text = strip_tags((string)$html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/(\R\s*){2,}/u', "\n", (string)$text);

        return trim((string)$text);
    }

    /**
     * Formats one titled chunk of content.
     *
     * @param string $label The label for the chunk, such as "Chapter 2".
     * @param string|null $title The author's title for the chunk, if any.
     * @param string $body The chunk's text.
     * @return string The formatted chunk, or an empty string when the body is empty.
     */
    protected static function chunk(string $label, ?string $title, string $body): string {
        $body = self::clean($body);
        if ($body === '') {
            return '';
        }

        $title = self::clean($title);
        $heading = $title === '' ? $label : "{$label} ({$title})";

        return $heading . ': ' . $body . "\n\n";
    }

    /**
     * Reads the activity's own database row.
     *
     * @param string $fields Comma separated list of fields to read.
     * @return \stdClass|false The record, or false when it has gone.
     */
    protected function get_instance(string $fields = '*') {
        global $DB;

        return $DB->get_record($this->cm->modname, ['id' => $this->cm->instance], $fields);
    }
}
