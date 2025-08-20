define([
    'aiplacement_courseassist/placement', // Parent class
    'core/templates',
    'core/ajax',
    'core/notification',
    'core/str'
], function(Base, Templates, Ajax, Notification, Str) {

    const SELECTOR_CLASSIFY = '[data-action="classify"]';
    const SELECTOR_CANCEL   = '[data-action="cancel"]';
    const ID_CLOSE_BTN      = '#ai-classify-drawer-close';

    return class ClassifyPlacement extends Base {
        constructor(userId, contextId) {
            super(userId, contextId);

            this.aiDrawerElement     = document.querySelector('#ai-classify-drawer');
            this.aiDrawerBodyElement = this.aiDrawerElement.querySelector('.ai-drawer-body');
            this.aiDrawerCloseElement = this.aiDrawerElement.querySelector(ID_CLOSE_BTN);
            if (this.aiDrawerCloseElement) {
                this.aiDrawerCloseElement.addEventListener('click', e => {
                    e.preventDefault();
                    this.closeAIDrawer();
                });
            }

            this.registerExtraListener();
        }

        registerExtraListener() {
            document.addEventListener('click', async e => {
                const btn = e.target.closest(SELECTOR_CLASSIFY);
                if (!btn) {
                    return;
                }
                e.preventDefault();
                this.openAIDrawer();

                let prompt = '';
                const tex = document.querySelector('#id_introeditor');
                if (tex) {
                    prompt = tex.value.trim();
                } else {
                    prompt = this.getTextContent().trim();
                }

                if (!prompt) {
                    Notification.error('No content found to classify.');
                    return;
                }

                this.sendClassification(prompt);
            });
        }

        async sendClassification(prompt) {
            if (!prompt) {
                return Notification.error('No prompt provided.');
            }

            // Reset any stale cancel state from a previous run.
            this.aiDrawerBodyElement.dataset.cancelled = '0';

            const loadingHtml = await Templates.render('aiplacement_classifyassist/loading', {});
            this.aiDrawerBodyElement.innerHTML = loadingHtml;

            // Wire up "Cancel" in the loading view.
            const cancelBtn = this.aiDrawerBodyElement.querySelector(SELECTOR_CANCEL);
            if (cancelBtn) {
                cancelBtn.addEventListener('click', e => {
                    e.preventDefault();
                    this.setRequestCancelled();   // mark as cancelled
                    this.toggleAIDrawer();        // close (or reopen) drawer
                    this.aiDrawerBodyElement.innerHTML = ''; // clear loading UI
                });
            }

            try {
                const calls = Ajax.call([{
                    methodname: 'aiplacement_classifyassist_classify_text',
                    args: {
                        contextid: this.contextId,
                        prompttext: prompt
                    }
                }]);

                const result = await calls[0];

                if (this.isRequestCancelled()) {
                    this.aiDrawerBodyElement.dataset.cancelled = '0';
                    return;
                }

                const {frameworkid, frameworkshortname, tasks, skills, knowledge } = result;

                const uniqid  = 'resp-' + Math.random().toString(36).substr(2, 9);
                const heading = await Str.get_string('classifyheading', 'aiplacement_classifyassist');

                const responseHtml = await Templates.render(
                    'aiplacement_classifyassist/response',
                    { heading, action: heading, uniqid, frameworkid, frameworkshortname, tasks, skills, knowledge }
                );

                this.aiDrawerBodyElement.innerHTML = responseHtml;

                const regen = this.aiDrawerBodyElement.querySelector('[data-action="regenerate"]');
                if (regen) {
                    regen.addEventListener('click', e => {
                        e.preventDefault();
                        this.sendClassification(prompt);
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
