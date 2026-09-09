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
 * First page of a collaborative wiki.
 *
 * A wiki is mostly learner work, so only the first page is read, and only in a
 * collaborative wiki. In an individual wiki every page, the first one included,
 * belongs to the student who holds that subwiki, and none of it describes what
 * the activity sets out to teach.
 *
 * @package    aiplacement_competency
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wiki_source extends source {
    #[\Override]
    public function get_content(): string {
        global $DB;

        $instance = $this->get_instance('id, firstpagetitle, wikimode');
        if (!$instance || $instance->wikimode !== 'collaborative') {
            return '';
        }

        $title = trim((string)$instance->firstpagetitle);
        if ($title === '') {
            return '';
        }

        $pages = $DB->get_records_sql(
            "SELECT p.id, p.title, p.cachedcontent
               FROM {wiki_pages} p
               JOIN {wiki_subwikis} s ON s.id = p.subwikiid
              WHERE s.wikiid = :wikiid AND p.title = :title
           ORDER BY p.id ASC",
            ['wikiid' => $this->cm->instance, 'title' => $title]
        );

        $content = '';
        $seen = [];

        foreach ($pages as $page) {
            $body = self::clean($page->cachedcontent);
            if ($body === '') {
                continue;
            }

            // A wiki in group mode keeps one subwiki per group, each starting
            // from the same first page, so identical copies are sent once.
            $key = md5($body);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $content .= self::chunk('First page', $page->title, $body);
        }

        return trim($content);
    }
}
