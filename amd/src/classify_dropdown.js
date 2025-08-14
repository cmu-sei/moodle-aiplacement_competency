define(['core/templates','jquery'], function(Templates, $) {
    return {
      init() {
        $(document).ready(async () => {
          const $menu = $('#ai-features .dropdown-menu');
          if (!$menu.length) {
            return;
          }
          $menu.removeClass('dropdown-menu-end')
               .addClass('dropdown-menu-start');

          const html = await Templates.render(
            'aiplacement_classifyassist/classify_button', {}
          );
          $menu.append(html);
        });
      }
    };
});
