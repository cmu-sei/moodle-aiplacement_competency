define([
  'core/modal',
  'core/modal_factory',
  'core/modal_events',
  'core/templates',
  'core/str',
  'core/ajax',
  'jquery',
  'core/notification'
], function(Modal, ModalFactory, ModalEvents, Templates, Str, Ajax, $, Notification) {

  var toArray = function(nl) { return Array.prototype.slice.call(nl || []); };

  var RELOAD_COURSE_KEY = 'aiplacement:coursePostReloadNotices';
  var RELOAD_CM_KEY     = 'aiplacement:cmPostReloadNotices';

  // Save the competencies that were added, exists, or failed to be added to the course
  function stashCourseNotices(added, exists, failed) {
    try {
      sessionStorage.setItem(RELOAD_COURSE_KEY, JSON.stringify({
        added: added || [], exists: exists || [], failed: failed || []
      }));
    } catch (e) {}
  }
  // Save the competencies that were added, exists, or failed to be added to the activity
  function stashCmNotices(added, exists, failed) {
    try {
      sessionStorage.setItem(RELOAD_CM_KEY, JSON.stringify({
        added: added || [], exists: exists || [], failed: failed || []
      }));
    } catch (e) {}
  }

  // Show notices with added, exists, or failed competencies with green, yellow, and red notifications
  function showNoticesFrom(key, headings) {
    var raw = null;
    try { raw = sessionStorage.getItem(key); } catch (e) {}
    if (!raw) {
      return;
    }
    try { sessionStorage.removeItem(key); } catch (e) {}

    var data = {};
    try { data = JSON.parse(raw) || {}; } catch (e) {}
    var added  = Array.isArray(data.added)  ? data.added  : [];
    var exists = Array.isArray(data.exists) ? data.exists : [];
    var failed = Array.isArray(data.failed) ? data.failed : [];

    var jobs = [];
    var esc = function (s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    };
    var renderList = function (items) {
      return '<ul class="mb-0 mt-1">' +
        items.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') +
      '</ul>';
    };

    if (added.length) {
      jobs.push(
        Str.get_string(headings.added.key, headings.added.comp, { count: added.length })
          .catch(function(){ return headings.added.fallback(added.length); })
          .then(function(h) { Notification.addNotification({ type: 'success', message: '<div>' + esc(h) + '</div>' + renderList(added) }); })
      );
    }
    if (exists.length) {
      jobs.push(
        Str.get_string(headings.exists.key, headings.exists.comp, { count: exists.length })
          .catch(function(){ return headings.exists.fallback(exists.length); })
          .then(function(h) { Notification.addNotification({ type: 'warning', message: '<div>' + esc(h) + '</div>' + renderList(exists) }); })
      );
    }
    if (failed.length) {
      jobs.push(
        Str.get_string(headings.failed.key, headings.failed.comp, { count: failed.length })
          .catch(function(){ return headings.failed.fallback(failed.length); })
          .then(function(h) { Notification.addNotification({ type: 'error', message: '<div>' + esc(h) + '</div>' + renderList(failed) }); })
      );
    }
    Promise.allSettled(jobs);
  }

  // Call these after load to show course notifications once the page refreshes
  function showPostReloadCourseNoticesIfAny() {
    showNoticesFrom(RELOAD_COURSE_KEY, {
      added : { key: 'notify_course_added_heading',  comp:'aiplacement_classifyassist', fallback: c => 'Course competencies added (' + c + ')' },
      exists: { key: 'notify_course_exists_heading', comp:'aiplacement_classifyassist', fallback: c => 'Already in course (' + c + ')' },
      failed: { key: 'notify_course_failed_heading', comp:'aiplacement_classifyassist', fallback: c => 'Failed to add to course (does not match selected framework) (' + c + ')' }
    });
  }

  // Call these after load to show activity notifications once the page refreshes
  function showPostReloadCmNoticesIfAny() {
    showNoticesFrom(RELOAD_CM_KEY, {
      added : { key: 'notify_cm_added_heading',  comp:'aiplacement_classifyassist', fallback: c => 'Added to activity (' + c + ')' },
      exists: { key: 'notify_cm_exists_heading', comp:'aiplacement_classifyassist', fallback: c => 'Already linked to activity (' + c + ')' },
      failed: { key: 'notify_cm_failed_heading', comp:'aiplacement_classifyassist', fallback: c => 'Failed to link to activity (' + c + ')' }
    });
  }

  var getModalAPI = function() {
    var hasNew = Modal && typeof Modal.create === 'function';
    return {
      create: hasNew ? Modal.create.bind(Modal)
        : (ModalFactory && ModalFactory.create.bind(ModalFactory)),
      types: (hasNew && (Modal.types || Modal.TYPE)) ||
        (ModalFactory && ModalFactory.types) ||
        null
    };
  };

  // Get the AI response
  var extractFromResponse = function(root) {
    var out = { competencies: [] };
    if (!root) {
      return out;
    }

    var dd = root.querySelector('dd[data-section="competencies"]');
    if (!dd) {
      return out;
    }

    out.competencies = toArray(dd.querySelectorAll('li'))
      .map(function(li) { return (li.innerText || '').trim(); })
      .filter(Boolean)
      .filter(function(s) { return s.toLowerCase() !== 'none'; });

    return out;
  };

  // Get the user checked competencies from the modal
  var collectChecked = function(root) {
    var sel = '.aiplacement-applycmps-section[data-section="competencies"] input[type="checkbox"]:checked';
    var arr = toArray(root.querySelectorAll(sel)).map(function(cb) {
      var row = cb.closest('label');
      var span = row && row.querySelector('span');
      return span ? span.textContent.trim() : null;
    }).filter(Boolean);
    return { competencies: arr };
  };

  // Add checkboxes, select all, clear all
  var attachControls = function($root) {
    $root.on('click', '[data-action="selectall"]', function(e) {
      e.preventDefault();
      var sec = e.currentTarget.closest('.aiplacement-applycmps-section');
      if (sec) {
        toArray(sec.querySelectorAll('input[type="checkbox"]')).forEach(function(cb){ cb.checked = true; });
      }
    });
    $root.on('click', '[data-action="clearall"]', function(e) {
      e.preventDefault();
      var sec = e.currentTarget.closest('.aiplacement-applycmps-section');
      if (sec) {
        toArray(sec.querySelectorAll('input[type="checkbox"]')).forEach(function(cb){ cb.checked = false; });
      }
    });
    $root.on('click', '[data-action="apply-inline"]', function(e) {
      e.preventDefault();
      $root.trigger(ModalEvents.save);
    });
  };

  var norm = function(s) {
    return (s === null ? '' : String(s)).toLowerCase().trim();
  };

  // Matches the competencies selected from the modal with the system's competencies
  var matchPickedToCompetencies = function(frameworkId, picked) {
    return new Promise(function(resolve, reject) {
      var calls = Ajax.call([{
        methodname: 'core_competency_list_competencies',
        args: {
          filters: [{ column: 'competencyframeworkid', value: String(frameworkId) }],
          sort: 'shortname', order: 'ASC', skip: 0, limit: 0
        }
      }]);

      calls[0].done(function(response) {
        var list = Array.isArray(response) ? response : (response.competencies || []);
        var byIdnumber = new Map();
        var byShortExact = new Map();
        list.forEach(function(c) {
          var idn = norm(c.idnumber);
          var sn  = norm(c.shortname);
          if (idn) {
            byIdnumber.set(idn, c);
          }
          if (sn)  {
            byShortExact.set(sn, c);
          }
        });

        var inputs = [].concat(picked.competencies || []);
        var matches = inputs.map(function(str) {
          var original = str || '';
          var parts = original.split(' - ');
          var code = norm(parts[0] || '');
          var name = norm(parts.slice(1).join(' - '));
          var comp = null;

          if (code && byIdnumber.has(code)) {
            comp = byIdnumber.get(code);
          }

          if (!comp && name && byShortExact.has(name)) {
            comp = byShortExact.get(name);
          }

          if (!comp && name) {
            comp = list.find(function(c) {
              return norm(c.description).includes(name);
            });
          }

          if (!comp && name) {
            comp = list.find(function(c) {
              return norm(c.shortname).includes(name);
            });
          }
                    return comp ?
            { input: original, id: comp.id, idnumber: comp.idnumber, shortname: comp.shortname } :
            { input: original, id: null };
        });

        var matchedIds = matches.filter(function(m){ return m.id !== null; }).map(function(m){ return m.id; });
        resolve({ matches: matches, matchedIds: matchedIds, list: list });
      }).fail(reject);
    });
  };
  function getCourseIdFromBody() {
    var cls = (document.body && document.body.className) || '';
    var m = cls.match(/(?:^|\s)course-(\d+)(?:\s|$)/);
    return m ? parseInt(m[1], 10) : null;
  }

  // Grab cmid from the URL (activity edit page uses ?update=<cmid>)
  function getCmidFromUrl() {
    var qs = new URLSearchParams(window.location.search);
    return Number(qs.get('update') || 0);
  }

  function addToModule(cmid, competencyId) {
    return Ajax.call([{
      methodname: 'aiplacement_classifyassist_add_cm_competency',
      args: { cmid: cmid, competencyid: competencyId }
    }])[0];
  }

  // Modal that shows the ai response competencies to the user, here the user will be able to select which should be added to the course
  var openModal = function(values) {
    return Str.get_string('applycmps_title', 'aiplacement_classifyassist').then(function(title) {
      return Templates.render('aiplacement_classifyassist/applycmps_modal', {
        competencies: values.competencies || [],
        hascompetencies: !!(values.competencies && values.competencies.length)
      }).then(function(html, js) {
        var api = getModalAPI();
        if (!api.create) {
          throw new Error('No modal API available');
        }

        var opts = { title: title, body: html, large: true };
        if (api.types && api.types.SAVE_CANCEL) {
          opts.type = api.types.SAVE_CANCEL;
        }

        return api.create(opts).then(function(modal) {
          if (js) {
            Templates.runTemplateJS(js);
          }
          attachControls(modal.getRoot());

          return new Promise(function(resolve, reject) {
            modal.getRoot().on(ModalEvents.save, function() {
              try {
                var root = modal.getRoot()[0];
                var picked = collectChecked(root);
                modal.getRoot().data('selection', picked);
                sessionStorage.setItem('aiplacement_applycmps:last', JSON.stringify(picked));

                var frameworkId = String($('dd[data-frameworkid]').data('frameworkid') || '0');

                matchPickedToCompetencies(frameworkId, picked).then(function(res) {
                  var sel = modal.getRoot().data('selection') || {};
                  sel.matchedCompetencyIds = res.matchedIds;
                  modal.getRoot().data('selection', sel);

                  var all = Array.isArray(res.matches) ? res.matches : [];
                  var matched   = all.filter(function(m){ return m && m.id !== null; });
                  var unmatched = all.filter(function(m){ return !m || m.id === null; });
                  var unmatchedLabels = unmatched.map(function(m){ return (m && m.input) ? String(m.input).trim() : ''; }).filter(Boolean);

                  var courseId = getCourseIdFromBody();
                  if (!courseId) {
                    Str.get_string('notify_nocourseid', 'aiplacement_classifyassist').then(function(msg) {
                      Notification.addNotification({ type: 'warning', message: msg });
                    });
                    resolve(picked);
                    modal.hide();
                    return;
                  }

                  // Add to course
                  var calls = res.matchedIds.map(function(id) {
                    return { methodname: 'core_competency_add_competency_to_course', args: { courseid: courseId, competencyid: id } };
                  });
                  var reqs = Ajax.call(calls);
                  Promise.all(reqs.map(function(p, idx) {
                    return Promise.resolve(p).then(function(r){ return { id: res.matchedIds[idx], result: r }; })
                      .catch(function(e){ return { id: res.matchedIds[idx], error: e }; });
                  })).then(function(outcomes){
                    var byId = new Map();
                    matched.forEach(function(m) {
                      if (m.id) {
                        byId.set(m.id, m);
                      }
                    });
                    var added=[], exists=[], failed=[];
                    outcomes.forEach(function(o){
                     if (o.error) {
                        failed.push(o.id);
                        return;
                      }

                      if (o.result === false) {
                        exists.push(o.id);
                      } else if (o.result === true || o.result === 1 || o.result === '1') {
                        added.push(o.id);
                      } else {
                        (o.result ? added : exists).push(o.id);
                      }

                    });

                    var nameOf = function(id){ var m = byId.get(id); return m ? (m.shortname || m.input || ('ID ' + id)) : ('ID ' + id); };
                    stashCourseNotices(added.map(nameOf), exists.map(nameOf), failed.map(nameOf).concat(unmatchedLabels));

                    // Also link to CM if we're editing a module
                    var cmid = getCmidFromUrl();
                    var toLink = Array.from(new Set([].concat(added, exists)));
                    var linkPromise = Promise.resolve();
                    if (cmid > 0 && toLink.length) {
                      var byIdName = new Map();
                      matched.forEach(function(m){
                        if (m && m.id) {
                          byIdName.set(m.id, m.shortname || m.input || ('ID ' + m.id));
                        }
                      });
                      linkPromise = Promise.all(toLink.map(function(id){
                        return addToModule(cmid, id).then(function(ok){ return { id:id, status: ok ? 'added' : 'exists' }; })
                          .catch(function(){ return { id:id, status:'failed' }; });
                      })).then(function(results){
                        var addedIds=[], existsIds=[], failedIds=[];
                        results.forEach(function(r){
                          if (r.status === 'added') {
                            addedIds.push(r.id);
                          } else if (r.status === 'exists') {
                            existsIds.push(r.id);
                          } else {
                            failedIds.push(r.id);
                          }
                        });
                        stashCmNotices(
                          addedIds.map(function(id){ return byIdName.get(id) || ('ID ' + id); }),
                          existsIds.map(function(id){ return byIdName.get(id) || ('ID ' + id); }),
                          failedIds.map(function(id){ return byIdName.get(id) || ('ID ' + id); })
                        );
                      });
                    }
                    linkPromise.then(function(){ window.location.reload(); });
                  }).catch(function(err){
                    Notification.exception(err);
                    resolve(picked);
                    modal.hide();
                  });
                }).catch(reject);
              } catch (err) {
                reject(err);
              }
            });
            modal.show();
          });
        });
      });
    });
  };

  var dispatchApply = function(payload, context) {
    var ev;
    try {
      ev = new CustomEvent('aiplacement_classifyassist:apply', { detail: payload, bubbles: true });
    } catch (e) {
      ev = document.createEvent('CustomEvent');
      ev.initCustomEvent('aiplacement_classifyassist:apply', true, false, payload);
    }
    (context || document).dispatchEvent(ev);
  };

  var handleClick = function(btn) {
    var card = btn.closest('.card-text.content');
    if (!card) {
      return;
    }
    var content = card.querySelector('.course-assist-response-content');
    var values = extractFromResponse(content); // { competencies: [...] }
    openModal(values).then(function(edited) {
      if (!edited) {
        return;
      }
      var contentEl = card.querySelector('[id^="course-assist-response-content-"]');
      var uniqid = contentEl ? contentEl.id : null;
      dispatchApply({ uniqid: uniqid, action: 'applycmps', values: edited }, contentEl || document);
    });
  };

  var init = function() {
    document.addEventListener('click', function(e) {
      var btn = e.target.closest('button[data-action="applycmps"]');
      if (!btn) {
        return;
      }
      e.preventDefault();
      handleClick(btn);
    }, { passive: false });
  };

  // Show notices after reloads
  showPostReloadCourseNoticesIfAny();
  showPostReloadCmNoticesIfAny();

  return { init: init };
});
