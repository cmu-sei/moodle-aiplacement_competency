define([
  'core/modal',
  'core/modal_factory',
  'core/modal_events',
  'core/templates',
  'core/str',
  'core/notification',
  'core/ajax'
], function(Modal, ModalFactory, ModalEvents, Templates, Str, Notification, Ajax) {

  var toArray = function(nl){ return Array.prototype.slice.call(nl || []); };
  var MAP = { tasks: 'tasks', skills: 'skills', knowledge: 'knowledge' };

  var getModalAPI = function() {
    var hasNew = Modal && typeof Modal.create === 'function';
    return {
      create: hasNew ? Modal.create.bind(Modal)
                     : (ModalFactory && ModalFactory.create.bind(ModalFactory)),
      types:  (hasNew && (Modal.types || Modal.TYPE)) ||
              (ModalFactory && ModalFactory.types) ||
              null
    };
  };

  var extractFromResponse = function(root){
    var out = { tasks: [], skills: [], knowledge: [] };
    if (!root) { return out; }
    var dl = root.querySelector('dl');
    if (!dl) { return out; }

    toArray(dl.querySelectorAll('dt')).forEach(function(dt){
      var key = MAP[(dt.textContent || '').trim().toLowerCase()];
      if (!key) { return; }
      var dd = dt.nextElementSibling;
      if (!dd || dd.tagName.toLowerCase() !== 'dd') { return; }

      out[key] = toArray(dd.querySelectorAll('li'))
        .map(function(li){ return (li.innerText || '').trim(); })
        .filter(Boolean)
        .filter(function(s){ return s.toLowerCase() !== 'none'; });
    });
    return out;
  };

  var collectChecked = function(root){
    var pick = function(section){
      var sel = '.aiplacement-applytsks-section[data-section="' + section + '"] input[type="checkbox"]:checked';
      return toArray(root.querySelectorAll(sel)).map(function(cb){
        var row = cb.closest('label'); var span = row && row.querySelector('span');
        return span ? span.textContent.trim() : null;
      }).filter(Boolean);
    };
    return { tasks: pick('tasks'), skills: pick('skills'), knowledge: pick('knowledge') };
  };

  var attachControls = function($root){
    $root.on('click', '[data-action="selectall"]', function(e){
      e.preventDefault();
      var sec = e.currentTarget.closest('.aiplacement-applytsks-section');
      if (sec) { toArray(sec.querySelectorAll('input[type="checkbox"]')).forEach(function(cb){ cb.checked = true; }); }
    });
    $root.on('click', '[data-action="clearall"]', function(e){
      e.preventDefault();
      var sec = e.currentTarget.closest('.aiplacement-applytsks-section');
      if (sec) { toArray(sec.querySelectorAll('input[type="checkbox"]')).forEach(function(cb){ cb.checked = false; }); }
    });

    $root.on('click', '[data-action="apply-inline"]', function(e){
      e.preventDefault();
      $root.trigger(ModalEvents.save);
    });
  };

    var openModal = function(values){
        return Str.get_string('applytsks_title', 'aiplacement_classifyassist').then(function(title){
            return Templates.render('aiplacement_classifyassist/applytsks_modal', {
            tasks: values.tasks || [],
            skills: values.skills || [],
            knowledge: values.knowledge || [],
            hastasks: !!(values.tasks && values.tasks.length),
            hasskills: !!(values.skills && values.skills.length),
            hasknowledge: !!(values.knowledge && values.knowledge.length)
            }).then(function(html, js){
            var api = getModalAPI();
            if (!api.create) { throw new Error('No modal API available'); }

            var opts = { title: title, body: html, large: true };
            if (api.types && api.types.SAVE_CANCEL) { opts.type = api.types.SAVE_CANCEL; }

            return api.create(opts).then(function(modal){
                if (js) { Templates.runTemplateJS(js); }
                attachControls(modal.getRoot());

                return new Promise(function(resolve, reject){
                modal.getRoot().on(ModalEvents.save, function(){
                    try {
                    var root = modal.getRoot()[0];
                    var picked = collectChecked(root);
                    modal.getRoot().data('selection', picked);
                    sessionStorage.setItem('aiplacement_applytsks:last', JSON.stringify(picked));
                    console.log('Saved selection:', picked);

                    //List competencies
                    const calls = Ajax.call([{
                      methodname: 'core_competency_list_competencies',
                    }]);

                    calls[0].done(function(response) {
                      console.log("Competencies response:", response);
                    }).fail(function(err) {
                      console.error("Competencies error:", err);
                    });

                    resolve(collectChecked(root));
                    modal.hide();
                    } catch (err) {
                    Notification.exception(err);
                    reject(err);
                    }
                });
                modal.show();
                });
            });
            });
        });
    };

  var dispatchApply = function(payload, context){
    var ev;
    try { ev = new CustomEvent('aiplacement_classifyassist:apply', { detail: payload, bubbles: true }); }
    catch (e) { ev = document.createEvent('CustomEvent'); ev.initCustomEvent('aiplacement_classifyassist:apply', true, false, payload); }
    (context || document).dispatchEvent(ev);
  };

  var handleClick = function(btn){
    var card = btn.closest('.card-text.content');
    if (!card) { return; }
    var content = card.querySelector('.course-assist-response-content');
    var values = extractFromResponse(content);

    openModal(values).then(function(edited){
      if (!edited) { return; }
      var contentEl = card.querySelector('[id^="course-assist-response-content-"]');
      var uniqid = contentEl ? contentEl.id : null;
      dispatchApply({ uniqid: uniqid, action: 'applytsks', values: edited }, contentEl || document);
    });
  };

  var init = function(){
    document.addEventListener('click', function(e){
      var btn = e.target.closest('button[data-action="applytsks"]');
      if (!btn) { return; }
      e.preventDefault();
      handleClick(btn);
    }, { passive: false });
  };

  return { init: init };
});
