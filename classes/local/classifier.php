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

namespace aiplacement_competency\local;

use aiplacement_competency\placement;
use context;

/**
 * Runs a classification from content to a short list of checked competencies.
 *
 * Long content, such as a lecture transcript, is read whole, in sections, and
 * the sections' answers are merged. The model chooses from a short list of
 * likely competencies for each section, every answer is checked against the
 * framework, and an optional second pass removes tags the content does not support.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class classifier {
    /** Characters of content sent in one request. */
    public const CHUNK_SIZE = 12000;

    /** Most sections read for one activity. Beyond this, sections are sampled evenly. */
    public const MAX_CHUNKS = 12;

    /** Characters of content shown to the verification pass. */
    private const VERIFY_BUDGET = 60000;

    /** Words in a title that say nothing about the subject. */
    private const TITLE_STOPWORDS = [
        'lecture', 'lab', 'labs', 'module', 'week', 'part', 'video', 'videos', 'transcript', 'introduction',
        'intro', 'chapter', 'exercise', 'activity', 'quiz', 'unit', 'course', 'section', 'overview', 'with',
        'from', 'and', 'the', 'for', 'session', 'class', 'lesson', 'day',
    ];

    /**
     * Classifies content.
     *
     * @param context $context The context of the request.
     * @param string $text The whole content, intro and activity content.
     * @param int $frameworkid The framework id.
     * @param string $shortname The framework shortname.
     * @param array $levels The selected level names.
     * @param string $title The activity's title, empty when there is none.
     * @param bool $verify Whether to run the verification pass.
     * @return array With 'competencies' (each 'id', 'idnumber', 'label', 'reason'),
     *               'warnings' (strings for the person applying the tags) and 'frameworkshortname'.
     * @throws \moodle_exception When the model fails or declines for every section.
     */
    public static function classify(
        context $context,
        string $text,
        int $frameworkid,
        string $shortname,
        array $levels,
        string $title = '',
        bool $verify = false
    ): array {
        $placement = new placement();
        $warnings = [];

        $chunks = self::split($text);
        $total = count($chunks);
        if ($total > self::MAX_CHUNKS) {
            $chunks = self::sample($chunks, self::MAX_CHUNKS);
            $warnings[] = get_string('contentpartial', 'aiplacement_competency', (object)[
                'read' => count($chunks),
                'total' => $total,
            ]);
        }

        $tally = [];
        $mismatch = false;
        $fwshort = $shortname;
        $failure = null;
        $succeeded = 0;
        $skipped = 0;
        $offered = 0;

        foreach ($chunks as $position => $chunk) {
            $prepared = utils::prepare($frameworkid, $shortname, $levels, $chunk, $title);
            $offered += count($prepared['candidates']);
            $label = count($chunks) > 1
                ? 'TEXT TO CLASSIFY (section ' . ($position + 1) . ' of ' . count($chunks) . "):\n"
                : "TEXT TO CLASSIFY:\n";
            try {
                $raw = $placement->send($context, $prepared['instruction'] . "\n\n" . $label . $chunk, [
                    'frameworkid' => $frameworkid,
                    'frameworkshortname' => $shortname,
                    'levels' => $levels,
                ]);
            } catch (\moodle_exception $e) {
                $failure ??= $e;
                $skipped++;
                continue;
            }

            $inner = self::decode($raw);
            if (!is_array($inner['competencies'] ?? null)) {
                $reply = self::reply_text($raw);
                if ($reply !== '' && $inner !== []) {
                    // Valid JSON that is not the requested answer, such as a message from the model.
                    $reply = $reply . ' ' . json_encode($inner);
                }
                if (placement::looks_like_refusal($reply . ' ' . self::finish_reason($raw))) {
                    $failure ??= new \moodle_exception('aiclassifyrefused', 'aiplacement_competency');
                } else if ($reply === '') {
                    // Some providers return only a reasoning block first, which core reads as no text at all.
                    $failure ??= new \moodle_exception('aiclassifyempty', 'aiplacement_competency');
                } else {
                    // Text that is not an answer at all.
                    $failure ??= new \moodle_exception('aiclassifyunusable', 'aiplacement_competency');
                }
                $skipped++;
                continue;
            }
            $succeeded++;

            $fwshort = (string)($inner['framework']['shortname'] ?? $fwshort);
            if (($inner['matches_title'] ?? true) === false) {
                $mismatch = true;
            }

            $returned = is_array($inner['competencies'] ?? null) ? $inner['competencies'] : [];
            foreach (utils::resolve_competencies($returned, $prepared['candidates']) as $found) {
                $id = $found['id'];
                if (!isset($tally[$id])) {
                    $tally[$id] = $found + ['count' => 0, 'first' => count($tally), 'sections' => []];
                }
                $tally[$id]['count']++;
                $tally[$id]['sections'][$position] = true;
                if ($tally[$id]['reason'] === '' && $found['reason'] !== '') {
                    $tally[$id]['reason'] = $found['reason'];
                }
            }
        }

        if ($succeeded === 0 && $failure !== null) {
            throw $failure;
        }
        if ($offered === 0) {
            $warnings[] = get_string('nocandidates', 'aiplacement_competency');
        }
        if ($skipped > 0) {
            $warnings[] = get_string('sectionsskipped', 'aiplacement_competency', (object)[
                'skipped' => $skipped,
                'total' => count($chunks),
            ]);
        }

        // Backed by the most sections first, then in the order they were found.
        $found = array_values($tally);
        usort($found, static function ($a, $b) {
            return [$b['count'], $a['first']] <=> [$a['count'], $b['first']];
        });
        $found = array_slice($found, 0, utils::max_tags());

        if ($verify && !empty($found)) {
            [$found, $removed, $verified] = self::verify($placement, $context, $found, $chunks, $title, $shortname);
            if (!$verified) {
                $warnings[] = get_string('verifyfailed', 'aiplacement_competency');
            }
            if ($removed > 0) {
                $warnings[] = get_string('droppedbyverify', 'aiplacement_competency', $removed);
            }
        }

        if ($mismatch || self::title_mismatch($title, $text)) {
            $warnings[] = get_string('contentmismatch', 'aiplacement_competency', $title !== '' ? $title : '?');
        }

        // Two competencies can read alike in full. The apply step is told which is which by the idnumber, and
        // the person ticking them needs to be able to tell them apart too.
        $seen = [];
        foreach ($found as &$tag) {
            $key = \core_text::strtolower($tag['label']);
            if (isset($seen[$key])) {
                $tag['label'] .= ' (' . $tag['idnumber'] . ')';
            }
            $seen[$key] = true;
        }
        unset($tag);

        $competencies = array_map(static function ($c) {
            return array_intersect_key($c, array_flip(['id', 'idnumber', 'label', 'reason']));
        }, $found);

        return [
            'competencies' => $competencies,
            'warnings' => $warnings,
            'frameworkshortname' => $fwshort,
        ];
    }

    /**
     * Has the model double check its tags and drop the ones the content does not support.
     *
     * @param placement $placement The placement to send through.
     * @param context $context The context of the request.
     * @param array[] $found The tags so far.
     * @param string[] $chunks The sections that were classified, by position.
     * @param string $title The activity title.
     * @param string $shortname The framework shortname.
     * @return array The kept tags, how many were removed, and whether the review gave a usable answer.
     *               All tags are kept when it did not.
     */
    private static function verify(
        placement $placement,
        context $context,
        array $found,
        array $chunks,
        string $title,
        string $shortname
    ): array {
        $lines = [];
        foreach ($found as $tag) {
            $lines[] = '[' . $tag['idnumber'] . '] ' . $tag['label'] . ' -- reason given: ' . ($tag['reason'] ?: 'none');
        }

        // Show the reviewer the sections that back the tags, wherever in the content they are. Every tag gets
        // the first section that backs it, so none is judged against content that does not contain it, and
        // what room is left goes to the other sections that back them.
        $required = [];
        $others = [];
        foreach ($found as $tag) {
            $backing = array_keys($tag['sections'] ?? []);
            sort($backing);
            if ($backing) {
                $required[$backing[0]] = true;
                foreach (array_slice($backing, 1) as $position) {
                    $others[$position] = true;
                }
            }
        }
        $room = max(count($required), intdiv(self::VERIFY_BUDGET, self::CHUNK_SIZE));
        $positions = array_keys($required);
        foreach (array_keys($others) as $position) {
            if (count($positions) >= $room) {
                break;
            }
            if (!isset($required[$position])) {
                $positions[] = $position;
            }
        }
        sort($positions);
        $evidence = array_values(array_intersect_key($chunks, array_flip($positions)));
        $evidence = $evidence ?: array_slice($chunks, 0, $room);

        $prompt = get_string('verify_instruction', 'aiplacement_competency', (object)[
            'frameworkshortname' => $shortname,
            'activitytitle' => $title,
            'tags' => implode("\n", $lines),
        ]) . "\n\nCONTENT:\n" . implode("\n\n", $evidence);

        try {
            $inner = self::decode($placement->send($context, $prompt, ['verify' => 1]));
        } catch (\moodle_exception $e) {
            return [$found, 0, false];
        }
        if (!isset($inner['keep']) || !is_array($inner['keep'])) {
            return [$found, 0, false];
        }

        // The reviewer may answer with an idnumber, "[CODE] Name", "CODE - Name" or "CODE: Name".
        $keep = array_map(static function ($k) {
            return \core_text::strtolower(ltrim(trim((string)$k), '[ '));
        }, $inner['keep']);
        $kept = array_values(array_filter($found, static function ($tag) use ($keep) {
            $code = preg_quote(\core_text::strtolower($tag['idnumber']), '/');
            foreach ($keep as $entry) {
                if (preg_match('/^' . $code . '(?![\p{L}\p{N}_])/u', $entry)) {
                    return true;
                }
            }

            return false;
        }));
        if (empty($kept) && !empty($keep)) {
            // It named things, and none of them is a tag. That is not an answer about these tags.
            return [$found, 0, false];
        }

        return [$kept, count($found) - count($kept), true];
    }

    /**
     * Splits content into sections the model can take in one request.
     *
     * Cuts fall on blank lines, then line ends, then spaces, so a sentence is
     * not cut in two where it can be helped.
     *
     * @param string $text The content.
     * @return string[] The sections, at least one.
     */
    public static function split(string $text): array {
        $text = trim($text);
        if (\core_text::strlen($text) <= self::CHUNK_SIZE) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        foreach (preg_split('/(\R{2,})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            while (\core_text::strlen($piece) > self::CHUNK_SIZE) {
                $head = \core_text::substr($piece, 0, self::CHUNK_SIZE);
                $cut = max((int)\core_text::strrpos($head, "\n"), (int)\core_text::strrpos($head, ' '));
                $cut = $cut > 0 ? $cut : self::CHUNK_SIZE;
                if ($current !== '') {
                    $chunks[] = trim($current);
                    $current = '';
                }
                $chunks[] = trim(\core_text::substr($piece, 0, $cut));
                $piece = \core_text::substr($piece, $cut);
            }
            if (\core_text::strlen($current) + \core_text::strlen($piece) > self::CHUNK_SIZE && $current !== '') {
                $chunks[] = trim($current);
                $current = '';
            }
            $current .= $piece;
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, static fn($c) => $c !== ''));
    }

    /**
     * Picks sections spread evenly over the content, first and last included.
     *
     * @param string[] $chunks The sections.
     * @param int $count How many to keep.
     * @return string[] The chosen sections, in order.
     */
    private static function sample(array $chunks, int $count): array {
        $total = count($chunks);
        if ($count >= $total) {
            return $chunks;
        }
        if ($count === 1) {
            return [$chunks[0]];
        }

        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $chunks[(int)round($i * ($total - 1) / ($count - 1))];
        }

        return $picked;
    }

    /**
     * Whether the content shares nothing with the activity's title.
     *
     * Videos carrying another lecture's transcript, and mislabelled files, give
     * confidently wrong tags. A title whose subject words appear nowhere in a
     * long text is a cheap sign of it.
     *
     * @param string $title The activity title.
     * @param string $text The content.
     * @return bool True when the content looks unrelated to the title.
     */
    public static function title_mismatch(string $title, string $text): bool {
        if (\core_text::strlen($text) < 1000) {
            return false;
        }

        preg_match_all('/[\p{L}\p{N}]{4,}/u', \core_text::strtolower($title), $m);
        $words = array_values(array_diff(array_unique($m[0]), self::TITLE_STOPWORDS));
        if (empty($words)) {
            return false;
        }

        $lower = \core_text::strtolower($text);
        foreach ($words as $word) {
            // A stem, so 'scanning' in a title finds 'scan' in the text.
            $stem = \core_text::strlen($word) > 6 ? \core_text::substr($word, 0, -2) : $word;
            if (str_contains($lower, $stem)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reads the model's JSON answer out of a provider response.
     *
     * @param string $rawjson The provider's response data, JSON encoded.
     * @return array The decoded answer, empty when there is none.
     */
    public static function decode(string $rawjson): array {
        $outer = json_decode($rawjson, true);
        if (is_array($outer) && array_key_exists('generatedcontent', $outer)) {
            $content = $outer['generatedcontent'];

            return is_string($content) ? (self::decode_text($content) ?? []) : (is_array($content) ? $content : []);
        }

        return is_array($outer) ? $outer : (self::decode_text($rawjson) ?? []);
    }

    /**
     * Why the model stopped, as the provider reported it.
     *
     * @param string $rawjson The provider's response data, JSON encoded.
     * @return string The reason, or an empty string.
     */
    private static function finish_reason(string $rawjson): string {
        $outer = json_decode($rawjson, true);

        return is_array($outer) && is_string($outer['finishreason'] ?? null) ? $outer['finishreason'] : '';
    }

    /**
     * The model's reply as text, for spotting a refusal.
     *
     * @param string $rawjson The provider's response data, JSON encoded.
     * @return string The reply, or an empty string.
     */
    private static function reply_text(string $rawjson): string {
        $outer = json_decode($rawjson, true);

        return is_array($outer) && is_string($outer['generatedcontent'] ?? null) ? trim($outer['generatedcontent']) : '';
    }

    /**
     * Decodes JSON that may be wrapped in a code fence or prose.
     *
     * @param string $s The text.
     * @return array|null The decoded value, or null when there is none.
     */
    private static function decode_text(string $s): ?array {
        $s = trim($s);
        if (preg_match('/^\x60{3}[a-zA-Z]*\s*(.*?)\s*\x60{3}$/s', $s, $m)) {
            $s = $m[1];
        }
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $p1 = strpos($s, '{');
        $p2 = strrpos($s, '}');
        if ($p1 !== false && $p2 !== false && $p2 > $p1) {
            $decoded = json_decode(substr($s, $p1, $p2 - $p1 + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
