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

/**
 * Narrows a long list of competencies to the few worth showing the model.
 *
 * DCWF and NICE hold hundreds to thousands of statements. Pasted whole into a
 * prompt they make a model return nothing, or hundreds of ids. This ranks the
 * statements against the content by keyword overlap, weighting rare words more
 * than common ones, and keeps the best. The model then chooses from a short list.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class narrower {
    /** Words too common to say anything about a competency. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'that', 'this', 'from', 'are', 'was', 'were', 'will', 'can', 'its', 'their',
        'knowledge', 'skill', 'skills', 'ability', 'abilities', 'task', 'tasks', 'use', 'using', 'used', 'such',
        'into', 'than', 'then', 'them', 'they', 'you', 'your', 'not', 'has', 'have', 'had', 'but', 'all', 'any',
        'each', 'more', 'other', 'also', 'how', 'what', 'when', 'which', 'who', 'about', 'between', 'within',
        'perform', 'ensure', 'determine', 'based', 'including', 'related', 'across', 'through', 'where',
    ];

    /**
     * Keeps the competencies that best match the text.
     *
     * A competency whose code appears in the text, as it does when the activity
     * author published a mapping, always ranks first.
     *
     * @param \stdClass[] $candidates Competencies keyed by id, each with shortname, idnumber, description.
     * @param string $text The content being classified.
     * @param int $limit How many to keep. Zero or less keeps all.
     * @return \stdClass[] The best matches, keyed by id, in the framework's original order.
     */
    public static function top(array $candidates, string $text, int $limit): array {
        if ($limit <= 0 || count($candidates) <= $limit) {
            return $candidates;
        }

        $query = self::term_counts($text);
        $lowertext = \core_text::strtolower($text);

        $docs = [];
        $docfreq = [];
        foreach ($candidates as $id => $candidate) {
            $terms = array_keys(self::term_counts(
                (string)($candidate->shortname ?? '') . ' ' . strip_tags((string)($candidate->description ?? ''))
            ));
            $docs[$id] = $terms;
            foreach ($terms as $term) {
                $docfreq[$term] = ($docfreq[$term] ?? 0) + 1;
            }
        }

        $total = count($candidates);
        $scores = [];
        foreach ($docs as $id => $terms) {
            $score = 0.0;
            foreach ($terms as $term) {
                if (isset($query[$term])) {
                    $idf = log(1 + ($total - $docfreq[$term] + 0.5) / ($docfreq[$term] + 0.5));
                    $score += $idf * ($query[$term] / ($query[$term] + 1.2));
                }
            }
            // A literal mention of the competency's code beats any keyword score.
            $code = self::code($candidates[$id]);
            if ($code !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($code, '/') . '(?![a-z0-9])/u', $lowertext)) {
                $score += 1000;
            }
            $scores[$id] = $score;
        }

        // Stable sort: ties keep the framework's order.
        $order = array_keys($scores);
        usort($order, static function ($a, $b) use ($scores) {
            return $scores[$b] <=> $scores[$a];
        });

        $keep = array_flip(array_slice($order, 0, $limit));

        return array_intersect_key($candidates, $keep);
    }

    /**
     * Keeps the competencies that best match the text, a share of the limit for each category.
     *
     * Narrowing the whole list by keyword score can leave a category with nothing, and the model could then never
     * return a tag from it. Each category keeps a share in proportion to its size, and at least one entry.
     *
     * @param \stdClass[] $candidates Competencies keyed by id, each with a 'category'.
     * @param string $text The content being classified.
     * @param int $limit How many to keep in all. Zero or less keeps all.
     * @return \stdClass[] The best matches, keyed by id, in their original order.
     */
    public static function top_by_group(array $candidates, string $text, int $limit): array {
        $total = count($candidates);
        if ($limit <= 0 || $total <= $limit) {
            return $candidates;
        }

        $groups = [];
        foreach ($candidates as $id => $candidate) {
            $groups[(string)($candidate->category ?? '')][$id] = $candidate;
        }

        $keep = [];
        foreach ($groups as $group) {
            $share = max(1, (int)floor($limit * count($group) / $total));
            $keep += self::top($group, $text, $share);
        }

        // Rounding down leaves a few places over: they go to the best of what was left out.
        $left = $limit - count($keep);
        if ($left > 0) {
            $rest = array_diff_key($candidates, $keep);
            $keep += self::top($rest, $text, $left);
        }

        // A minimum of one for every category can pass the limit when there are more categories than places.
        if (count($keep) > $limit) {
            $keep = self::top(array_intersect_key($candidates, $keep), $text, $limit);
        }

        return array_intersect_key($candidates, $keep);
    }

    /**
     * The code a competency is known by, lower cased, or an empty string.
     *
     * @param \stdClass $candidate The competency.
     * @return string The code.
     */
    public static function code(\stdClass $candidate): string {
        $code = \core_text::strtolower(trim((string)($candidate->idnumber ?? '')));

        // Too short to be a code: a bare 'it' would match any sentence.
        return \core_text::strlen($code) >= 3 && preg_match('/\d/', $code) ? $code : '';
    }

    /**
     * Counts the significant words in a text.
     *
     * @param string $text The text.
     * @return int[] Occurrences by stemmed word.
     */
    private static function term_counts(string $text): array {
        $text = \core_text::strtolower($text);
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-\.]*[\p{L}\p{N}]|[\p{L}\p{N}]/u', $text, $matches);

        $counts = [];
        foreach ($matches[0] as $word) {
            $word = rtrim($word, '.-');
            if (\core_text::strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $stem = self::stem($word);
            $counts[$stem] = ($counts[$stem] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Strips the commonest English endings, enough to match 'scanning' with 'scan'.
     *
     * @param string $word The lower case word.
     * @return string The stem.
     */
    private static function stem(string $word): string {
        foreach (['ing', 'ed', 'es', 's'] as $suffix) {
            $length = \core_text::strlen($word);
            if ($length > \core_text::strlen($suffix) + 3 && str_ends_with($word, $suffix)) {
                return \core_text::substr($word, 0, $length - \core_text::strlen($suffix));
            }
        }

        return $word;
    }
}
