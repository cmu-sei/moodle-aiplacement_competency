define(['core/templates', 'jquery', 'core/notification'], function(Templates, $, Notification) {
    return {
        init() {
            $(function() {
                const $container = $('#id_competenciessectioncontainer');
                if (!$container.length) {
                    return;
                }
                if ($container.data('classify-btn-initialised')) {
                    return;
                }
                $container.data('classify-btn-initialised', true);

                Templates.renderForPromise('aiplacement_classifyassist/classify_button', {})
                    .then(function(result) {
                        var html = result.html;
                        var js = result.js;

                        $container.find('.fitem').last().after(html);

                        Templates.runTemplateJS(js);
                    })
                    .catch(Notification.exception);
            });
        }
    };
});
