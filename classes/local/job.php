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
 * One classification, run a step at a time.
 *
 * A step is one model request. Splitting the work this way lets the browser make the requests one by one, so it can
 * say what is happening, stop between them, and never hold a single web request open longer than one model call.
 * The state is plain data so that it can be kept between the requests.
 *
 * With a framework that has work roles, and categories of statements ticked, each section of the content first has
 * the model choose the roles it prepares for, and then has it choose tags from those roles' statements. Otherwise a
 * section is a single request, choosing from the keyword shortlist as before.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class job {
    /** Step type: the model chooses the work roles a section prepares for. */
    public const STEP_ROLES = 'roles';

    /** Step type: the model chooses tags for a section. */
    public const STEP_MATCH = 'match';

    /** Step type: the model double checks the tags. */
    public const STEP_VERIFY = 'verify';

    /** @var array The state, plain data. */
    private array $state;

    /** @var float Seconds the last step took. */
    private float $lastduration = 0.0;

    /**
     * Constructor.
     *
     * @param array $state The state, as state() returned it.
     */
    private function __construct(array $state) {
        $this->state = $state;
    }

    /**
     * Starts a classification.
     *
     * @param int $contextid The context id of the request.
     * @param string $text The whole content, intro and activity content.
     * @param int $frameworkid The framework id.
     * @param string $shortname The framework shortname.
     * @param string[] $levels The ticked level names.
     * @param string $title The activity's title, empty when there is none.
     * @param bool $verify Whether to run the verification pass.
     * @return self The job, at its first step.
     */
    public static function start(
        int $contextid,
        string $text,
        int $frameworkid,
        string $shortname,
        array $levels,
        string $title = '',
        bool $verify = false
    ): self {
        global $USER;

        $warnings = [];
        $chunks = classifier::split($text);
        $total = count($chunks);
        $limit = classifier::max_sections();
        if ($total > $limit) {
            $chunks = classifier::sample($chunks, $limit);
            $warnings[] = get_string('contentpartial', 'aiplacement_competency', (object)[
                'read' => count($chunks),
                'total' => $total,
            ]);
        }

        $rolepick = utils::needs_role_pick($frameworkid, $levels);

        $steps = [];
        foreach (array_keys($chunks) as $position) {
            if ($rolepick) {
                $steps[] = [self::STEP_ROLES, $position];
            }
            $steps[] = [self::STEP_MATCH, $position];
        }
        if ($verify) {
            $steps[] = [self::STEP_VERIFY, 0];
        }

        return new self([
            'userid' => (int)$USER->id,
            'contextid' => $contextid,
            'text' => $text,
            'frameworkid' => $frameworkid,
            'shortname' => $shortname,
            'levels' => array_values($levels),
            'title' => $title,
            'chunks' => $chunks,
            'total' => $total,
            'steps' => $steps,
            'pointer' => 0,
            'rolepick' => $rolepick,
            'picked' => [],
            'rolenames' => [],
            'tally' => [],
            'mismatch' => false,
            'fwshort' => $shortname,
            'failure' => null,
            'succeeded' => 0,
            'skipped' => 0,
            'offered' => 0,
            'unread' => 0,
            'warnings' => $warnings,
            'kept' => null,
            'verifyremoved' => 0,
            'verifywarned' => false,
            'rolesmissed' => 0,
        ]);
    }

    /**
     * Rebuilds a job from kept state.
     *
     * @param array $state The state, as state() returned it.
     * @return self The job.
     */
    public static function from_state(array $state): self {
        return new self($state);
    }

    /**
     * The state, to be kept between requests.
     *
     * @return array Plain data.
     */
    public function state(): array {
        return $this->state;
    }

    /**
     * The id of the user who started the job.
     *
     * @return int The user id.
     */
    public function userid(): int {
        return (int)$this->state['userid'];
    }

    /**
     * The context id the job was started in.
     *
     * @return int The context id.
     */
    public function contextid(): int {
        return (int)$this->state['contextid'];
    }

    /**
     * How many steps there are, in all.
     *
     * @return int The count.
     */
    public function total_steps(): int {
        return count($this->state['steps']);
    }

    /**
     * How many steps have run.
     *
     * @return int The count.
     */
    public function done_steps(): int {
        return (int)$this->state['pointer'];
    }

    /**
     * Whether every step has run.
     *
     * @return bool True when only the result is left.
     */
    public function finished(): bool {
        return $this->done_steps() >= $this->total_steps();
    }

    /**
     * Seconds the last step took.
     *
     * @return float The seconds.
     */
    public function last_duration(): float {
        return $this->lastduration;
    }

    /**
     * The type and section of the next step.
     *
     * @return array|null [type, section position], or null when finished.
     */
    public function next_step(): ?array {
        return $this->state['steps'][$this->done_steps()] ?? null;
    }

    /**
     * What the next step does, as a sentence for the person waiting.
     *
     * @return string The sentence.
     */
    public function next_message(): string {
        $next = $this->next_step();
        if ($next === null) {
            return get_string('progress_finish', 'aiplacement_competency');
        }
        [$type, $position] = $next;
        $a = (object)['n' => $position + 1, 'total' => count($this->state['chunks'])];

        return match ($type) {
            self::STEP_ROLES => get_string('progress_roles', 'aiplacement_competency', $a),
            self::STEP_MATCH => get_string('progress_match', 'aiplacement_competency', $a),
            default => get_string('progress_verify', 'aiplacement_competency'),
        };
    }

    /**
     * Gives up on the sections not yet started, as when time is short, and goes on to the review.
     *
     * @return void
     */
    public function stop_reading(): void {
        $kept = [];
        $unread = 0;
        foreach ($this->state['steps'] as $index => $step) {
            if ($index >= $this->state['pointer'] && $step[0] !== self::STEP_VERIFY) {
                if ($step[0] === self::STEP_MATCH) {
                    $unread++;
                }
                continue;
            }
            $kept[] = $step;
        }
        $this->state['unread'] += $unread;
        $this->state['steps'] = $kept;
    }

    /**
     * Adds a warning for the person applying the tags.
     *
     * @param string $warning The warning, already in words.
     * @return void
     */
    public function add_warning(string $warning): void {
        $this->state['warnings'][] = $warning;
    }

    /**
     * Drops the review, with a warning, as when there is no time left for it.
     *
     * @return void
     */
    public function skip_verify(): void {
        $this->state['steps'] = array_values(array_filter(
            $this->state['steps'],
            static fn($step) => $step[0] !== self::STEP_VERIFY
        ));
        if (!empty($this->state['tally'])) {
            $this->state['warnings'][] = get_string('verifyskipped', 'aiplacement_competency');
        }
    }

    /**
     * Runs the next step, one model request.
     *
     * @return void
     */
    public function step(): void {
        $next = $this->next_step();
        if ($next === null) {
            return;
        }
        [$type, $position] = $next;

        $started = microtime(true);
        $context = \context::instance_by_id($this->contextid());
        $placement = new placement();
        match ($type) {
            self::STEP_ROLES => $this->run_roles($placement, $context, $position),
            self::STEP_MATCH => $this->run_match($placement, $context, $position),
            default => $this->run_verify($placement, $context),
        };
        $this->lastduration = microtime(true) - $started;
        $this->state['pointer']++;
    }

    /**
     * Has the model choose the work roles a section prepares for.
     *
     * Whatever goes wrong here is not an error of its own: the section is then matched against the keyword
     * shortlist as it would have been without roles.
     *
     * @param placement $placement The placement to send through.
     * @param context $context The context of the request.
     * @param int $position The section.
     * @return void
     */
    private function run_roles(placement $placement, context $context, int $position): void {
        $roles = utils::get_roles((int)$this->state['frameworkid']);
        $lines = [];
        foreach ($roles as $role) {
            $lines[] = '[' . $role->idnumber . '] ' . $role->line;
        }
        $maxroles = (int)get_config('aiplacement_competency', 'maxroles');
        $maxroles = $maxroles > 0 ? $maxroles : 4;
        $prompt = get_string('roles_instruction', 'aiplacement_competency', (object)[
            'frameworkshortname' => $this->state['shortname'],
            'activitytitle' => trim($this->state['title']),
            'maxroles' => $maxroles,
            'roles' => implode("\n", $lines),
        ]) . "\n\nTEXT:\n" . $this->state['chunks'][$position];

        $this->state['picked'][$position] = [];
        try {
            $inner = classifier::decode($placement->send($context, $prompt, ['roles' => 1]));
        } catch (\moodle_exception $e) {
            $this->state['rolesmissed']++;
            return;
        }
        $returned = is_array($inner['roles'] ?? null) ? $inner['roles'] : [];

        // Roles are competencies, and the model may name them as an idnumber, 'CODE - Name' or a name.
        $picked = [];
        foreach (array_slice(utils::resolve_competencies($returned, $roles), 0, $maxroles) as $found) {
            $picked[] = $found['id'];
            $this->state['rolenames'][$found['id']] = trim((string)$roles[$found['id']]->shortname);
        }
        $this->state['picked'][$position] = $picked;
        if (empty($picked)) {
            $this->state['rolesmissed']++;
        }
    }

    /**
     * Has the model choose tags for a section.
     *
     * @param placement $placement The placement to send through.
     * @param context $context The context of the request.
     * @param int $position The section.
     * @return void
     */
    private function run_match(placement $placement, context $context, int $position): void {
        $state = &$this->state;
        $chunk = $state['chunks'][$position];

        $pool = null;
        if ($state['rolepick'] && !empty($state['picked'][$position])) {
            $pool = utils::role_pool((int)$state['frameworkid'], $state['picked'][$position], $state['levels']);
            if (!empty($pool)) {
                // The chosen roles' statements first, then the closest keyword matches beside them: a role the model
                // did not choose, or a statement its sentence does not bring to mind, can still be offered.
                $extras = utils::keyword_extras((int)$state['frameworkid'], $state['levels'], $chunk);
                $pool += array_diff_key($extras, $pool);
                uasort($pool, static function ($a, $b) {
                    return [$a->category, $a->shortname] <=> [$b->category, $b->shortname];
                });
            }
            if (empty($pool)) {
                // Roles with nothing in the ticked categories leave the keyword shortlist to do the work, which the
                // teacher is told, as for a section in which no role could be chosen.
                $pool = null;
                $state['rolesmissed']++;
            }
        }
        $prepared = utils::prepare(
            (int)$state['frameworkid'],
            $state['shortname'],
            $state['levels'],
            $chunk,
            $state['title'],
            $pool,
            $state['rolepick']
        );
        $state['offered'] += count($prepared['candidates']);
        $count = count($state['chunks']);
        $label = $count > 1
            ? 'TEXT TO CLASSIFY (section ' . ($position + 1) . ' of ' . $count . "):\n"
            : "TEXT TO CLASSIFY:\n";

        try {
            $raw = $placement->send($context, $prepared['instruction'] . "\n\n" . $label . $chunk, [
                'frameworkid' => $state['frameworkid'],
                'frameworkshortname' => $state['shortname'],
                'levels' => $state['levels'],
            ]);
        } catch (\moodle_exception $e) {
            // The provider's own message is the debug information, which an administrator needs to find out why.
            $state['failure'] ??= [$e->errorcode, $e->debuginfo];
            $state['skipped']++;
            return;
        }

        $inner = classifier::decode($raw);
        if (!is_array($inner['competencies'] ?? null)) {
            $reply = classifier::reply_text($raw);
            if ($reply !== '' && $inner !== []) {
                // Valid JSON that is not the requested answer, such as a message from the model.
                $reply = $reply . ' ' . json_encode($inner);
            }
            if (placement::looks_like_refusal($reply . ' ' . classifier::finish_reason($raw))) {
                $state['failure'] ??= ['aiclassifyrefused', null];
            } else if ($reply === '') {
                // Some providers return only a reasoning block first, which core reads as no text at all.
                $state['failure'] ??= ['aiclassifyempty', null];
            } else {
                // Text that is not an answer at all.
                $state['failure'] ??= ['aiclassifyunusable', null];
            }
            $state['skipped']++;
            return;
        }
        $state['succeeded']++;

        $state['fwshort'] = (string)($inner['framework']['shortname'] ?? $state['fwshort']);
        if (($inner['matches_title'] ?? true) === false) {
            $state['mismatch'] = true;
        }

        foreach (utils::resolve_competencies($inner['competencies'], $prepared['candidates']) as $found) {
            $id = $found['id'];
            if (!isset($state['tally'][$id])) {
                $state['tally'][$id] = $found + ['count' => 0, 'first' => count($state['tally']), 'sections' => []];
            }
            $state['tally'][$id]['count']++;
            $state['tally'][$id]['sections'][$position] = true;
            if ($state['tally'][$id]['reason'] === '' && $found['reason'] !== '') {
                $state['tally'][$id]['reason'] = $found['reason'];
            }
        }
    }

    /**
     * Has the model double check the tags found so far.
     *
     * @param placement $placement The placement to send through.
     * @param context $context The context of the request.
     * @return void
     */
    private function run_verify(placement $placement, context $context): void {
        $found = $this->ranked();
        if (empty($found)) {
            return;
        }
        [$kept, $removed, $verified] = classifier::review(
            $placement,
            $context,
            $found,
            $this->state['chunks'],
            $this->state['title'],
            $this->state['shortname']
        );
        if (!$verified) {
            $this->state['verifywarned'] = true;
            return;
        }
        $this->state['kept'] = array_column($kept, 'id');
        $this->state['verifyremoved'] = $removed;
    }

    /**
     * The tags found so far, best first, and cut to what a classification returns.
     *
     * With work roles there are two caps, whichever is reached first: the most from any one category, so Tasks,
     * Knowledge and Skills each keep their own best, and the most in all. Otherwise it is the best overall. Backed by
     * the most sections first, then in the order they were found.
     *
     * @return array[] The tags.
     */
    private function ranked(): array {
        $found = array_values($this->state['tally']);
        usort($found, static function ($a, $b) {
            return [$b['count'], $a['first']] <=> [$a['count'], $b['first']];
        });

        $max = utils::max_tags();
        if (!$this->state['rolepick']) {
            return array_slice($found, 0, $max);
        }

        // In rank order, a tag is taken while its category and the total both have room.
        $percategory = utils::max_tags_per_category();
        $taken = [];
        $kept = [];
        foreach ($found as $tag) {
            if (count($kept) >= $max) {
                break;
            }
            if (($taken[$tag['category']] ?? 0) >= $percategory) {
                continue;
            }
            $taken[$tag['category']] = ($taken[$tag['category']] ?? 0) + 1;
            $kept[] = $tag;
        }

        return $kept;
    }

    /**
     * The result, once every step has run.
     *
     * @return array With 'competencies' (each 'id', 'idnumber', 'label', 'reason', 'category'), 'warnings',
     *               'frameworkshortname' and 'roles' (the names of the work roles chosen).
     * @throws \moodle_exception When the model failed or declined for every section.
     */
    public function result(): array {
        $state = $this->state;
        if ($state['succeeded'] === 0 && $state['failure'] !== null) {
            throw new \moodle_exception($state['failure'][0], 'aiplacement_competency', '', null, $state['failure'][1]);
        }

        $warnings = $state['warnings'];
        $sections = count($state['chunks']);
        if ($state['unread'] > 0) {
            $warnings[] = get_string('timebudgetreached', 'aiplacement_competency', (object)[
                'read' => $sections - $state['unread'],
                'total' => $state['total'],
            ]);
        }
        if ($state['rolesmissed'] > 0) {
            $warnings[] = get_string('rolesmissed', 'aiplacement_competency', (object)[
                'missed' => $state['rolesmissed'],
                'total' => $sections - $state['unread'],
            ]);
        }
        if ($state['offered'] === 0) {
            $warnings[] = get_string('nocandidates', 'aiplacement_competency');
        }
        if ($state['skipped'] > 0) {
            $warnings[] = get_string('sectionsskipped', 'aiplacement_competency', (object)[
                'skipped' => $state['skipped'],
                'total' => $sections - $state['unread'],
            ]);
        }

        $found = $this->ranked();
        if ($state['kept'] !== null) {
            $found = array_values(array_filter($found, static fn($tag) => in_array($tag['id'], $state['kept'], true)));
        }
        if ($state['verifywarned']) {
            $warnings[] = get_string('verifyfailed', 'aiplacement_competency');
        }
        if ($state['verifyremoved'] > 0) {
            $warnings[] = get_string('droppedbyverify', 'aiplacement_competency', $state['verifyremoved']);
        }
        if ($state['mismatch'] || classifier::title_mismatch($state['title'], $state['text'])) {
            $warnings[] = get_string(
                'contentmismatch',
                'aiplacement_competency',
                $state['title'] !== '' ? $state['title'] : '?'
            );
        }

        // Two competencies can read alike in full. The apply step is told which is which by the idnumber, and
        // the person ticking them needs to be able to tell them apart too.
        $seen = [];
        foreach ($found as $tag) {
            $seen[\core_text::strtolower($tag['label'])] = true;
        }
        $used = [];
        foreach ($found as &$tag) {
            $key = \core_text::strtolower($tag['label']);
            while (isset($used[$key])) {
                // Not the same text as any label in the list, the ones just given included.
                $tag['label'] .= ' (' . $tag['idnumber'] . ')';
                $key = \core_text::strtolower($tag['label']);
                if (isset($seen[$key]) && !isset($used[$key])) {
                    $tag['label'] .= '*';
                    $key = \core_text::strtolower($tag['label']);
                }
            }
            $used[$key] = true;
        }
        unset($tag);

        $roles = [];
        foreach ($state['picked'] as $ids) {
            foreach ($ids as $id) {
                $roles[$id] = $state['rolenames'][$id] ?? '';
            }
        }

        return [
            'competencies' => array_map(static function ($c) {
                return array_intersect_key($c, array_flip(['id', 'idnumber', 'label', 'reason', 'category']));
            }, $found),
            'warnings' => $warnings,
            'frameworkshortname' => $state['fwshort'],
            'roles' => array_values(array_filter($roles)),
        ];
    }
}
