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
//

/* eslint-disable max-len */
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
/* eslint-enable max-len */
define([
    'aiplacement_courseassist/placement',
    'core/templates',
    'core/ajax',
    'core/notification',
    'core/str'
], function(Base, Templates, Ajax, Notification, Str) {

    const SELECTOR_CLASSIFY    = '[data-action="classify"]';
    const SELECTOR_CANCEL      = '[data-action="cancel"]';
    const SELECTOR_CONTINUE    = '[data-action="continue"]';
    const SELECTOR_BACK        = '[data-action="back"]';
    const SELECTOR_PRESELECT   = '#classify-preselect';
    const SELECTOR_LEVELS  = 'input[name="classify-levels"]';
    const ID_CLOSE_BTN         = '#ai-classify-drawer-close';
    const SELECTOR_RETRY      = '[data-action="retry"]';
    const COMPONENT            = 'aiplacement_competency';

    return class ClassifyPlacement extends Base {
        constructor(userId, contextId) {
            super(userId, contextId);

            this.aiDrawerElement      = document.querySelector('#ai-classify-drawer');
            this.aiDrawerBodyElement  = this.aiDrawerElement.querySelector('.ai-drawer-body');
            this.aiDrawerCloseElement = this.aiDrawerElement.querySelector(ID_CLOSE_BTN);
            if (this.aiDrawerCloseElement) {
                this.aiDrawerCloseElement.addEventListener('click', e => {
                    e.preventDefault();
                    this.closeAIDrawer();
                });
            }

            this._levels = [];
            this.registerExtraListener();
        }

        /**
         * Fetch, and cache for the lifetime of the drawer, the strings the steps need.
         * @returns {Promise<Object>} Strings keyed by string identifier
         */
        getStrings() {
            if (!this._stringsPromise) {
                const requests = [
                    {key: 'loading', component: 'core'},
                    {key: 'frameworkselection_placeholder', component: COMPONENT},
                    {key: 'frameworkselection_none', component: COMPONENT},
                    {key: 'frameworkselection_error', component: COMPONENT},
                    {key: 'levelsselection_error', component: COMPONENT},
                    {key: 'notify_empty_description', component: COMPONENT},
                ];

                this._stringsPromise = Str.get_strings(requests).then(values => {
                    const strings = {};
                    requests.forEach((request, index) => {
                        strings[request.key] = values[index];
                    });
                    return strings;
                });
            }

            return this._stringsPromise;
        }

        /**
         * Build the disabled, selected option shown when there is nothing to choose yet.
         * @param {string} text The option label
         * @returns {HTMLOptionElement} The placeholder option
         */
        createPlaceholderOption(text) {
            const option = document.createElement('option');
            option.value = '';
            option.disabled = true;
            option.selected = true;
            option.textContent = text;
            return option;
        }

        /**
         * Extract activity-specific content from the page.
         *
         * Only the editor fields of the settings form are read here, because that form is
         * the only page the drawer renders on. Everything a module keeps outside the form -
         * a book's chapters, a quiz's questions, a workshop's grading criteria - is read
         * from the database by the server, in aiplacement_competency\local\content\*_source.
         * The fields below are still worth reading client side because they carry edits the
         * user has not saved yet, which the server cannot see.
         *
         * @returns {string} The activity-specific content
         */
        extractActivityContent() {
            let content = '';

            // Try to detect activity type from the page.
            const bodyClasses = document.body.className;

            // Page: Extract page content.
            if (bodyClasses.includes('path-mod-page')) {
                const pageEditor = document.querySelector('#id_page') || document.querySelector('[name="page[text]"]');
                const pageContentEditable = document.querySelector('[id^="id_page"][contenteditable="true"]');
                if (pageEditor && pageEditor.value) {
                    content += pageEditor.value.trim() + ' ';
                } else if (pageContentEditable) {
                    content += pageContentEditable.textContent.trim() + ' ';
                }
            }

            // Assignment: Extract assignment activity content.
            if (bodyClasses.includes('path-mod-assign')) {
                const activityEditor = document.querySelector('#id_activity') || document.querySelector('[name="activity[text]"]');
                const activityContentEditable = document.querySelector('[id^="id_activity"][contenteditable="true"]');
                if (activityEditor && activityEditor.value) {
                    content += activityEditor.value.trim() + ' ';
                } else if (activityContentEditable) {
                    content += activityContentEditable.textContent.trim() + ' ';
                }
            }

            // Workshop: Extract instruction fields.
            if (bodyClasses.includes('path-mod-workshop')) {
                const instructAuthors = document.querySelector('#id_instructauthorseditor') ||
                                       document.querySelector('[name="instructauthors[text]"]');
                const instructReviewers = document.querySelector('#id_instructreviewerseditor') ||
                                         document.querySelector('[name="instructreviewers[text]"]');

                if (instructAuthors) {
                    const text = instructAuthors.value || instructAuthors.textContent;
                    if (text) {
                        content += text.trim() + ' ';
                    }
                }
                if (instructReviewers) {
                    const text = instructReviewers.value || instructReviewers.textContent;
                    if (text) {
                        content += text.trim() + ' ';
                    }
                }
            }

            return content.trim();
        }

        /**
         * Read activity description and content from the page.
         * @returns {string} Combined intro and activity content
         */
        readActivityContent() {
            // Read intro/description.
            const ta = document.querySelector('#id_introeditor') || document.querySelector('[name="intro[text]"]');
            const ce = document.querySelector('[id^="id_introeditor"][contenteditable="true"]');
            const intro = (ta && typeof ta.value === 'string' ? ta.value.trim() : '') ||
                         (ce && ce.textContent ? ce.textContent.trim() : '');

            // Read activity-specific content.
            const activityContent = this.extractActivityContent();

            // Combine intro and activity content.
            const combined = [intro, activityContent].filter(s => s).join('\n\n');

            return combined.trim();
        }

        registerExtraListener() {
            document.addEventListener('click', async e => {
                // 1) Retry inside the drawer
                const retryBtn = e.target.closest(SELECTOR_RETRY);
                if (retryBtn && this.aiDrawerElement.contains(retryBtn)) {
                    e.preventDefault();
                    retryBtn.disabled = true;
                    try {
                        // Read activity content (intro + activity-specific content)
                        const prompt = this.readActivityContent();

                        // On any activity settings page the server decides what content the
                        // activity holds, so no list of module names is kept here.
                        const bodyClasses = document.body.className;
                        const hasDbContent = bodyClasses.includes('path-mod-');

                        // Only show error if no content AND activity doesn't fetch from DB
                        if (!prompt && !hasDbContent) {
                            const errorHtml = await Templates.render('aiplacement_competency/error', {});
                            this.aiDrawerBodyElement.innerHTML = errorHtml;
                            return;
                        }
                        await this.showSelectFramework(prompt);
                    } finally {
                        retryBtn.disabled = false;
                    }
                    return;
                }

                // 2) Classify button
                const btn = e.target.closest(SELECTOR_CLASSIFY);
                if (!btn) {
                    return;
                }

                e.preventDefault();
                this.openAIDrawer();

                // Read activity content (intro + activity-specific content)
                const prompt = this.readActivityContent();

                // On any activity settings page the server decides what content the
                // activity holds, so no list of module names is kept here.
                const bodyClasses = document.body.className;
                const hasDbContent = bodyClasses.includes('path-mod-');

                // Only show error if no content AND activity doesn't fetch from DB
                if (!prompt && !hasDbContent) {
                    const errorHtml = await Templates.render('aiplacement_competency/error', {});
                    this.aiDrawerBodyElement.innerHTML = errorHtml;

                    const strings = await this.getStrings();
                    Notification.addNotification({
                        type: 'error',
                        message: strings.notify_empty_description,
                    });
                    return;
                }

                await this.showSelectFramework(prompt);
            });
        }

        // === STEP 1: Select framework ===
        async showSelectFramework(prompt) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const strings = await this.getStrings();

            const prestepHtml = await Templates.render('aiplacement_competency/framework_select', {});
            this.aiDrawerBodyElement.innerHTML = prestepHtml;

            const select = this.aiDrawerBodyElement.querySelector(SELECTOR_PRESELECT);
            if (select) {
                select.replaceChildren(this.createPlaceholderOption(strings.loading));
            }

            try {
                const calls = Ajax.call([{
                    methodname: 'core_competency_list_competency_frameworks',
                    args: {
                        sort: 'shortname',
                        order: 'ASC',
                        skip: 0,
                        context: { contextid: 1, instanceid: 0 },
                    }
                }]);

                const res = await calls[0];
                const frameworks = Array.isArray(res?.frameworks) ? res.frameworks
                                : Array.isArray(res) ? res
                                : [];

                if (select) {
                    if (!frameworks.length) {
                        select.replaceChildren(this.createPlaceholderOption(strings.frameworkselection_none));
                    } else {
                        select.replaceChildren(
                            this.createPlaceholderOption(strings.frameworkselection_placeholder)
                        );

                        frameworks.forEach(fw => {
                            const opt = document.createElement('option');
                            opt.value = String(fw.id);
                            opt.textContent = fw.shortname || fw.name || fw.idnumber || `#${fw.id}`;
                            opt.dataset.shortname = fw.shortname || '';
                            opt.dataset.idnumber = fw.idnumber || '';
                            select.appendChild(opt);
                        });
                    }
                }
            } catch (error) {
                if (select) {
                    select.replaceChildren(this.createPlaceholderOption(strings.frameworkselection_error));
                }
                Notification.exception(error);
            }

            const continueBtn = this.aiDrawerBodyElement.querySelector(SELECTOR_CONTINUE);
            const sel        = this.aiDrawerBodyElement.querySelector(SELECTOR_PRESELECT);
            const backBtn    = this.aiDrawerBodyElement.querySelector(SELECTOR_BACK);
            const cancelBtn  = this.aiDrawerBodyElement.querySelector(SELECTOR_CANCEL);

            if (continueBtn) {
                continueBtn.disabled = true;

                const updateContinueState = () => {
                    const hasValue = !!(sel && sel.value && !sel.options[sel.selectedIndex]?.disabled);
                    continueBtn.disabled = !hasValue;
                };
                if (sel) {
                    sel.addEventListener('change', updateContinueState);
                }

                updateContinueState();

                continueBtn.addEventListener('click', e => {
                    e.preventDefault();
                    if (continueBtn.disabled) {
                        return;
                    }

                    const opt = sel && sel.selectedOptions ? sel.selectedOptions[0] : null;

                    this._selectedFrameworkId        = sel && sel.value ? Number(sel.value) : null;
                    this._selectedFrameworkShortname = opt
                        ? (opt.dataset.shortname || opt.textContent.trim())
                        : null;

                    const selectedFramework = {
                        id: this._selectedFrameworkId,
                        shortname: this._selectedFrameworkShortname,
                    };
                    this._selectedFramework = selectedFramework;

                    this.showLevels(prompt, selectedFramework);
                });

            }

            if (backBtn) {
                backBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.toggleAIDrawer();
                    this.aiDrawerBodyElement.innerHTML = '';
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.setRequestCancelled();
                    this.toggleAIDrawer();
                    this.aiDrawerBodyElement.innerHTML = '';
                });
            }
        }

        // === STEP 2: Select levels (checkboxes) ===
        async showLevels(prompt, framework) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const strings = await this.getStrings();

            const html = await Templates.render('aiplacement_competency/levels', { options: [] });
            this.aiDrawerBodyElement.innerHTML = html;

            let competencies = [];
            let loadfailed = false;

            try {
            const args = {
                filters: [
                { column: 'competencyframeworkid', value: String(framework.id) },
                { column: 'parentid',              value: '0' }
                ],
                sort: 'shortname',
                order: 'ASC',
                skip: 0,
                limit: 0
            };

            const requests = Ajax.call([{
                methodname: 'core_competency_list_competencies',
                args
            }]);

            const res = await requests[0];

            competencies = Array.isArray(res?.competencies) ? res.competencies
                        : Array.isArray(res) ? res
                        : [];

            competencies = competencies.filter(c => Number(c.parentid || 0) === 0);

            } catch (error) {
            Notification.exception(error);
            competencies = [];
            loadfailed = true;
            }

            const options = competencies.map(c => {
            const raw =
                (c.shortname || '').trim() ||
                (c.competencyname && c.competencyname.trim?.()) ||
                (c.idnumber || '').trim() ||
                (c.description && c.description.replace(/<[^>]+>/g, '').trim()) ||
                `#${c.id}`;

            const label = raw
                .replace(/\b[A-Z]{4,}\b/g, w => w.charAt(0) + w.slice(1).toLowerCase());

            return {
                id: `level-${c.shortname}`,
                value: String(c.shortname),
                label,
            };
            });

            const finalHtml = await Templates.render('aiplacement_competency/levels', { options });
            this.aiDrawerBodyElement.innerHTML = finalHtml;

            if (loadfailed) {
                // Insert after the final render, otherwise the render wipes the message out.
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger mb-3';
                alert.textContent = strings.levelsselection_error;
                this.aiDrawerBodyElement.querySelector('.ai-prestep')?.prepend(alert);
            }

            const cancelBtn   = this.aiDrawerBodyElement.querySelector(SELECTOR_CANCEL);
            const backBtn     = this.aiDrawerBodyElement.querySelector(SELECTOR_BACK);
            const continueBtn = this.aiDrawerBodyElement.querySelector(SELECTOR_CONTINUE);
            const boxes       = this.aiDrawerBodyElement.querySelectorAll(SELECTOR_LEVELS);

            if (Array.isArray(this._selectedLevels) && this._selectedLevels.length) {
                const set = new Set(this._selectedLevels.map(String));
                boxes.forEach(b => { b.checked = set.has(b.value); });
            }

            const updateContinue = () => {
                const anyChecked = Array.from(boxes).some(b => b.checked);
                if (continueBtn) {
                    continueBtn.disabled = !anyChecked;
                }
            };
            boxes.forEach(b => b.addEventListener('change', updateContinue));
            updateContinue();

            // Select all / Clear all handlers
            this.aiDrawerBodyElement.addEventListener('click', e => {
                const selectAllBtn = e.target.closest('[data-action="selectall"]');
                if (selectAllBtn) {
                    e.preventDefault();
                    const sec = selectAllBtn.closest('.aiplacement-applycmps-section');
                    if (sec) {
                        sec.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                            cb.checked = true;
                        });
                        updateContinue();
                    }
                }

                const clearAllBtn = e.target.closest('[data-action="clearall"]');
                if (clearAllBtn) {
                    e.preventDefault();
                    const sec = clearAllBtn.closest('.aiplacement-applycmps-section');
                    if (sec) {
                        sec.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                            cb.checked = false;
                        });
                        updateContinue();
                    }
                }
            });

            if (continueBtn) {
                continueBtn.addEventListener('click', e => {
                    e.preventDefault();
                    if (continueBtn.disabled) {
                        return;
                    }

                    const selectedLevels = Array.from(boxes)
                        .filter(b => b.checked)
                        .map(b => b.value);

                    this._selectedLevels = selectedLevels;

                    this.sendClassification(prompt, framework, selectedLevels);
                });
            }

            if (backBtn) {
                backBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.showSelectFramework(prompt);
                });
            }

            if (cancelBtn) {
                cancelBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.setRequestCancelled();
                    this.toggleAIDrawer();
                    this.aiDrawerBodyElement.innerHTML = '';
                });
            }
        }

        // === Final: send classification ===
        async sendClassification(prompt, framework, levels) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const loadingHtml = await Templates.render('aiplacement_competency/loading', {});
            this.aiDrawerBodyElement.innerHTML = loadingHtml;

            const cancelBtn = this.aiDrawerBodyElement.querySelector(SELECTOR_CANCEL);
            if (cancelBtn) {
                cancelBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.setRequestCancelled();
                    this.toggleAIDrawer();
                    this.aiDrawerBodyElement.innerHTML = '';
                });
            }

            try {
                const fw = framework || this._selectedFramework || {
                    id: this._selectedFrameworkId,
                    shortname: this._selectedFrameworkShortname,
                };

                const rawSelectedLevels =
                (Array.isArray(levels) && levels.length) ? levels :
                (Array.isArray(this._selectedLevels) && this._selectedLevels.length) ? this._selectedLevels :
                [];

                // Dedupe case insensitively, but send each level as the framework spells
                // it. classify_text echoes these straight back as usedlevels and the
                // response panel prints them verbatim, so folding the case here is what
                // used to make a level read as 'attack t101 (demo collision)'.
                const seenLevels = new Set();
                const selectedLevels = rawSelectedLevels
                    .map(s => String(s).trim().replace(/\s+/g, ' '))
                    .filter(Boolean)
                    .filter(s => {
                        const key = s.toLowerCase();
                        if (seenLevels.has(key)) {
                            return false;
                        }
                        seenLevels.add(key);
                        return true;
                    });

                const calls = Ajax.call([{
                    methodname: 'aiplacement_competency_classify_text',
                    args: {
                        contextid: this.contextId,
                        prompttext: prompt,
                        selectedframeworkid: fw?.id || 0,
                        selectedframeworkshortname: fw?.shortname || '',
                        levels: selectedLevels,
                    }
                }]);

                const result = await calls[0];
                if (this.isRequestCancelled()) {
                    this.aiDrawerBodyElement.dataset.cancelled = '0';
                    return;
                }

                const {frameworkid, frameworkshortname, usedlevels = [], competencies = []} = result;
                const uniqid  = 'resp-' + Math.random().toString(36).slice(2, 11);
                const heading = await Str.get_string('classifyheading', 'aiplacement_competency');

                const responseHtml = await Templates.render(
                    'aiplacement_competency/response',
                    {
                        heading,
                        action: heading,
                        uniqid,
                        frameworkid,
                        frameworkshortname,
                        usedlevels,
                        competencies,
                    }
                );

                this.aiDrawerBodyElement.innerHTML = responseHtml;

                const regen = this.aiDrawerBodyElement.querySelector('[data-action="regenerate"]');
                if (regen) {
                    regen.addEventListener('click', e => {
                        e.preventDefault();
                        this.sendClassification(prompt, fw, selectedLevels);
                    });
                }
            } catch (error) {
                if (!this.isRequestCancelled()) {
                    const errorHtml = await Templates.render('aiplacement_competency/error', {});
                    this.aiDrawerBodyElement.innerHTML = errorHtml;
                    Notification.exception(error);
                }
            } finally {
                this.aiDrawerBodyElement.dataset.cancelled = '0';
            }
        }
    };
});
