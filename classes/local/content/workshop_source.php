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
 * Instructions and assessment criteria of a workshop.
 *
 * The criteria say what the work is judged on, which is a closer description of
 * the skill being taught than the instructions usually are. They are held by
 * the grading strategy subplugin the workshop uses, one table per strategy, and
 * none of them appear on the settings form.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_source extends source {
    /**
     * The table each grading strategy keeps its criteria in.
     *
     * Every one of these is a core subplugin, but a site can uninstall one, so
     * the strategy is checked against what is actually installed before its
     * table is read.
     */
    private const STRATEGY_TABLES = [
        'accumulative' => 'workshopform_accumulative',
        'comments' => 'workshopform_comments',
        'numerrors' => 'workshopform_numerrors',
        'rubric' => 'workshopform_rubric',
    ];

    #[\Override]
    public function get_content(): string {
        $instance = $this->get_instance('id, instructauthors, instructreviewers, strategy');
        if (!$instance) {
            return '';
        }

        $content = '';
        $content .= self::chunk('Instructions for submission', null, $instance->instructauthors ?? '');
        $content .= self::chunk('Instructions for assessment', null, $instance->instructreviewers ?? '');
        $content .= $this->get_criteria((string)$instance->strategy);

        return trim($content);
    }

    /**
     * The criteria the workshop's grading strategy assesses against.
     *
     * @param string $strategy The workshop's grading strategy.
     * @return string The criteria, or an empty string when there are none.
     */
    private function get_criteria(string $strategy): string {
        global $DB;

        $table = self::STRATEGY_TABLES[$strategy] ?? null;
        if ($table === null || \core_component::get_component_directory("workshopform_{$strategy}") === null) {
            return '';
        }

        $dimensions = $DB->get_records(
            $table,
            ['workshopid' => $this->cm->instance],
            'sort ASC, id ASC',
            'id, description'
        );

        $content = '';
        $number = 0;

        foreach ($dimensions as $dimension) {
            $description = self::clean($dimension->description);
            if ($description === '') {
                continue;
            }

            $number++;
            $content .= self::chunk("Assessment criterion {$number}", null,
                $description . $this->get_levels($strategy, (int)$dimension->id));
        }

        return $content;
    }

    /**
     * The level definitions of one rubric criterion.
     *
     * Only the rubric strategy has these, and they are where a rubric says what
     * good and poor work look like.
     *
     * @param string $strategy The workshop's grading strategy.
     * @param int $dimensionid The criterion the levels belong to.
     * @return string The levels, ready to append to the criterion.
     */
    private function get_levels(string $strategy, int $dimensionid): string {
        global $DB;

        if ($strategy !== 'rubric') {
            return '';
        }

        $levels = $DB->get_records(
            'workshopform_rubric_levels',
            ['dimensionid' => $dimensionid],
            'grade ASC, id ASC',
            'id, definition'
        );

        $definitions = [];
        foreach ($levels as $level) {
            $definition = self::clean($level->definition);
            if ($definition !== '') {
                $definitions[] = $definition;
            }
        }

        if (empty($definitions)) {
            return '';
        }

        return ' Levels: ' . implode('; ', $definitions);
    }
}
