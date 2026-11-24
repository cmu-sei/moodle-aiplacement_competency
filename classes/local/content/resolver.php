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
     * Ceiling on the characters of activity content read for one classification.
     *
     * The classifier sends content to the model in sections, so a long lecture
     * transcript is read whole. This is only a guard against a runaway file, and
     * is far beyond any real transcript or chapter set.
     */
    public const MAX_CONTENT_LENGTH = 400000;

    /**
     * Words in a heading that mark a solution guide or walkthrough.
     *
     * Models stop on these: CTF solution guides read as attack instructions.
     * They teach nothing the lab's own tasks do not, so they are not sent.
     */
    private const SOLUTION_PATTERN =
        '/\b(solutions?|walk[- ]?throughs?|answer[ _-]?keys?|write[- ]?ups?|spoilers?|cheat[ _-]?sheets?)\b/iu';

    /**
     * A line that is nothing but a solution heading: 'Solution guide', 'Lab walkthrough', 'Answer key'.
     *
     * Deliberately narrow. 'Endpoint Security Solutions' and 'Design a solution for the network' are about
     * solutions, and cutting the rest of a description at them would lose real content.
     */
    private const HEADING_PATTERN = '/^\W*' .
        '(?:(?:lab|ctf|full|complete|instructors?|exercise|task|challenge|part|step|question|problem|level|detailed|' .
        'step[- ]by[- ]step|\#?\d+)\s+)*' .
        '(?:solutions?|walk[- ]?throughs?|answer[ _-]?keys?|write[- ]?ups?|spoilers?|cheat[ _-]?sheets?)' .
        '(?:\s+(?:guide|guides|notes|steps|sheet|key|manual|only|section)|' .
        '\s+to\s+(?:(?:lab|task|challenge|exercise|question|problem)\s+)?\#?\d+)?\W*$/iu';

    /**
     * The words that open a guide even with its first sentence after the colon: 'Walkthrough: start by...'.
     */
    private const LEADIN_PATTERN = '/^\W*(?:(?:lab|ctf|full|complete|instructors?|exercise|task|challenge|\#?\d+)\s+)*' .
        '(?:walk[- ]?throughs?|answer[ _-]?keys?|spoilers?|solutions?\s+(?:guide|guides|notes|steps|manual|key))\W*$/iu';

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

        return self::truncate(self::drop_solutions($source->get_content(), true));
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
        // Whatever the caller sent may hold a solution section pasted into the description.
        $prompttext = trim(self::drop_solutions($prompttext));
        if ($prompttext !== '') {
            $parts[] = $prompttext;
        }

        $source = self::get_source($cm, $context);
        if ($source !== null) {
            // Dropped before truncating, so the prompt budget is spent on
            // content the caller has not already sent.
            $content = self::truncate(self::drop_solutions(self::strip_duplicates($prompttext, $source->get_content()), true));
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Removes the chunks of content that are solution guides.
     *
     * A chunk reads 'Heading (Title): body', so a solution is recognised by its heading.
     *
     * @param string $content The content.
     * @return string The content without solution guides.
     */
    public static function drop_solutions(string $content, bool $labelled = false): string {
        if ($content === '') {
            return $content;
        }

        if ($labelled) {
            return self::drop_labelled_solutions($content);
        }

        $withoutsections = self::drop_solution_html($content);
        $content = $withoutsections === $content ? $content : rtrim($withoutsections);

        // Lines, not blank line paragraphs: a description is usually written without blank lines, and as markup it
        // arrives as <p> after <p>. Each block closing tag ends a line.
        $lines = preg_split(
            '/\R|(?<=<\/p>|<\/div>|<\/li>|<\/h1>|<\/h2>|<\/h3>|<\/h4>|<\/h5>|<\/h6>|<br>|<br\/>|<br \/>)/iu',
            $content
        ) ?: [$content];

        foreach ($lines as $i => $line) {
            if (self::is_solution_heading($line)) {
                // A guide is written last and runs on past its heading, with sub headings of its own, so
                // once one starts the rest of this text goes. Losing a section after it costs less than sending it.
                return trim(implode("\n", array_slice($lines, 0, $i)));
            }
        }

        return $content;
    }

    /**
     * Drops the chunks of a content source whose label says they are a solution.
     *
     * A source writes 'Label (Title): body' chunks separated by blank lines, and each stands alone. Only the label
     * and the title are judged, each as a heading: a chapter called 'Endpoint Security Solutions' is kept.
     *
     * @param string $content The content a source returned.
     * @return string The content without those chunks.
     */
    private static function drop_labelled_solutions(string $content): string {
        $kept = [];
        foreach (preg_split('/\R{2,}/u', $content) ?: [] as $chunk) {
            $label = self::source_label($chunk);
            if ($label !== null) {
                $parts = [trim(preg_replace('/\([^)]*\)/u', '', $label)), ...self::parenthesised($label)];
                foreach ($parts as $part) {
                    if (preg_match(self::HEADING_PATTERN, $part)) {
                        continue 2;
                    }
                }
            }
            $kept[] = $chunk;
        }

        return implode("\n\n", $kept);
    }

    /**
     * The text inside the brackets of a label.
     *
     * @param string $label The label, such as 'Chapter 2 (Solution guide)'.
     * @return string[] The bracketed parts.
     */
    private static function parenthesised(string $label): array {
        preg_match_all('/\(([^)]*)\)/u', $label, $m);

        return array_map('trim', $m[1]);
    }

    /**
     * Removes HTML sections that open with a solution heading.
     *
     * A description scraped from the editor can arrive as one line of markup, so the heading is found by its tag. A
     * heading tag's section runs to the next heading of the same or a higher level. A bold lead-in such as
     * '<p><strong>Walkthrough:</strong> start by...' has no level, so its section runs to the end.
     *
     * @param string $content The content.
     * @return string The content without those sections.
     */
    private static function drop_solution_html(string $content): string {
        $cuts = [];

        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/isu', $content, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($found as $i => $heading) {
                if (!preg_match(self::HEADING_PATTERN, self::heading_text($heading[2][0]))) {
                    continue;
                }
                $end = strlen($content);
                for ($j = $i + 1; $j < count($found); $j++) {
                    if ((int)$found[$j][1][0] <= (int)$heading[1][0]) {
                        $end = $found[$j][0][1];
                        break;
                    }
                }
                $cuts[] = [$heading[0][1], $end];
            }
        }

        // A paragraph that opens in bold. When the bold is the whole paragraph it is a heading, and when text follows it
        // is a lead-in such as 'Solution: use parameterised queries', which only the guide words count as.
        $boldpattern = '/<p\b[^>]*>\s*<(strong|b)\b[^>]*>(.*?)<\/\1>(.*?)<\/p>/isu';
        if (preg_match_all($boldpattern, $content, $bold, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($bold as $i => $para) {
                $text = self::heading_text($para[2][0]);
                $hasbody = self::heading_text($para[3][0]) !== '';
                if ($hasbody ? !preg_match(self::LEADIN_PATTERN, rtrim($text, ': ')) : !preg_match(self::HEADING_PATTERN, $text)) {
                    continue;
                }
                $end = strlen($content);
                if (!$hasbody && !preg_match(self::LEADIN_PATTERN, $text)) {
                    // A bare 'Solution' is as likely a lesson's own sub heading as a guide: it runs to the next heading.
                    $end = self::next_heading($content, $para[0][1] + strlen($para[0][0]), $bold, $i);
                }
                $cuts[] = [$para[0][1], $end];
            }
        }

        if (empty($cuts)) {
            return $content;
        }

        // Merge the ranges, then cut from the last to the first so the offsets of those still to cut hold.
        usort($cuts, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [array_shift($cuts)];
        foreach ($cuts as [$start, $end]) {
            $last = count($merged) - 1;
            if ($start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        foreach (array_reverse($merged) as [$start, $end]) {
            $content = substr($content, 0, $start) . substr($content, $end);
        }

        return $content;
    }

    /**
     * The label of a chunk a content source wrote, 'Label (Title): body', or null for any other text.
     *
     * Sources write short labels such as 'Chapter 2' or 'Transcript', so a long run of words before a colon
     * is a sentence of a description and not a label.
     *
     * @param string $chunk The chunk.
     * @return string|null The label with its title, or null.
     */
    private static function source_label(string $chunk): ?string {
        if (!preg_match('/^([^\n:()]{1,40}?)(\s*\([^\n)]{0,120}\))?: /u', trim($chunk), $m)) {
            return null;
        }

        return str_word_count($m[1]) <= 3 ? $m[1] . ($m[2] ?? '') : null;
    }

    /**
     * Where the next heading begins after a position: a heading tag or a paragraph that is bold throughout.
     *
     * @param string $content The markup.
     * @param int $from The offset to look from.
     * @param array $bold The bold lead paragraphs found, as preg_match_all returned them with offsets.
     * @param int $index The position in $bold of the paragraph the section opens with.
     * @return int The offset, or the end of the content.
     */
    private static function next_heading(string $content, int $from, array $bold, int $index): int {
        $next = strlen($content);
        if (preg_match('/<h[1-6]\b/i', $content, $m, PREG_OFFSET_CAPTURE, $from)) {
            $next = $m[0][1];
        }
        for ($j = $index + 1; $j < count($bold); $j++) {
            if (self::heading_text($bold[$j][3][0]) === '') {
                return min($next, $bold[$j][0][1]);
            }
        }

        return $next;
    }

    /**
     * The text of a heading's markup, tags removed and entities and non breaking spaces turned into what they stand for.
     *
     * @param string $html The heading's inner markup.
     * @return string The text.
     */
    private static function heading_text(string $html): string {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text));
    }

    /**
     * Whether a line of a description is, or opens with, a heading that introduces a solution.
     *
     * 'Walkthrough:' and 'Solution guide' qualify, with or without a body after the colon.
     *
     * @param string $line The line.
     * @return bool True when it is such a heading.
     */
    private static function is_solution_heading(string $line): bool {
        $line = self::heading_text($line);
        if (preg_match(self::HEADING_PATTERN, rtrim($line, ':'))) {
            return true;
        }

        // A line like 'Walkthrough: start by running nmap' opens a guide as much as a heading does. Only words
        // that are not ordinary teaching wording count with a body after the colon: 'Solution: use parameterised
        // queries' follows 'Problem:' in any lesson, and 'Write-up: submit a PDF' is an assignment instruction.
        $head = explode(': ', $line, 2);

        return count($head) === 2 && preg_match(self::LEADIN_PATTERN, $head[0]);
    }

    /**
     * Whether a file name is that of a solution guide.
     *
     * @param string $filename The file name.
     * @return bool True when it should not be sent.
     */
    public static function is_solution_file(string $filename): bool {
        $name = trim((string)preg_replace('/[\s._\-]+/u', ' ', pathinfo($filename, PATHINFO_FILENAME)));
        // The file 'Endpoint Security Solutions.txt' is a lesson about solutions, and 'lab3-solution.pdf' is a guide.
        // A short name is a guide if it says so anywhere, a longer one only if the whole name is a solution heading.
        if (!preg_match(self::SOLUTION_PATTERN, $name)) {
            return false;
        }

        // The one exception: a name that is about solutions in the plural, as a lesson is, and says nothing of a lab,
        // task or answers, as 'Endpoint Security Solutions' does.
        $guidewords = '/\b(?:lab|ctf|task|challenge|exercise|module|answers?|instructors?|full|complete|\d+)\b|\d/iu';
        $others = trim((string)preg_replace('/\bsolutions\b/iu', '', $name));
        $othersguide = preg_match('/\b(?:solution|walk[- ]?through|answer|write[- ]?up|spoiler|cheat)/iu', $others);
        $lesson = preg_match('/\bsolutions\b/iu', $name) && !$othersguide && !preg_match($guidewords, $others);
        if ($lesson && str_word_count($others) >= 2) {
            return false;
        }

        return true;
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
     * Removes the parts of the content the prompt already carries.
     *
     * The drawer scrapes some fields from the settings form, so for a saved
     * activity the same text can arrive twice. Sending it twice wastes prompt
     * budget and overweights whatever is duplicated. This works chunk by chunk
     * because a source usually returns more than the drawer scraped: a workshop
     * sends its instructions from the form and its assessment criteria from
     * here, and dropping the whole lot because the instructions matched would
     * lose the criteria.
     *
     * @param string $prompttext The prompt text so far.
     * @param string $content The content about to be appended.
     * @return string The content, without anything already in the prompt.
     */
    private static function strip_duplicates(string $prompttext, string $content): string {
        if (trim($prompttext) === '' || trim($content) === '') {
            return $content;
        }

        $haystack = self::normalise($prompttext);

        $kept = [];
        foreach (preg_split('/\R{2,}/u', $content) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            // A chunk reads 'Chapter 2 (Title): body', and what the drawer
            // scraped is the body on its own, so the label is set aside before
            // comparing. Labels never contain a colon.
            $body = self::normalise(explode(': ', $chunk, 2)[1] ?? $chunk);
            if ($body !== '' && str_contains($haystack, $body)) {
                continue;
            }

            $kept[] = $chunk;
        }

        return implode("\n\n", $kept);
    }

    /**
     * Reduces text to what it says, for comparing one piece against another.
     *
     * @param string $text The text to normalise.
     * @return string The normalised text.
     */
    private static function normalise(string $text): string {
        return \core_text::strtolower(trim((string)preg_replace('/\s+/u', ' ', $text)));
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
