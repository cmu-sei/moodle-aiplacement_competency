/*
AI Placement Plugin for Moodle Competencies

Copyright 2026 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS. 
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO, 
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. 
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM26-0017
*/
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

        registerExtraListener() {
            document.addEventListener('click', async e => {
                // 1) Retry inside the drawer
                const retryBtn = e.target.closest(SELECTOR_RETRY);
                if (retryBtn && this.aiDrawerElement.contains(retryBtn)) {
                    e.preventDefault();
                    retryBtn.disabled = true;
                    try {
                        // Read activity description
                        const ta = document.querySelector('#id_introeditor') || document.querySelector('[name="intro[text]"]');
                        const ce = document.querySelector('[id^="id_introeditor"][contenteditable="true"]');
                        const prompt =
                            (ta && typeof ta.value === 'string' ? ta.value.trim() : '') ||
                            (ce && ce.textContent ? ce.textContent.trim() : '');

                        if (!prompt) {
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

                // If you want to require description here too, use the same strict read:
                const ta = document.querySelector('#id_introeditor') || document.querySelector('[name="intro[text]"]');
                const ce = document.querySelector('[id^="id_introeditor"][contenteditable="true"]');
                const prompt =
                    (ta && typeof ta.value === 'string' ? ta.value.trim() : '') ||
                    (ce && ce.textContent ? ce.textContent.trim() : '');

                if (!prompt) {
                    Str.get_string('notify_empty_description', 'aiplacement_competency')
                        .catch(() => 'No content found to classify.')
                        .then(msg => Notification.addNotification({ type: 'error', message: msg }));

                    const errorHtml = await Templates.render('aiplacement_competency/error', {});
                    this.aiDrawerBodyElement.innerHTML = errorHtml;
                    return;
                }

                await this.showSelectFramework(prompt);
            });
        }

        // === STEP 1: Select framework ===
        async showSelectFramework(prompt) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const prestepHtml = await Templates.render('aiplacement_competency/framework_select', {});
            this.aiDrawerBodyElement.innerHTML = prestepHtml;

            const select = this.aiDrawerBodyElement.querySelector(SELECTOR_PRESELECT);
            if (select) {
                select.innerHTML = '<option value="" disabled selected>Loading…</option>';
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
                    select.innerHTML = '';

                    if (!frameworks.length) {
                        select.innerHTML = '<option value="" disabled selected>No frameworks found</option>';
                    } else {
                        const ph = document.createElement('option');
                        ph.value = '';
                        ph.disabled = true;
                        ph.selected = true;
                        ph.textContent = 'Select Competency Framework...';
                        select.appendChild(ph);

                        frameworks.forEach(fw => {
                            const opt = document.createElement('option');
                            opt.value = String(fw.id);
                            opt.textContent = fw.shortname || fw.name || fw.idnumber || `Framework #${fw.id}`;
                            opt.dataset.shortname = fw.shortname || '';
                            opt.dataset.idnumber = fw.idnumber || '';
                            select.appendChild(opt);
                        });
                    }
                }
            } catch (error) {
                if (select) {
                    select.innerHTML = '<option value="" disabled selected>Failed to load frameworks</option>';
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

            const html = await Templates.render('aiplacement_competency/levels', { options: [] });
            this.aiDrawerBodyElement.innerHTML = html;

            let competencies = [];

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
            this.aiDrawerBodyElement.querySelector('.ai-prestep')?.insertAdjacentHTML(
                'afterbegin',
                '<div class="alert alert-danger mb-3">Failed to load levels.</div>'
            );
            Notification.exception(error);
            competencies = [];
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

                const selectedLevels = [...new Set(
                rawSelectedLevels
                    .map(s => String(s).trim().replace(/\s+/g, ' '))
                    .filter(Boolean)
                    .map(s => s.toLowerCase())
                )];

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
                const uniqid  = 'resp-' + Math.random().toString(36).substr(2, 9);
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
