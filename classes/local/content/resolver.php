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
 * Finds and applies the content source for an activity.
 *
 * This is the single place that decides what text an activity contributes to a
 * classification request. Both the request path and the check that decides
 * whether to offer the Classify button go through here, so the two can no
 * longer disagree about which activities have content.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolver {
    /**
     * Ceiling on the characters of activity content added to one prompt.
     *
     * A book or lesson can hold far more text than a model will accept, and the
     * provider rejects the whole request rather than truncating it. Cut on a
     * word boundary and say so, rather than sending something that fails.
     */
    public const MAX_CONTENT_LENGTH = 20000;

    /**
     * Returns the content source for an activity, or null when it has none.
     *
     * Sources are found by name: mod_book is served by book_source. A module
     * with no class here contributes its intro and nothing more.
     *
     * @param \stdClass $cm The course module record.
     * @param \context_module $context The context of the activity.
     * @return source|null The source, or null when the module has no source.
     */
    public static function get_source(\stdClass $cm, \context_module $context): ?source {
        $class = __NAMESPACE__ . '\\' . $cm->modname . '_source';

        if (!class_exists($class) || !is_subclass_of($class, source::class)) {
            return null;
        }

        return new $class($cm, $context);
    }

    /**
     * Returns the activity's content, beyond its intro, ready to send.
     *
     * @param \stdClass $cm The course module record.
     * @param \context_module $context The context of the activity.
     * @return string The content, truncated to MAX_CONTENT_LENGTH.
     */
    public static function get_content(\stdClass $cm, \context_module $context): string {
        $source = self::get_source($cm, $context);
        if ($source === null) {
            return '';
        }

        return self::truncate($source->get_content());
    }

    /**
     * Whether the activity has anything worth classifying.
     *
     * @param \stdClass $cm The course module record.
     * @param \context_module $context The context of the activity.
     * @return bool True when the activity has an intro or source content.
     */
    public static function has_content(\stdClass $cm, \context_module $context): bool {
        if (self::get_intro($cm) !== '') {
            return true;
        }

        $source = self::get_source($cm, $context);

        return $source !== null && $source->has_content();
    }

    /**
     * Adds an activity's content to the prompt the drawer sent.
     *
     * The drawer sends the intro as it stands in the settings form, which may
     * hold unsaved edits, so whatever it sent is kept as written. Saved content
     * is appended, and anything the drawer already sent is not repeated. When
     * the drawer sent nothing at all, which is what happens for any caller that
     * is not sitting on the settings form, the saved intro is used instead.
     *
     * @param string $prompttext The prompt text as received from the caller.
     * @param \stdClass $cm The course module record.
     * @param \context_module $context The context of the activity.
     * @return string The prompt text to classify.
     */
    public static function augment_prompt(string $prompttext, \stdClass $cm, \context_module $context): string {
        $parts = [];

        $prompttext = trim($prompttext);
        if ($prompttext === '') {
            $prompttext = self::get_intro($cm);
        }
        if ($prompttext !== '') {
            $parts[] = $prompttext;
        }

        $content = self::get_content($cm, $context);
        if ($content !== '' && !self::already_present($prompttext, $content)) {
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * The activity's saved intro as plain text.
     *
     * @param \stdClass $cm The course module record.
     * @return string The intro, or an empty string when there is none.
     */
    public static function get_intro(\stdClass $cm): string {
        global $DB;

        $intro = $DB->get_field($cm->modname, 'intro', ['id' => $cm->instance], IGNORE_MISSING);
        if ($intro === false || $intro === null) {
            return '';
        }

        return trim(strip_tags((string)$intro));
    }

    /**
     * Whether the prompt already carries the content, ignoring whitespace.
     *
     * The drawer scrapes some fields from the settings form, so for a saved
     * activity the same text can arrive twice. Sending it twice wastes prompt
     * budget and overweights whatever is duplicated.
     *
     * @param string $prompttext The prompt text so far.
     * @param string $content The content about to be appended.
     * @return bool True when the content is already in the prompt.
     */
    private static function already_present(string $prompttext, string $content): bool {
        $normalise = static function (string $text): string {
            return strtolower((string)preg_replace('/\s+/u', ' ', $text));
        };

        return str_contains($normalise($prompttext), $normalise($content));
    }

    /**
     * Cuts content to the prompt budget on a word boundary.
     *
     * @param string $content The content to truncate.
     * @return string The content, no longer than MAX_CONTENT_LENGTH.
     */
    private static function truncate(string $content): string {
        if (\core_text::strlen($content) <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }

        $cut = \core_text::substr($content, 0, self::MAX_CONTENT_LENGTH);
        $lastspace = \core_text::strrpos($cut, ' ');
        if ($lastspace !== false && $lastspace > 0) {
            $cut = \core_text::substr($cut, 0, $lastspace);
        }

        return rtrim($cut) . "\n\n[content truncated]";
    }
}
