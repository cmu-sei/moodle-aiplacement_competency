define(['core/templates', 'jquery', 'core/notification'], function(Templates, $, Notification) {
    return {
        init(message) {
            $(function() {
                const $container = $('#id_competenciessectioncontainer');
                if (!$container.length) {
                    return;
                }
                if ($container.data('newactivity-notice-initialised')) {
                    return;
                }
                $container.data('newactivity-notice-initialised', true);

                // Render our simple notice template and inject it at the top of the section.
                Templates.renderForPromise('aiplacement_competency/newactivity_notice', { message })
                    .then(function(result) {
                        const html = result.html;
                        const js = result.js;

                        $container.prepend(html);

                        Templates.runTemplateJS(js);
                    })
                    .catch(Notification.exception);
            });
        }
    };
});