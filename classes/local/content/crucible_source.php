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
 * Tasks of a Crucible exercise.
 *
 * The tasks are what the exercise actually asks a student to do, so they carry
 * more competency signal than the intro does. Tasks hidden from students are
 * left out; whether a task is graded is not a reason to ignore what it asks.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crucible_source extends source {
    #[\Override]
    public function get_content(): string {
        global $DB;

        $tasks = $DB->get_records(
            'crucible_tasks',
            ['crucibleid' => $this->cm->instance, 'visible' => 1],
            'id ASC',
            'id, name, description'
        );

        $content = '';
        $number = 0;

        foreach ($tasks as $task) {
            $name = self::clean($task->name);
            $description = self::clean($task->description);
            if ($name === '' && $description === '') {
                continue;
            }

            $number++;
            // A task name is often the whole of what the task asks for, so it
            // becomes the body when there is no description rather than being
            // dropped as a heading with nothing under it.
            $content .= $description === ''
                ? self::chunk("Task {$number}", null, $name)
                : self::chunk("Task {$number}", $name, $description);
        }

        return trim($content);
    }

    #[\Override]
    public function has_content(): bool {
        global $DB;

        return $DB->record_exists('crucible_tasks', ['crucibleid' => $this->cm->instance, 'visible' => 1]);
    }
}
