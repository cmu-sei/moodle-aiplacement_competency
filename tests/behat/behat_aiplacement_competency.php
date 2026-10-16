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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;

/**
 * Behat steps for aiplacement_competency.
 *
 * @package    aiplacement_competency
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_aiplacement_competency extends behat_base {
    /**
     * Render a classification response card on the current page, as if the AI provider had returned it.
     *
     * Behat cannot call a real provider, so this stands in for the classify_text round trip and puts
     * the same response template in the page that the drawer would show. Everything after that - the
     * Apply button, the modal and its checklist - is the plugin's own JavaScript.
     *
     * @Given /^the AI classification suggests these competencies:$/
     * @param TableNode $table One competency per row.
     */
    public function the_ai_classification_suggests_these_competencies(TableNode $table): void {
        $context = [
            'action' => 'classify_text',
            'heading' => 'Suggested competencies',
            'uniqid' => 'behat',
            'frameworkid' => 1,
            'frameworkshortname' => 'NICE',
            'usedlevels' => ['basic'],
            'competencies' => array_map(fn($row) => $row[0], $table->getRows()),
        ];
        $json = json_encode($context);
        $this->execute_script(<<<JS
            M.util.js_pending('aiplacement_competency_behat');
            require(['core/templates'], function(Templates) {
                Templates.render('aiplacement_competency/response', {$json}).then(function(html) {
                    var region = document.querySelector('[role="main"]') || document.body;
                    region.insertAdjacentHTML('beforeend', html);
                    M.util.js_complete('aiplacement_competency_behat');
                    return html;
                }).catch(function(e) {
                    M.util.js_complete('aiplacement_competency_behat');
                    throw e;
                });
            });
        JS);
        $this->wait_for_pending_js();
    }

    /**
     * TEMPORARY diagnostic.
     *
     * @Given /^I dump the aiplacement diagnostics$/
     */
    public function i_dump_the_aiplacement_diagnostics(): void {
        $this->getSession()->executeScript(<<<JS
            window.__aipdiag = 'pending';
            var ctx = requirejs.s.contexts._;
            var out = {
                theme: M.cfg.theme, jsrev: M.cfg.jsrev, pending: M.util.pending_js,
                comp: !!document.querySelector('#id_competenciessectioncontainer'),
                drawer: !!document.querySelector('#ai-classify-drawer'),
                btn: !!document.querySelector('[data-action="classify"]'),
                defined: Object.keys(ctx.defined).filter(function(k) { return /aiplacement|modal|templates/.test(k); }),
                registry: Object.keys(ctx.registry).map(function(k) { var m = ctx.registry[k];
                    return k + (m.error ? ' ERR ' + m.error : '') + ' deps=' + (m.depMaps || []).map(function(d) { return d.name; }).join(','); }),
                scripts: Array.prototype.map.call(document.querySelectorAll('script:not([src])'), function(x) { return x.textContent; })
                    .filter(function(t) { return /aiplacement/.test(t); }).map(function(t) { return t.slice(0, 600); })
            };
            require(['aiplacement_competency/classify_button', 'aiplacement_competency/applycmps'], function() {
                out.load = 'ok'; window.__aipdiag = JSON.stringify(out);
            }, function(e) { out.load = 'ERR ' + e + ' ' + (e.requireModules || []).join(','); window.__aipdiag = JSON.stringify(out); });
            setTimeout(function() { if (window.__aipdiag === 'pending') { out.load = 'timeout'; window.__aipdiag = JSON.stringify(out); } }, 5000);
        JS);
        sleep(6);
        throw new \Exception('AIPDIAG ' . $this->getSession()->evaluateScript('return window.__aipdiag;'));
    }
}
