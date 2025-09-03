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
    const SELECTOR_DOMAINS  = 'input[name="classify-domains"]';
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

            this._domains = [];
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
                            const errorHtml = await Templates.render('aiplacement_classifyassist/error', {});
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
                    Str.get_string('notify_empty_description', 'aiplacement_classifyassist')
                        .catch(() => 'No content found to classify.')
                        .then(msg => Notification.addNotification({ type: 'error', message: msg }));

                    const errorHtml = await Templates.render('aiplacement_classifyassist/error', {});
                    this.aiDrawerBodyElement.innerHTML = errorHtml;
                    return;
                }

                await this.showSelectFramework(prompt);
            });
        }

        // === STEP 1: Select framework ===
        async showSelectFramework(prompt) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const prestepHtml = await Templates.render('aiplacement_classifyassist/framework_select', {});
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

                    this.showDomains(prompt, selectedFramework);
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

        // === STEP 2: Select domains (checkboxes) ===
        async showDomains(prompt, framework) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const html = await Templates.render('aiplacement_classifyassist/domains', { options: [] });
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
                '<div class="alert alert-danger mb-3">Failed to load domains.</div>'
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
                id: `domain-${c.shortname}`,
                value: String(c.shortname),
                label,
            };
            });

            const finalHtml = await Templates.render('aiplacement_classifyassist/domains', { options });
            this.aiDrawerBodyElement.innerHTML = finalHtml;

            const cancelBtn   = this.aiDrawerBodyElement.querySelector(SELECTOR_CANCEL);
            const backBtn     = this.aiDrawerBodyElement.querySelector(SELECTOR_BACK);
            const continueBtn = this.aiDrawerBodyElement.querySelector(SELECTOR_CONTINUE);
            const boxes       = this.aiDrawerBodyElement.querySelectorAll(SELECTOR_DOMAINS);

            if (Array.isArray(this._selectedDomains) && this._selectedDomains.length) {
                const set = new Set(this._selectedDomains.map(String));
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

                    const selectedDomains = Array.from(boxes)
                        .filter(b => b.checked)
                        .map(b => b.value);

                    this._selectedDomains = selectedDomains;

                    this.sendClassification(prompt, framework, selectedDomains);
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
        async sendClassification(prompt, framework, domains) {
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const loadingHtml = await Templates.render('aiplacement_classifyassist/loading', {});
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

                const rawSelectedDomains =
                (Array.isArray(domains) && domains.length) ? domains :
                (Array.isArray(this._selectedDomains) && this._selectedDomains.length) ? this._selectedDomains :
                [];

                const selectedDomains = [...new Set(
                rawSelectedDomains
                    .map(s => String(s).trim().replace(/\s+/g, ' '))
                    .filter(Boolean)
                    .map(s => s.toLowerCase())
                )];

                const calls = Ajax.call([{
                    methodname: 'aiplacement_classifyassist_classify_text',
                    args: {
                        contextid: this.contextId,
                        prompttext: prompt,
                        selectedframeworkid: fw?.id || 0,
                        selectedframeworkshortname: fw?.shortname || '',
                        domains: selectedDomains,
                    }
                }]);

                const result = await calls[0];
                if (this.isRequestCancelled()) {
                    this.aiDrawerBodyElement.dataset.cancelled = '0';
                    return;
                }

                const {frameworkid, frameworkshortname, useddomains = [], competencies = []} = result;
                const uniqid  = 'resp-' + Math.random().toString(36).substr(2, 9);
                const heading = await Str.get_string('classifyheading', 'aiplacement_classifyassist');

                const responseHtml = await Templates.render(
                    'aiplacement_classifyassist/response',
                    {
                        heading,
                        action: heading,
                        uniqid,
                        frameworkid,
                        frameworkshortname,
                        useddomains,
                        competencies,
                    }
                );

                this.aiDrawerBodyElement.innerHTML = responseHtml;

                const regen = this.aiDrawerBodyElement.querySelector('[data-action="regenerate"]');
                if (regen) {
                    regen.addEventListener('click', e => {
                        e.preventDefault();
                        this.sendClassification(prompt, fw, selectedDomains);
                    });
                }
            } catch (error) {
                if (!this.isRequestCancelled()) {
                    const errorHtml = await Templates.render('aiplacement_classifyassist/error', {});
                    this.aiDrawerBodyElement.innerHTML = errorHtml;
                    Notification.exception(error);
                }
            } finally {
                this.aiDrawerBodyElement.dataset.cancelled = '0';
            }
        }
    };
});
