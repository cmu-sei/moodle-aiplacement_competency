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
 * Table of contents of an IMS content package.
 *
 * The package's own pages are files Moodle only serves, so the manifest titles
 * are all there is. They are held on the activity row as a serialized tree of
 * items, each with a title and its own subitems.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class imscp_source extends source {
    #[\Override]
    public function get_content(): string {
        $instance = $this->get_instance('id, structure');
        if (!$instance) {
            return '';
        }

        $structure = unserialize_array((string)$instance->structure);
        if (!is_array($structure)) {
            return '';
        }

        $titles = self::collect_titles($structure);
        if (empty($titles)) {
            return '';
        }

        return trim(self::chunk('Package contents', null, implode('; ', $titles)));
    }

    /**
     * Walks the item tree, depth first, collecting the titles in reading order.
     *
     * @param array $items The items to walk.
     * @return string[] The non-empty titles found.
     */
    private static function collect_titles(array $items): array {
        $titles = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = self::clean(is_string($item['title'] ?? null) ? $item['title'] : '');
            if ($title !== '') {
                $titles[] = $title;
            }

            if (!empty($item['subitems']) && is_array($item['subitems'])) {
                foreach (self::collect_titles($item['subitems']) as $subtitle) {
                    $titles[] = $subtitle;
                }
            }
        }

        return $titles;
    }
}
