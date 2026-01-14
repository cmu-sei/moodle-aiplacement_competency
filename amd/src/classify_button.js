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

                Templates.renderForPromise('aiplacement_competency/classify_button', {})
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
