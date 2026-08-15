/**
 * Activity Builder client
 */
(function () {
  'use strict';

  const root = document.getElementById('activity-builder');
  const bootEl = document.getElementById('ab-bootstrap');
  if (!root || !bootEl) return;

  let boot;
  try {
    boot = JSON.parse(bootEl.textContent || '{}');
  } catch (e) {
    console.error('Invalid builder bootstrap');
    return;
  }

  const state = {
    activity: boot.activity || {},
    tree: boot.tree || { sections: [], questions: [], options_by_question: {} },
    revision: boot.revision || 1,
    selectedQuestionId: null,
    dirty: false,
    saving: false,
    validation: boot.validation || { errors: [], warnings: [] },
    debounceTimer: null,
    periodicTimer: null,
    changeVersion: 0,
    savePromise: null,
  };

  const csrf = root.dataset.csrf || '';
  const activityId = Number(root.dataset.activityId || boot.activity?.id || 0);
  const isFlashcardMode = root.dataset.flashcard === '1' || (boot.activity?.mode === 'flashcard');
  const recoveryKey = 'ab-recovery-' + activityId;

  const els = {
    list: root.querySelector('[data-ab-question-list]'),
    empty: root.querySelector('[data-ab-structure-empty]'),
    editor: root.querySelector('[data-ab-editor-form]'),
    editorEmpty: root.querySelector('[data-ab-editor-empty]'),
    validation: root.querySelector('[data-ab-validation]'),
    saveState: root.querySelector('[data-ab-save-state]'),
    status: root.querySelector('[data-ab-status]'),
    typePicker: document.getElementById('ab-type-picker'),
    mediaFile: document.getElementById('ab-media-file'),
    camera: document.getElementById('ab-camera-capture'),
    csvFile: document.getElementById('ab-csv-file'),
    toast: root.querySelector('[data-ab-toast]'),
  };

  let toastTimer = null;
  let toastHideTimer = null;
  function showToast(msg, kind) {
    if (!els.toast) return;
    els.toast.textContent = msg;
    els.toast.classList.toggle('is-error', kind === 'error');
    els.toast.hidden = false;
    els.toast.classList.remove('is-in', 'is-out');
    void els.toast.offsetWidth;
    requestAnimationFrame(() => els.toast.classList.add('is-in'));
    clearTimeout(toastTimer);
    clearTimeout(toastHideTimer);
    toastTimer = setTimeout(() => {
      els.toast.classList.remove('is-in');
      els.toast.classList.add('is-out');
      toastHideTimer = setTimeout(() => {
        if (els.toast) els.toast.hidden = true;
      }, 300);
    }, 4200);
  }

  function optionsFor(qid) {
    const map = state.tree.options_by_question || {};
    return map[qid] || map[String(qid)] || [];
  }

  function setSaveState(msg, isError) {
    if (!els.saveState) return;
    els.saveState.textContent = msg;
    els.saveState.classList.toggle('is-error', !!isError);
  }

  function markDirty() {
    state.dirty = true;
    state.changeVersion += 1;
    setSaveState('Unsaved changes…');
    renderSetupSummary();
    scheduleAutosave();
    try {
      localStorage.setItem(recoveryKey, JSON.stringify({
        at: Date.now(),
        revision: state.revision,
        activity: collectSettings(),
        selectedQuestionId: state.selectedQuestionId,
      }));
    } catch (_) { /* ignore */ }
  }

  function scheduleAutosave() {
    clearTimeout(state.debounceTimer);
    state.debounceTimer = setTimeout(() => saveSettings(), 800);
  }

  async function api(action, body, isFormData) {
    const headers = { 'X-Requested-With': 'fetch' };
    let payload;
    if (isFormData) {
      payload = body;
      if (!payload.has('_token')) payload.append('_token', csrf);
      if (!payload.has('action')) payload.append('action', action);
      if (!payload.has('id')) payload.append('id', String(activityId));
    } else {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(Object.assign({ action, _token: csrf, id: activityId, revision: state.revision }, body || {}));
    }
    const res = await fetch('activity-builder.php?id=' + activityId, {
      method: 'POST',
      headers,
      body: payload,
      credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
    if (data.conflict) {
      state.revision = data.revision || state.revision;
      setSaveState('Conflict — another save happened. Reloading…', true);
      if (data.activity) state.activity = data.activity;
      if (data.tree) state.tree = data.tree;
      renderAll();
      throw new Error(data.error || 'Revision conflict');
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed');
    }
    if (data.revision) state.revision = data.revision;
    if (data.activity) {
      state.activity = data.activity;
      root.dataset.revision = String(state.revision);
      root.dataset.status = data.activity.status || '';
      root.dataset.mode = data.activity.mode || '';
      if (els.status) els.status.textContent = (data.activity.status || '').replace(/^./, c => c.toUpperCase());
    }
    if (data.tree) state.tree = data.tree;
    if (data.validation) {
      state.validation = data.validation;
      renderValidation();
    }
    return data;
  }

  function collectSettings() {
    const fields = {};
    root.querySelectorAll('[data-ab-setting]').forEach(el => {
      const key = el.getAttribute('data-ab-setting');
      let val = el.value;
      if (el.type === 'datetime-local') {
        val = val ? val.replace('T', ' ') + ':00' : '';
      }
      fields[key] = val;
    });
    root.querySelectorAll('[data-ab-setting-minutes]').forEach(el => {
      const key = el.getAttribute('data-ab-setting-minutes');
      const mins = Math.max(0, Number(el.value || 0));
      fields[key] = Math.round(mins * 60);
    });
    root.querySelectorAll('[data-ab-setting-bool]').forEach(el => {
      fields[el.getAttribute('data-ab-setting-bool')] = el.checked ? 1 : 0;
    });
    const instr = document.getElementById('ab-instructions');
    if (instr) fields.instructions_html = instr.value;
    return fields;
  }

  async function saveSettings() {
    if (state.saving) return state.savePromise || false;
    const savingVersion = state.changeVersion;
    state.saving = true;
    setSaveState('Saving…');
    state.savePromise = (async () => {
      try {
        await api('save_settings', { fields: collectSettings(), revision: state.revision });
        state.dirty = state.changeVersion !== savingVersion;
        setSaveState(state.dirty ? 'More changes to save…' : 'Saved');
        const titleEl = root.querySelector('[data-ab-title]');
        const titleInput = root.querySelector('[data-ab-setting="title"]');
        if (titleInput && titleEl && !titleEl.isContentEditable) {
          titleEl.textContent = titleInput.value || 'Untitled activity';
        }
        if (!state.dirty) {
          try { localStorage.removeItem(recoveryKey); } catch (_) { /* ignore */ }
        } else {
          scheduleAutosave();
        }
        renderSetupSummary();
        return true;
      } catch (err) {
        setSaveState(err.message || 'Save failed', true);
        renderSetupSummary();
        return false;
      } finally {
        state.saving = false;
        state.savePromise = null;
      }
    })();
    return state.savePromise;
  }

  async function ensureSettingsSaved() {
    clearTimeout(state.debounceTimer);
    let saved = await saveSettings();
    if (saved && state.dirty) saved = await saveSettings();
    return !!saved && !state.dirty;
  }

  function stripHtml(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return (d.textContent || '').trim();
  }

  function fmtPoints(value) {
    const number = Number(value || 0);
    return Number.isInteger(number) ? String(number) : String(Math.round(number * 100) / 100);
  }

  function renderValidation() {
    if (!els.validation) return;
    const v = state.validation || { errors: [], warnings: [] };
    const errors = v.errors || [];
    const warnings = v.warnings || [];
    if (!errors.length && !warnings.length) {
      els.validation.className = 'ab-validation ab-validation--ok';
      els.validation.textContent = 'Ready to publish';
      renderSetupSummary();
      return;
    }
    els.validation.className = 'ab-validation ' + (errors.length ? 'ab-validation--error' : 'ab-validation--warn');
    els.validation.innerHTML = '';
    errors.forEach(msg => {
      const p = document.createElement('p');
      p.textContent = msg;
      els.validation.appendChild(p);
    });
    warnings.forEach(msg => {
      const p = document.createElement('p');
      p.textContent = 'Warning: ' + msg;
      els.validation.appendChild(p);
    });
    renderSetupSummary();
  }

  function renderSetupSummary() {
    const questions = state.tree.questions || [];
    const points = questions.reduce((sum, q) => sum + Number(q.points || 0), 0);
    const questionMetric = root.querySelector('[data-ab-metric="questions"]');
    const pointsMetric = root.querySelector('[data-ab-metric="points"]');
    if (questionMetric) questionMetric.textContent = String(questions.length);
    if (pointsMetric) {
      pointsMetric.textContent = isFlashcardMode ? String(questions.length) : fmtPoints(points);
      const pointsLabel = pointsMetric.parentElement;
      if (isFlashcardMode && pointsLabel && pointsLabel.childNodes.length) {
        // "N points" label sits outside the strong — leave structure, summary text handles wording
      }
    }

    const settings = collectSettings();
    const xpEnabled = !!Number(settings.xp_enabled || 0);
    const xpAmount = Math.max(0, Number(settings.xp_amount || 0));
    const rewardSummary = root.querySelector('[data-ab-reward-summary]');
    if (rewardSummary) {
      rewardSummary.textContent = xpEnabled && xpAmount > 0 ? ('+' + fmtPoints(xpAmount) + ' XP reward') : 'No XP reward';
      rewardSummary.classList.toggle('is-active', xpEnabled && xpAmount > 0);
    }

    const note = root.querySelector('[data-ab-assessment-reward-note]');
    if (note) note.hidden = settings.mode !== 'assessment';
    const integritySettings = root.querySelector('[data-ab-integrity-settings]');
    if (integritySettings) integritySettings.classList.toggle('is-relevant', settings.mode === 'assessment');

    const rewardPreview = root.querySelector('[data-ab-reward-preview]');
    if (rewardPreview) {
      rewardPreview.classList.toggle('is-active', xpEnabled && xpAmount > 0);
      const strong = rewardPreview.querySelector('strong');
      const copy = rewardPreview.querySelector('span:last-child');
      if (strong) strong.textContent = xpEnabled && xpAmount > 0 ? ('+' + fmtPoints(xpAmount) + ' XP on completion') : 'Motivate completion';
      if (copy) copy.textContent = settings.mode === 'assessment' && xpEnabled
        ? 'Awarded after the teacher releases the result.'
        : (xpEnabled ? 'Students see this reward before they begin.' : 'Turn on XP to add a visible completion reward.');
    }

    const strip = root.querySelector('[data-ab-setup-strip]');
    const message = root.querySelector('[data-ab-setup-message]');
    const errors = state.validation?.errors || [];
    const warnings = state.validation?.warnings || [];
    strip?.classList.toggle('has-errors', errors.length > 0);
    strip?.classList.toggle('has-warnings', errors.length === 0 && warnings.length > 0);
    strip?.classList.toggle('is-ready', !state.dirty && questions.length > 0 && errors.length === 0);
    if (message) {
      if (state.dirty) message.textContent = 'Saving changes before the next readiness check.';
      else if (errors.length) message.textContent = errors.length + (errors.length === 1 ? ' issue blocks' : ' issues block') + ' publishing.';
      else if (warnings.length) message.textContent = warnings.length + (warnings.length === 1 ? ' setting deserves' : ' settings deserve') + ' a quick review.';
      else if (!questions.length) message.textContent = isFlashcardMode ? 'Add your first flashcard to begin.' : 'Add your first question to begin.';
      else message.textContent = 'Ready to preview and publish.';
    }
  }

  function renderStructure() {
    if (!els.list) return;
    els.list.innerHTML = '';
    const questions = state.tree.questions || [];
    if (els.empty) els.empty.hidden = questions.length > 0;

    const sections = state.tree.sections || [];
    const bySection = new Map();
    sections.forEach(s => bySection.set(Number(s.id), []));
    const unsectioned = [];
    questions.forEach(q => {
      const sid = q.section_id != null ? Number(q.section_id) : null;
      if (sid && bySection.has(sid)) bySection.get(sid).push(q);
      else unsectioned.push(q);
    });

    const appendQuestion = (q, indexLabel) => {
      const card = document.createElement('div');
      card.className = 'ab-question-card' + (Number(state.selectedQuestionId) === Number(q.id) ? ' is-selected' : '');
      card.draggable = true;
      card.dataset.questionId = String(q.id);
      card.setAttribute('role', 'listitem');
      card.tabIndex = 0;
      const marking = isFlashcardMode
        ? ''
        : (Number(q.manual_marking)
        ? '<span class="ab-mark-pill ab-mark-pill--manual" title="Teacher marks this">Manual</span>'
        : '<span class="ab-mark-pill ab-mark-pill--auto" title="Scored automatically">Auto</span>');
      const front = stripHtml(q.prompt_html) || (isFlashcardMode ? 'Untitled card' : 'Untitled question');
      const cardSettings = safeJson(q.settings_json || q.settings, {});
      const backPreview = isFlashcardMode ? stripHtml(cardSettings.back || q.explanation_html || '') : '';
      card.innerHTML =
        '<button type="button" class="ab-drag-handle" aria-label="Drag to reorder" data-ab-drag>⋮⋮</button>' +
        '<div class="ab-question-card-body">' +
        '<span class="ab-question-index">' + indexLabel + '</span>' +
        '<strong>' + escapeHtml(front) + '</strong>' +
        (backPreview ? '<small class="ab-card-back-preview">' + escapeHtml(backPreview.slice(0, 80)) + (backPreview.length > 80 ? '…' : '') + '</small>' : '') +
        '<p class="ab-question-card-meta">' +
        '<span>' + escapeHtml(isFlashcardMode ? 'Flashcard' : typeLabel(q.question_type)) + '</span>' +
        (isFlashcardMode ? '' : ('<strong>' + Number(q.points || 0) + ' pts</strong>')) +
        marking + '</p>' +
        '</div>' +
        '<div class="ab-q-actions">' +
        '<button type="button" data-ab-q-move="up" aria-label="Move up" title="Move up">↑</button>' +
        '<button type="button" data-ab-q-move="down" aria-label="Move down" title="Move down">↓</button>' +
        '</div>';
      card.addEventListener('click', (e) => {
        if (e.target.closest('[data-ab-q-move],[data-ab-drag]')) return;
        selectQuestion(Number(q.id));
      });
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          selectQuestion(Number(q.id));
        }
      });
      els.list.appendChild(card);
    };

    let n = 1;
    sections.forEach(sec => {
      const head = document.createElement('div');
      head.className = 'ab-section-head';
      head.innerHTML = '<strong>' + escapeHtml(sec.title || 'Section') + '</strong>';
      els.list.appendChild(head);
      (bySection.get(Number(sec.id)) || []).forEach(q => appendQuestion(q, String(n++)));
    });
    unsectioned.forEach(q => appendQuestion(q, String(n++)));

    const totalPts = (state.tree.questions || []).reduce((sum, q) => sum + Number(q.points || 0), 0);
    const tally = document.createElement('p');
    tally.className = 'ab-points-tally';
    tally.innerHTML = '<strong>' + (state.tree.questions || []).length + '</strong> questions · <strong>' + totalPts + '</strong> pts total';
    els.list.appendChild(tally);

    bindDragReorder();
  }

  function typeLabel(type) {
    const found = (boot.question_types || []).find(t => t.id === type);
    return found ? found.label : type;
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  const MEDIA_LIMIT_QUESTION = 3;
  const MEDIA_LIMIT_FLASHCARD = 1;

  function mediaLimitFor(q) {
    if (isFlashcardMode || (q && q.question_type === 'flashcard')) return MEDIA_LIMIT_FLASHCARD;
    return MEDIA_LIMIT_QUESTION;
  }

  function uniqueMediaList(media) {
    if (!Array.isArray(media) || media.length === 0) return [];
    const seen = new Set();
    return media.filter((m) => {
      const hash = String(m.sha256 || '');
      if (hash === '') return true;
      if (seen.has(hash)) return false;
      seen.add(hash);
      return true;
    });
  }

  function mediaCountFor(q) {
    return uniqueMediaList(q && q.media).length;
  }

  function mediaCapMessage(limit) {
    return limit === 1
      ? 'This card already has media. Remove it to add another.'
      : 'This question already has ' + limit + ' attachments. Remove one to add another.';
  }

  function mediaBlockHtml(q) {
    const items = Array.isArray(q.media) ? q.media : [];
    const limit = mediaLimitFor(q);
    const used = mediaCountFor(q);
    const remaining = Math.max(0, limit - used);
    const atCap = remaining <= 0;
    const list = items.length
      ? ('<ul class="ab-media-list">' + items.map((m) => {
          const url = escapeHtml(m.url || ('activity-media.php?id=' + m.id));
          const name = escapeHtml(m.original_filename || ('Media #' + m.id));
          const type = String(m.media_type || 'image');
          let preview = '';
          if (type === 'image') {
            preview = '<img src="' + url + '" alt="">';
          } else if (type === 'audio') {
            preview = '<audio controls preload="metadata" src="' + url + '"></audio>';
          } else if (type === 'video') {
            preview = '<video controls preload="metadata" src="' + url + '"></video>';
          }
          return '<li class="ab-media-item">' +
            '<div class="ab-media-preview">' + preview + '</div>' +
            '<div class="ab-media-meta"><strong>' + name + '</strong>' +
            '<span>' + escapeHtml(type) + (m.filesize ? ' · ' + formatBytes(m.filesize) : '') + '</span></div>' +
            '<button type="button" class="ab-btn ab-btn--ghost" data-ab-delete-media="' + Number(m.id) + '">Delete</button>' +
            '</li>';
        }).join('') + '</ul>')
      : '<p class="ab-media-empty">Nothing attached yet.</p>';

    let hint;
    if (atCap) {
      hint = limit === 1
        ? 'This card already has media. Remove it to add another.'
        : 'Remove one to add another (' + limit + ' maximum).';
    } else if (used === 0) {
      hint = limit === 1
        ? 'One image, audio clip, or video on this card'
        : 'Images, audio, or video — up to ' + limit + ' per question';
    } else {
      hint = remaining === 1
        ? '1 more can be added'
        : remaining + ' more can be added';
    }

    const disabled = atCap ? ' disabled' : '';
    return '<div class="ab-media-dropzone' + (atCap ? ' is-full' : '') + '" data-ab-media-dropzone>' +
      '<div class="ab-media-dropzone-ui">' +
      '<div class="ab-media-dropzone-copy"><strong>' + (atCap ? 'Media limit reached' : 'Drop media here') + '</strong>' +
      '<span>' + hint + '</span></div>' +
      '<div class="ab-media-dropzone-actions">' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-upload-media' + disabled + '>Upload media</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-camera' + disabled + '>Camera</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-record-audio' + disabled + '>Record audio</button>' +
      '</div></div>' +
      '<div class="ab-media-progress is-hidden" data-ab-media-progress aria-live="polite">' +
      '<div class="ab-media-progress-track"><div class="ab-media-progress-bar" data-ab-media-progress-bar></div></div>' +
      '<span class="ab-media-progress-label" data-ab-media-progress-label>0%</span>' +
      '</div>' +
      '<div class="ab-media-attached" data-ab-media-list>' + list + '</div>' +
      '</div>';
  }

  function selectQuestion(id) {
    state.selectedQuestionId = id;
    renderStructure();
    renderEditor();
  }

  function renderEditor() {
    const q = (state.tree.questions || []).find(x => Number(x.id) === Number(state.selectedQuestionId));
    if (!q) {
      if (els.editor) els.editor.hidden = true;
      if (els.editorEmpty) els.editorEmpty.hidden = false;
      return;
    }
    if (els.editorEmpty) els.editorEmpty.hidden = true;
    if (els.editor) els.editor.hidden = false;

    if (isFlashcardMode || q.question_type === 'flashcard') {
      renderFlashcardEditor(q);
      return;
    }

    const opts = optionsFor(q.id);
    const settings = safeJson(q.settings_json, {});
    const choiceTypes = ['single_choice', 'multiple_choice', 'true_false'];
    const isWritten = q.question_type === 'long_response';
    const autoTypes = ['single_choice', 'multiple_choice', 'true_false', 'short_text', 'numeric', 'fill_blank', 'ordering', 'matching'];
    const canAuto = autoTypes.includes(q.question_type) && !isWritten;
    const forcedManual = isWritten;

    let optionsHtml = '';
    if (choiceTypes.includes(q.question_type)) {
      const correctControl = (q.question_type === 'multiple_choice') ? 'checkbox' : 'radio';
      optionsHtml = '<div class="ab-editor-block"><h3>Answer key</h3>' +
        '<p class="ab-hint-line">Mark the correct answer' + (q.question_type === 'multiple_choice' ? '(s)' : '') + '.</p>' +
        '<div data-ab-options class="ab-options-list">';
      opts.forEach((o, i) => {
        optionsHtml +=
          '<div class="ab-option-row" data-option-id="' + o.id + '">' +
          '<span class="ab-option-letter" aria-hidden="true">' + String.fromCharCode(65 + (i % 26)) + '</span>' +
          '<input type="text" data-opt-text value="' + escapeHtml(stripHtml(o.option_text_html)) + '" aria-label="Option text" placeholder="Option ' + (i + 1) + '">' +
          '<label class="ab-check ab-check--correct"><input type="' + correctControl + '" name="ab-correct-' + q.id + '" data-opt-correct' +
          (Number(o.is_correct) ? ' checked' : '') + '><span>Correct</span></label>' +
          '<button type="button" data-ab-del-option aria-label="Delete option">×</button>' +
          '</div>';
      });
      optionsHtml += '</div><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm" data-ab-add-option>+ Add option</button></div>';
    } else if (q.question_type === 'ordering') {
      optionsHtml = '<div class="ab-editor-block"><h3>Items in correct order</h3>' +
        '<p class="ab-hint-line">List items from first to last. Students must rearrange them into this order.</p>' +
        '<div data-ab-options class="ab-options-list">';
      opts.forEach((o, i) => {
        optionsHtml +=
          '<div class="ab-option-row ab-option-row--order" data-option-id="' + o.id + '">' +
          '<span class="ab-option-letter" aria-hidden="true">' + (i + 1) + '</span>' +
          '<input type="text" data-opt-text value="' + escapeHtml(stripHtml(o.option_text_html)) + '" aria-label="Item text" placeholder="Item ' + (i + 1) + '">' +
          '<button type="button" data-ab-del-option aria-label="Delete item">×</button>' +
          '</div>';
      });
      optionsHtml += '</div><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm" data-ab-add-option>+ Add item</button></div>';
    } else if (q.question_type === 'matching') {
      const pairs = Array.isArray(settings.pairs) && settings.pairs.length
        ? settings.pairs
        : [{ left: '', right: '' }, { left: '', right: '' }];
      optionsHtml = '<div class="ab-editor-block"><h3>Matching pairs</h3>' +
        '<p class="ab-hint-line">Students see the prompts on the left and choose from the answers on the right. Scoring is automatic.</p>' +
        '<div data-ab-pairs class="ab-pairs-list">';
      pairs.forEach((pair, i) => {
        optionsHtml +=
          '<div class="ab-pair-row">' +
          '<input type="text" data-pair-left value="' + escapeHtml(String(pair.left || '')) + '" placeholder="Prompt ' + (i + 1) + '" aria-label="Prompt">' +
          '<span class="ab-pair-arrow" aria-hidden="true">→</span>' +
          '<input type="text" data-pair-right value="' + escapeHtml(String(pair.right || '')) + '" placeholder="Match ' + (i + 1) + '" aria-label="Match">' +
          '<button type="button" data-ab-del-pair aria-label="Delete pair">×</button>' +
          '</div>';
      });
      optionsHtml += '</div><button type="button" class="ab-btn ab-btn--ghost ab-btn--sm" data-ab-add-pair>+ Add pair</button></div>';
    }

    let settingsHtml = '';
    if (q.question_type === 'short_text') {
      const accepted = (settings.accepted_answers || []).join('\n');
      settingsHtml = '<div class="ab-editor-block"><h3>Accepted answers</h3>' +
        '<p class="ab-hint-line">One correct answer per line. Matching is automatic and case-insensitive.</p>' +
        '<label class="ab-field"><textarea data-ab-accepted rows="3" placeholder="e.g. mitochondria">' + escapeHtml(accepted) + '</textarea></label></div>';
    } else if (q.question_type === 'numeric') {
      settingsHtml =
        '<div class="ab-editor-block"><h3>Correct value</h3>' +
        '<div class="ab-field-row">' +
        '<label class="ab-field"><span>Answer</span><input type="number" step="any" data-ab-correct-value value="' + escapeHtml(settings.correct_value ?? '') + '"></label>' +
        '<label class="ab-field"><span>Tolerance (±)</span><input type="number" step="any" data-ab-tolerance value="' + escapeHtml(settings.absolute_tolerance ?? settings.tolerance ?? 0) + '"></label>' +
        '</div></div>';
    } else if (isWritten) {
      const expected = String(settings.expected_answer || '');
      const keywords = (settings.keywords || []).join(', ');
      const suggestOn = settings.auto_suggest === undefined ? true : !!Number(settings.auto_suggest);
      settingsHtml =
        '<div class="ab-editor-block">' +
        '<div class="ab-editor-head"><h3>Expected answer</h3>' +
        '<span class="ab-mark-pill ab-mark-pill--assist">Helps you mark</span></div>' +
        '<p class="ab-hint-line">Write the answer you are looking for. When students submit, their writing is compared ' +
        'to this and you get a suggested mark to accept or change in Results.</p>' +
        '<label class="ab-field"><textarea data-ab-expected rows="4" ' +
        'placeholder="e.g. Running raises the heart rate because muscles need more oxygen to release energy.">' +
        escapeHtml(expected) + '</textarea></label>' +
        '<label class="ab-field"><span>Key points <small>comma or new line · use | for alternatives</small></span>' +
        '<textarea data-ab-keywords rows="2" placeholder="heart rate|pulse, oxygen, energy">' +
        escapeHtml(keywords) + '</textarea></label>' +
        '<label class="ab-check"><input type="checkbox" data-ab-q-setting-bool="auto_suggest"' +
        (suggestOn ? ' checked' : '') + '><span>Suggest a mark for me when marking this question</span></label>' +
        '</div>' +
        '<div class="ab-editor-block ab-marking-callout">' +
        '<h3>You always confirm the mark</h3>' +
        '<p>Written answers are never scored automatically. The suggestion is a starting point — ' +
        '<strong>the grade stays pending</strong> and stays out of the gradebook until you mark every written answer on the attempt.</p>' +
        '</div>';
    }

    let markingHtml = '';
    if (forcedManual) {
      markingHtml =
        '<input type="hidden" data-ab-q-bool="manual_marking" value="1" checked>' +
        '<p class="ab-mark-pill ab-mark-pill--manual">Always needs teacher marking</p>';
    } else if (canAuto) {
      markingHtml =
        '<div class="ab-marking-box">' +
        '<label class="ab-check"><input type="checkbox" data-ab-q-bool="manual_marking"' + (Number(q.manual_marking) ? ' checked' : '') + '>' +
        '<span><strong>Mark manually instead</strong> — turn this on if you do not want automatic scoring for this question</span></label>' +
        '</div>';
    }

    els.editor.innerHTML =
      '<div class="ab-editor-block">' +
      '<div class="ab-editor-head"><h3>' + escapeHtml(typeLabel(q.question_type)) + '</h3>' +
      (forcedManual || Number(q.manual_marking)
        ? '<span class="ab-mark-pill ab-mark-pill--manual">Teacher marked</span>'
        : '<span class="ab-mark-pill ab-mark-pill--auto">Auto-marked</span>') +
      '</div>' +
      '<label class="ab-field"><span>Question text</span><div class="quill-wrap ab-quill-compact"><div class="quill-editor" data-ab-q-quill="prompt"></div></div>' +
      '<textarea hidden data-ab-q-field="prompt_html">' + escapeHtml(q.prompt_html || '') + '</textarea></label>' +
      mediaBlockHtml(q) +
      '<div class="ab-editor-meta">' +
      '<label class="ab-field"><span>Points</span><input type="number" min="0" step="0.5" data-ab-q-field="points" value="' + Number(q.points || 1) + '"></label>' +
      '<label class="ab-check ab-check--inline"><input type="checkbox" data-ab-q-bool="required"' + (Number(q.required) !== 0 ? ' checked' : '') + '><span>Required</span></label>' +
      '</div>' +
      markingHtml +
      '</div>' +
      settingsHtml +
      optionsHtml +
      '<details class="ab-advanced">' +
      '<summary>More options</summary>' +
      '<label class="ab-field"><span>Feedback shown after release</span><div class="quill-wrap ab-quill-compact"><div class="quill-editor" data-ab-q-quill="explanation"></div></div>' +
      '<textarea hidden data-ab-q-field="explanation_html">' + escapeHtml(q.explanation_html || '') + '</textarea></label>' +
      '<label class="ab-field"><span>Private teacher notes</span><textarea data-ab-q-field="teacher_notes" rows="2" placeholder="Only you can see this">' + escapeHtml(q.teacher_notes || '') + '</textarea></label>' +
      '<label class="ab-field"><span>Difficulty</span><select data-ab-q-field="difficulty">' +
      ['easy','medium','hard'].map(d => '<option value="' + d + '"' + (q.difficulty === d ? ' selected' : '') + '>' + d + '</option>').join('') +
      '</select></label>' +
      '</details>' +
      '<div class="ab-editor-actions">' +
      '<button type="button" class="ab-btn ab-btn--primary" data-ab-save-question>Save question</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-dup-question>Duplicate</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-del-question>Delete</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-bank-question>Save to bank</button>' +
      '</div>';

    // Hidden checkbox substitute for forced manual must still collect as bool
    const forcedInput = els.editor.querySelector('input[data-ab-q-bool="manual_marking"][type="hidden"]');
    if (forcedInput) {
      // collectQuestionFields reads .checked on checkboxes; for hidden, force via dataset
      forcedInput.checked = true;
    }

    initQuestionQuills(q);
    bindEditorEvents(q);
  }

  function renderFlashcardEditor(q) {
    const settings = safeJson(q.settings_json || q.settings, {});
    const frontText = stripHtml(q.prompt_html || '');
    const backText = String(settings.back || stripHtml(q.explanation_html || '') || '');

    els.editor.innerHTML =
      '<div class="ab-editor-block ab-flashcard-editor">' +
      '<div class="ab-editor-head"><h3>Flashcard</h3></div>' +
      '<label class="ab-field"><span>Front</span>' +
      '<textarea data-ab-fc-front rows="4" placeholder="Term, question, or prompt">' + escapeHtml(frontText) + '</textarea></label>' +
      '<label class="ab-field"><span>Back</span>' +
      '<textarea data-ab-fc-back rows="4" placeholder="Definition or answer">' + escapeHtml(backText) + '</textarea></label>' +
      mediaBlockHtml(q) +
      '<p class="ab-hint-line">Students flip the card to reveal the back, then mark Know or Still learning.</p>' +
      '<div class="ab-editor-actions">' +
      '<button type="button" class="ab-btn ab-btn--primary" data-ab-save-question>Save card</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-dup-question>Duplicate</button>' +
      '<button type="button" class="ab-btn ab-btn--ghost" data-ab-del-question>Delete</button>' +
      '</div></div>';

    bindFlashcardEditorEvents(q);
  }

  function bindFlashcardEditorEvents(q) {
    const saveBtn = els.editor.querySelector('[data-ab-save-question]');
    const dupBtn = els.editor.querySelector('[data-ab-dup-question]');
    const delBtn = els.editor.querySelector('[data-ab-del-question]');
    const front = els.editor.querySelector('[data-ab-fc-front]');
    const back = els.editor.querySelector('[data-ab-fc-back]');

    [front, back].forEach((el) => {
      el?.addEventListener('input', () => markDirty());
    });
    bindMediaControls(q);

    saveBtn?.addEventListener('click', async () => {
      try {
        const frontRaw = (front?.value || '').trim();
        const backText = (back?.value || '').trim();
        const frontHtml = '<p>' + escapeHtml(frontRaw).replace(/\n/g, '<br>') + '</p>';
        await api('update_question', {
          question_id: q.id,
          fields: {
            prompt_html: frontHtml,
            explanation_html: '',
            settings: { back: backText },
            points: 1,
            question_type: 'flashcard',
          },
        });
        state.dirty = false;
        setSaveState('Saved');
        renderAll();
        selectQuestion(q.id);
      } catch (err) {
        alert(err.message || 'Could not save card');
      }
    });

    dupBtn?.addEventListener('click', async () => {
      try {
        const data = await api('duplicate_question', { question_id: q.id });
        renderAll();
        if (data.question_id) selectQuestion(data.question_id);
      } catch (err) {
        alert(err.message || 'Duplicate failed');
      }
    });

    delBtn?.addEventListener('click', async () => {
      if (!confirm('Delete this flashcard?')) return;
      try {
        await api('delete_question', { question_id: q.id });
        state.selectedQuestionId = null;
        renderAll();
      } catch (err) {
        alert(err.message || 'Delete failed');
      }
    });
  }

  function safeJson(raw, fallback) {
    if (typeof raw === 'object' && raw !== null) return raw;
    try { return JSON.parse(raw || '{}') || fallback; } catch (_) { return fallback; }
  }

  function stripHtmlImages(html) {
    return String(html || '').replace(/<img\b[^>]*>/gi, '');
  }

  /** Load existing HTML without counting it as an edit. */
  function setQuillHtml(quill, html) {
    if (!html) return;
    html = stripHtmlImages(html);
    try {
      quill.setContents(quill.clipboard.convert({ html }), 'silent');
    } catch (_) {
      quill.root.innerHTML = html;
    }
  }

  function bindQuillMediaIntercept(quill, questionId) {
    const takeFiles = (dt) => {
      if (!dt || !dt.files || !dt.files.length) return [];
      return Array.from(dt.files).filter((f) => f && (f.type.startsWith('image/') || f.type.startsWith('audio/') || f.type.startsWith('video/') || f.size));
    };
    quill.root.addEventListener('drop', (e) => {
      const files = takeFiles(e.dataTransfer);
      if (!files.length) return;
      e.preventDefault();
      e.stopPropagation();
      uploadMediaFiles(files, questionId);
    }, true);
    quill.root.addEventListener('paste', (e) => {
      const items = Array.from(e.clipboardData?.items || []);
      const imageItem = items.find((item) => item.type && item.type.startsWith('image/'));
      if (!imageItem) return;
      e.preventDefault();
      e.stopPropagation();
      const file = imageItem.getAsFile();
      if (file) uploadMediaFiles([file], questionId);
    }, true);
    try {
      const Delta = Quill.import('delta');
      quill.clipboard.addMatcher('IMG', () => new Delta());
    } catch (_) { /* older Quill */ }
  }

  function initQuestionQuills(q) {
    if (typeof Quill === 'undefined') return;
    els.editor.querySelectorAll('[data-ab-q-quill]').forEach(node => {
      const key = node.getAttribute('data-ab-q-quill');
      const field = key === 'prompt' ? 'prompt_html' : 'explanation_html';
      const ta = els.editor.querySelector('[data-ab-q-field="' + field + '"]');
      const quill = new Quill(node, {
        theme: 'snow',
        placeholder: key === 'prompt' ? 'Type the question…' : 'Explain the answer…',
        modules: { toolbar: [['bold', 'italic'], ['link'], [{ list: 'ordered' }, { list: 'bullet' }]] },
      });
      setQuillHtml(quill, ta ? ta.value : '');
      bindQuillMediaIntercept(quill, q.id);
      quill.on('text-change', () => {
        if (ta) ta.value = stripHtmlImages(quill.root.innerHTML);
        markDirty();
      });
      node._quill = quill;
    });
  }

  function bindEditorEvents(q) {
    els.editor.querySelector('[data-ab-save-question]')?.addEventListener('click', async () => {
      try {
        const fields = collectQuestionFields(q);
        await api('update_question', { question_id: q.id, fields });
        state.dirty = false;
        setSaveState('Question saved');
        renderAll();
        selectQuestion(q.id);
      } catch (err) {
        setSaveState(err.message, true);
      }
    });

    els.editor.querySelector('[data-ab-dup-question]')?.addEventListener('click', async () => {
      try {
        if (!await flushCurrentQuestion(q)) return;
        const data = await api('duplicate_question', { question_id: q.id });
        renderAll();
        if (data.question_id) selectQuestion(data.question_id);
      } catch (err) { alert(err.message); }
    });

    els.editor.querySelector('[data-ab-del-question]')?.addEventListener('click', async () => {
      if (!confirm('Delete this question?')) return;
      try {
        await api('delete_question', { question_id: q.id });
        state.selectedQuestionId = null;
        renderAll();
      } catch (err) { alert(err.message); }
    });

    els.editor.querySelector('[data-ab-bank-question]')?.addEventListener('click', async () => {
      try {
        if (!await flushCurrentQuestion(q)) return;
        await api('save_to_bank', { question_id: q.id, visibility: 'private' });
        setSaveState('Saved to question bank');
      } catch (err) { alert(err.message); }
    });

    els.editor.querySelector('[data-ab-add-option]')?.addEventListener('click', async () => {
      try {
        if (!await flushCurrentQuestion(q)) return;
        await api('add_option', { question_id: q.id, option: { option_text_html: '', is_correct: 0 } });
        renderAll();
        selectQuestion(q.id);
      } catch (err) { alert(err.message); }
    });

    els.editor.querySelectorAll('[data-ab-del-option]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const row = btn.closest('[data-option-id]');
        const list = els.editor.querySelector('[data-ab-options]');
        if (!row || !list) return;
        const rows = list.querySelectorAll('[data-option-id]');
        if (rows.length <= 2) {
          setSaveState('Keep at least two options', true);
          return;
        }
        row.remove();
        list.querySelectorAll('[data-option-id]').forEach((el, i) => {
          const letter = el.querySelector('.ab-option-letter');
          if (!letter) return;
          letter.textContent = el.classList.contains('ab-option-row--order')
            ? String(i + 1)
            : String.fromCharCode(65 + (i % 26));
        });
        try {
          if (!await flushCurrentQuestion(q)) return;
          renderAll();
          selectQuestion(q.id);
        } catch (err) { alert(err.message); }
      });
    });

    const pairsList = els.editor.querySelector('[data-ab-pairs]');
    els.editor.querySelector('[data-ab-add-pair]')?.addEventListener('click', () => {
      if (!pairsList) return;
      const n = pairsList.querySelectorAll('.ab-pair-row').length + 1;
      const row = document.createElement('div');
      row.className = 'ab-pair-row';
      row.innerHTML =
        '<input type="text" data-pair-left value="" placeholder="Prompt ' + n + '" aria-label="Prompt">' +
        '<span class="ab-pair-arrow" aria-hidden="true">→</span>' +
        '<input type="text" data-pair-right value="" placeholder="Match ' + n + '" aria-label="Match">' +
        '<button type="button" data-ab-del-pair aria-label="Delete pair">×</button>';
      pairsList.appendChild(row);
      row.querySelector('[data-ab-del-pair]')?.addEventListener('click', () => {
        if (pairsList.querySelectorAll('.ab-pair-row').length <= 2) return;
        row.remove();
        markDirty();
      });
      row.querySelectorAll('input').forEach(inp => inp.addEventListener('input', markDirty));
      markDirty();
      row.querySelector('[data-pair-left]')?.focus();
    });
    els.editor.querySelectorAll('[data-ab-del-pair]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (!pairsList || pairsList.querySelectorAll('.ab-pair-row').length <= 2) return;
        btn.closest('.ab-pair-row')?.remove();
        markDirty();
      });
    });
    els.editor.querySelectorAll('[data-pair-left],[data-pair-right]').forEach(inp => {
      inp.addEventListener('input', markDirty);
    });

    els.editor.querySelectorAll('[data-opt-text],[data-opt-correct]').forEach(el => {
      el.addEventListener('change', async () => {
        const row = el.closest('[data-option-id]');
        const oid = Number(row?.dataset.optionId || 0);
        const isCorrectEl = el.hasAttribute('data-opt-correct') ? el : row.querySelector('[data-opt-correct]');
        try {
          // Single-correct types: when one is ticked, clear the others server-side.
          if (isCorrectEl && isCorrectEl.checked && isCorrectEl.type === 'radio') {
            const rows = els.editor.querySelectorAll('[data-option-id]');
            for (const other of rows) {
              const otherId = Number(other.dataset.optionId || 0);
              if (otherId === oid) continue;
              await api('update_option', {
                option_id: otherId,
                option: {
                  option_text_html: other.querySelector('[data-opt-text]')?.value || '',
                  is_correct: 0,
                },
              });
            }
          }
          await api('update_option', {
            option_id: oid,
            option: {
              option_text_html: row.querySelector('[data-opt-text]')?.value || '',
              is_correct: row.querySelector('[data-opt-correct]')?.checked ? 1 : 0,
            },
          });
          setSaveState('Answer key updated');
        } catch (err) { setSaveState(err.message, true); }
      });
    });

    bindMediaControls(q);
  }

  function bindMediaControls(q) {
    els.editor.querySelector('[data-ab-upload-media]')?.addEventListener('click', () => {
      els.mediaFile.dataset.questionId = String(q.id);
      els.mediaFile.click();
    });
    els.editor.querySelector('[data-ab-camera]')?.addEventListener('click', () => {
      els.camera.dataset.questionId = String(q.id);
      els.camera.click();
    });
    els.editor.querySelector('[data-ab-record-audio]')?.addEventListener('click', () => recordAudio(q.id));

    els.editor.querySelectorAll('[data-ab-delete-media]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const mediaId = Number(btn.getAttribute('data-ab-delete-media') || 0);
        if (!mediaId || !confirm('Remove this media from the question?')) return;
        try {
          await api('delete_media', { media_id: mediaId });
          setSaveState('Media removed');
          renderAll();
          selectQuestion(q.id);
        } catch (err) {
          alert(err.message || 'Could not delete media');
        }
      });
    });

    const mediaZone = els.editor.querySelector('[data-ab-media-dropzone]');
    if (mediaZone) {
      let dragDepth = 0;
      const hasFiles = (dt) => {
        if (!dt) return false;
        if (dt.types && typeof dt.types.includes === 'function') return dt.types.includes('Files');
        return Array.from(dt.types || []).indexOf('Files') !== -1;
      };
      mediaZone.addEventListener('dragenter', (e) => {
        if (!hasFiles(e.dataTransfer)) return;
        e.preventDefault();
        e.stopPropagation();
        dragDepth += 1;
        mediaZone.classList.add('is-dragover');
      });
      mediaZone.addEventListener('dragover', (e) => {
        if (!hasFiles(e.dataTransfer)) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      });
      mediaZone.addEventListener('dragleave', (e) => {
        if (!hasFiles(e.dataTransfer)) return;
        e.preventDefault();
        e.stopPropagation();
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) mediaZone.classList.remove('is-dragover');
      });
      mediaZone.addEventListener('drop', (e) => {
        if (!hasFiles(e.dataTransfer)) return;
        e.preventDefault();
        e.stopPropagation();
        dragDepth = 0;
        mediaZone.classList.remove('is-dragover');
        const files = Array.from(e.dataTransfer?.files || []);
        uploadMediaFiles(files, q.id);
      });
    }
  }

  function syncQuestionQuills() {
    if (!els.editor) return;
    els.editor.querySelectorAll('[data-ab-q-quill]').forEach(node => {
      const key = node.getAttribute('data-ab-q-quill');
      const field = key === 'prompt' ? 'prompt_html' : 'explanation_html';
      const ta = els.editor.querySelector('[data-ab-q-field="' + field + '"]');
      if (ta && node._quill) ta.value = stripHtmlImages(node._quill.root.innerHTML);
    });
  }

  /** Persist the open question editor before tree-refreshing actions (add/delete option). */
  async function flushCurrentQuestion(q) {
    if (!q || !els.editor || els.editor.hasAttribute('hidden')) return true;
    syncQuestionQuills();
    try {
      const fields = collectQuestionFields(q);
      await api('update_question', { question_id: q.id, fields });
      return true;
    } catch (err) {
      setSaveState(err.message || 'Could not save question', true);
      return false;
    }
  }

  function collectQuestionFields(q) {
    const fields = {};
    els.editor.querySelectorAll('[data-ab-q-field]').forEach(el => {
      fields[el.getAttribute('data-ab-q-field')] = el.value;
    });
    els.editor.querySelectorAll('[data-ab-q-bool]').forEach(el => {
      if (el.type === 'hidden') {
        fields[el.getAttribute('data-ab-q-bool')] = 1;
      } else {
        fields[el.getAttribute('data-ab-q-bool')] = el.checked ? 1 : 0;
      }
    });
    if (q.question_type === 'long_response') {
      fields.manual_marking = 1;
    }
    const settings = safeJson(q.settings_json, {});
    els.editor.querySelectorAll('[data-ab-q-setting-bool]').forEach(el => {
      settings[el.getAttribute('data-ab-q-setting-bool')] = el.checked ? 1 : 0;
    });
    const accepted = els.editor.querySelector('[data-ab-accepted]');
    if (accepted) {
      settings.accepted_answers = accepted.value.split('\n').map(s => s.trim()).filter(Boolean);
    }
    const expectedAnswer = els.editor.querySelector('[data-ab-expected]');
    if (expectedAnswer) {
      settings.expected_answer = expectedAnswer.value.trim();
    }
    const keywordBox = els.editor.querySelector('[data-ab-keywords]');
    if (keywordBox) {
      settings.keywords = keywordBox.value
        .split(/[\n,]+/)
        .map(s => s.trim())
        .filter(Boolean);
    }
    const cv = els.editor.querySelector('[data-ab-correct-value]');
    if (cv) {
      settings.correct_value = cv.value === '' ? null : Number(cv.value);
      const tol = Number(els.editor.querySelector('[data-ab-tolerance]')?.value || 0);
      settings.absolute_tolerance = tol;
      settings.tolerance = tol;
    }
    const pairRows = els.editor.querySelectorAll('[data-ab-pairs] .ab-pair-row');
    if (pairRows.length) {
      settings.pairs = Array.from(pairRows).map((row) => ({
        left: (row.querySelector('[data-pair-left]')?.value || '').trim(),
        right: (row.querySelector('[data-pair-right]')?.value || '').trim(),
      })).filter((p) => p.left !== '' || p.right !== '');
    }
    fields.settings = settings;

    const optRows = els.editor.querySelectorAll('[data-option-id]');
    if (optRows.length) {
      fields.options = Array.from(optRows).map(row => ({
        option_text_html: row.querySelector('[data-opt-text]')?.value || '',
        is_correct: row.querySelector('[data-opt-correct]')?.checked ? 1 : 0,
      }));
    } else if (q.question_type === 'matching') {
      // Matching uses settings.pairs, not choice options — clear any legacy options.
      fields.options = [];
    }
    return fields;
  }

  function bindDragReorder() {
    let dragId = null;
    els.list.querySelectorAll('.ab-question-card').forEach(card => {
      card.addEventListener('dragstart', () => {
        dragId = Number(card.dataset.questionId);
        card.classList.add('is-dragging');
      });
      card.addEventListener('dragend', () => card.classList.remove('is-dragging'));
      card.addEventListener('dragover', (e) => { e.preventDefault(); });
      card.addEventListener('drop', async (e) => {
        e.preventDefault();
        const targetId = Number(card.dataset.questionId);
        if (!dragId || dragId === targetId) return;
        const ids = (state.tree.questions || []).map(q => Number(q.id));
        const from = ids.indexOf(dragId);
        const to = ids.indexOf(targetId);
        if (from < 0 || to < 0) return;
        ids.splice(to, 0, ids.splice(from, 1)[0]);
        try {
          await api('reorder_questions', { question_ids: ids });
          renderAll();
        } catch (err) { alert(err.message); }
      });
    });

    els.list.querySelectorAll('[data-ab-q-move]').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const card = btn.closest('.ab-question-card');
        const id = Number(card?.dataset.questionId || 0);
        const ids = (state.tree.questions || []).map(q => Number(q.id));
        const idx = ids.indexOf(id);
        if (idx < 0) return;
        const dir = btn.getAttribute('data-ab-q-move');
        const swap = dir === 'up' ? idx - 1 : idx + 1;
        if (swap < 0 || swap >= ids.length) return;
        [ids[idx], ids[swap]] = [ids[swap], ids[idx]];
        try {
          await api('reorder_questions', { question_ids: ids });
          renderAll();
          selectQuestion(id);
        } catch (err) { alert(err.message); }
      });
    });
  }

  function setMediaUploadProgress(pct, visible) {
    const wrap = els.editor?.querySelector('[data-ab-media-progress]');
    const bar = els.editor?.querySelector('[data-ab-media-progress-bar]');
    const label = els.editor?.querySelector('[data-ab-media-progress-label]');
    if (!wrap) return;
    if (!visible) {
      wrap.classList.add('is-hidden');
      if (bar) bar.style.width = '0%';
      if (label) label.textContent = '0%';
      return;
    }
    wrap.classList.remove('is-hidden');
    const safe = Math.max(0, Math.min(100, Math.round(pct || 0)));
    if (bar) bar.style.width = safe + '%';
    if (label) label.textContent = safe + '%';
  }

  function xhrUploadMedia(fd, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'activity-builder.php?id=' + activityId);
      xhr.setRequestHeader('X-Requested-With', 'fetch');
      xhr.responseType = 'text';
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable && typeof onProgress === 'function') {
          onProgress(Math.round((e.loaded / e.total) * 100));
        }
      };
      xhr.onload = () => {
        let data;
        try {
          data = JSON.parse(xhr.responseText || '{}');
        } catch (_) {
          reject(new Error('Invalid response'));
          return;
        }
        if (data.conflict) {
          state.revision = data.revision || state.revision;
          setSaveState('Conflict — another save happened. Reloading…', true);
          if (data.activity) state.activity = data.activity;
          if (data.tree) state.tree = data.tree;
          renderAll();
          reject(new Error(data.error || 'Revision conflict'));
          return;
        }
        if (xhr.status >= 400 || data.ok === false) {
          reject(new Error(data.error || 'Upload failed'));
          return;
        }
        if (data.revision) state.revision = data.revision;
        if (data.activity) state.activity = data.activity;
        if (data.tree) state.tree = data.tree;
        resolve(data);
      };
      xhr.onerror = () => reject(new Error('Network error'));
      xhr.send(fd);
    });
  }

  let lastMediaDropKey = '';
  let lastMediaDropAt = 0;

  async function uploadMediaFiles(files, questionId) {
    const list = Array.from(files || []).filter((f) => f && f.size);
    const dropKey = String(questionId || '') + ':' + list.map((f) =>
      String(f.name || '') + ':' + String(f.size || 0) + ':' + String(f.lastModified || 0)
    ).join('|');
    const now = Date.now();
    if (dropKey !== '' && dropKey === lastMediaDropKey && (now - lastMediaDropAt) < 800) {
      return;
    }
    lastMediaDropKey = dropKey;
    lastMediaDropAt = now;
    if (!list.length) return;
    const unique = [];
    const seen = new Set();
    let droppedDupes = 0;
    list.forEach((f) => {
      const key = String(f.name || '') + ':' + String(f.size || 0) + ':' + String(f.lastModified || 0);
      if (seen.has(key)) {
        droppedDupes += 1;
        return;
      }
      seen.add(key);
      unique.push(f);
    });
    if (droppedDupes > 0) {
      showToast('You cannot upload the same picture twice.', 'error');
      setSaveState('You cannot upload the same picture twice.', true);
    }
    const q = (state.tree.questions || []).find((x) => Number(x.id) === Number(questionId))
      || { question_type: isFlashcardMode ? 'flashcard' : '' };
    const limit = mediaLimitFor(q);
    const remaining = Math.max(0, limit - mediaCountFor(q));
    if (remaining <= 0) {
      alert(mediaCapMessage(limit));
      return;
    }
    const batch = unique.slice(0, remaining);
    const skipped = unique.length - batch.length;
    for (const file of batch) {
      const ok = await uploadMedia(file, questionId);
      if (!ok) break;
    }
    if (skipped > 0) {
      alert(
        'Only ' + remaining + ' more attachment' + (remaining === 1 ? '' : 's')
        + ' allowed on this ' + (limit === 1 ? 'card' : 'question') + '. '
        + skipped + ' file' + (skipped === 1 ? ' was' : 's were') + ' not added.'
      );
    }
  }

  async function uploadMedia(file, questionId) {
    if (!file) return false;
    const q = (state.tree.questions || []).find((x) => Number(x.id) === Number(questionId));
    if (q && mediaCountFor(q) >= mediaLimitFor(q)) {
      alert(mediaCapMessage(mediaLimitFor(q)));
      return false;
    }
    const fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'upload_media');
    fd.append('_token', csrf);
    fd.append('id', String(activityId));
    let mediaType = 'image';
    if (file.type.startsWith('audio/')) mediaType = 'audio';
    else if (file.type.startsWith('video/')) mediaType = 'video';
    fd.append('media_type', mediaType);
    if (questionId) fd.append('question_id', String(questionId));
    setMediaUploadProgress(0, true);
    setSaveState('Uploading media…');
    try {
      const data = await xhrUploadMedia(fd, (pct) => setMediaUploadProgress(pct, true));
      setMediaUploadProgress(100, true);
      if (data.duplicate) {
        const msg = mediaType === 'image'
          ? 'You cannot upload the same picture twice.'
          : 'You cannot attach the same file twice.';
        setSaveState(msg, true);
        showToast(msg, 'error');
        return false;
      }
      setSaveState('Media attached');
      const keepId = Number(questionId || state.selectedQuestionId || 0);
      renderAll();
      if (keepId) selectQuestion(keepId);
      return true;
    } catch (err) {
      setSaveState(err.message || 'Upload failed', true);
      alert(err.message || 'Upload failed');
      return false;
    } finally {
      setTimeout(() => setMediaUploadProgress(0, false), 400);
    }
  }

  async function recordAudio(questionId) {
    if (!navigator.mediaDevices || !window.MediaRecorder) {
      alert('Audio recording is not supported in this browser.');
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const recorder = new MediaRecorder(stream);
      const chunks = [];
      recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
      recorder.onstop = async () => {
        stream.getTracks().forEach(t => t.stop());
        const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        const file = new File([blob], 'recording.webm', { type: blob.type });
        await uploadMediaFiles([file], questionId);
      };
      recorder.start();
      setSaveState('Recording… click OK to stop');
      setTimeout(() => {
        if (recorder.state === 'recording') recorder.stop();
      }, 15000);
      if (confirm('Recording started. Click OK to stop (max 15s).')) {
        if (recorder.state === 'recording') recorder.stop();
      }
    } catch (err) {
      alert('Could not start recording: ' + (err.message || err));
    }
  }

  function renderAll() {
    renderStructure();
    renderValidation();
    renderSetupSummary();
    if (state.selectedQuestionId) renderEditor();
  }

  // Settings listeners
  root.querySelectorAll('[data-ab-setting],[data-ab-setting-bool],[data-ab-setting-minutes]').forEach(el => {
    el.addEventListener('change', markDirty);
    el.addEventListener('input', markDirty);
  });

  const settingsTitleInput = root.querySelector('[data-ab-setting="title"]');
  let titleEditBaseline = '';
  let titleEditBusy = false;

  settingsTitleInput?.addEventListener('input', () => {
    const titleEl = root.querySelector('[data-ab-title]');
    if (titleEl && !titleEl.isContentEditable) {
      titleEl.textContent = settingsTitleInput.value || 'Untitled activity';
    }
  });

  function selectTitleContents(el) {
    const range = document.createRange();
    range.selectNodeContents(el);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function endTitleEdit(commit) {
    const titleEl = root.querySelector('[data-ab-title]');
    if (!titleEl || !titleEl.isContentEditable || titleEditBusy) return;
    titleEditBusy = true;
    try {
      const raw = (titleEl.textContent || '').replace(/\s+/g, ' ').trim();
      const next = commit ? raw : titleEditBaseline;
      const safe = next || titleEditBaseline || 'Untitled activity';
      titleEl.textContent = safe;
      titleEl.contentEditable = 'false';
      titleEl.classList.remove('is-editing');
      if (commit && settingsTitleInput && settingsTitleInput.value !== safe) {
        settingsTitleInput.value = safe;
        markDirty();
      } else if (!commit) {
        titleEl.textContent = titleEditBaseline || settingsTitleInput?.value || 'Untitled activity';
      }
    } finally {
      titleEditBusy = false;
    }
  }

  function startTitleEdit() {
    const titleEl = root.querySelector('[data-ab-title]');
    if (!titleEl || titleEl.isContentEditable) return;
    titleEditBaseline = (settingsTitleInput?.value || titleEl.textContent || '').trim();
    titleEl.contentEditable = 'true';
    titleEl.classList.add('is-editing');
    titleEl.focus();
    selectTitleContents(titleEl);
  }

  root.querySelector('[data-ab-rename-title]')?.addEventListener('mousedown', (e) => {
    // Keep focus from leaving before we enter edit mode.
    e.preventDefault();
  });
  root.querySelector('[data-ab-rename-title]')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    startTitleEdit();
  });
  root.querySelector('[data-ab-title]')?.addEventListener('click', () => {
    startTitleEdit();
  });
  root.querySelector('[data-ab-title]')?.addEventListener('keydown', (e) => {
    const titleEl = e.currentTarget;
    if (!titleEl.isContentEditable) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        startTitleEdit();
      }
      return;
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      endTitleEdit(true);
      titleEl.blur();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      endTitleEdit(false);
      titleEl.blur();
    }
  });
  root.querySelector('[data-ab-title]')?.addEventListener('blur', () => endTitleEdit(true));

  const xpToggle = root.querySelector('[data-ab-setting-bool="xp_enabled"]');
  const leaderboardToggle = root.querySelector('[data-ab-setting-bool="leaderboard_enabled"]');
  const xpAmountInput = root.querySelector('[data-ab-setting="xp_amount"]');
  leaderboardToggle?.addEventListener('change', () => {
    if (leaderboardToggle.checked && xpToggle && !xpToggle.checked) {
      xpToggle.checked = true;
      if (xpAmountInput && Number(xpAmountInput.value || 0) <= 0) xpAmountInput.value = '25';
    }
    renderSetupSummary();
  });
  xpToggle?.addEventListener('change', () => {
    if (!xpToggle.checked && leaderboardToggle) leaderboardToggle.checked = false;
    if (xpToggle.checked && xpAmountInput && Number(xpAmountInput.value || 0) <= 0) xpAmountInput.value = '25';
    renderSetupSummary();
  });

  // Actions
  root.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ab-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-ab-action');
    try {
      if (action === 'open-type-picker') {
        els.typePicker?.showModal();
      } else if (action === 'add-flashcard') {
        const data = await api('add_question', {
          question_type: 'flashcard',
          prompt_html: '<p></p>',
          settings: { back: '' },
        });
        renderAll();
        if (data.question_id) selectQuestion(data.question_id);
      } else if (action === 'add-section') {
        const title = prompt('Section title', 'Section') || 'Section';
        await api('add_section', { title });
        renderAll();
      } else if (action === 'validate') {
        if (!await ensureSettingsSaved()) throw new Error('Save failed. Fix the highlighted settings before checking readiness.');
        const data = await api('validate', {});
        state.validation = data.validation || state.validation;
        renderValidation();
        setSaveState('Validation updated');
      } else if (action === 'publish') {
        if (!await ensureSettingsSaved()) throw new Error('Your latest settings could not be saved, so publishing was stopped.');
        const data = await api('publish', {});
        if (data.validation?.errors?.length) {
          state.validation = data.validation;
          renderValidation();
          alert('Fix validation issues before publishing.');
          return;
        }
        setSaveState('Published');
        location.reload();
      } else if (action === 'unpublish') {
        await api('unpublish', {});
        location.reload();
      } else if (action === 'preview') {
        if (!await ensureSettingsSaved()) throw new Error('Save the latest settings before opening the preview.');
        location.href = (boot.urls?.player || ('activity.php?id=' + activityId)) + '&preview=1';
      } else if (action === 'export-json') {
        const data = await api('export_json', {});
        const blob = new Blob([JSON.stringify(data.export, null, 2)], { type: 'application/json' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'activity-' + activityId + '.json';
        a.click();
      } else if (action === 'import-csv') {
        els.csvFile?.click();
      } else if (action === 'open-bank') {
        location.href = boot.urls?.bank || ('question-bank.php?activity_id=' + activityId);
      } else if (action === 'duplicate-activity') {
        const data = await api('duplicate_activity', {});
        if (data.redirect) location.href = data.redirect;
      }
    } catch (err) {
      alert(err.message || 'Action failed');
    }
  });

  els.typePicker?.querySelectorAll('[data-ab-type]').forEach(card => {
    card.addEventListener('click', async () => {
      const type = card.getAttribute('data-ab-type');
      try {
        const data = await api('add_question', { question_type: type, prompt_html: '<p></p>' });
        els.typePicker.close();
        renderAll();
        if (data.question_id) selectQuestion(data.question_id);
      } catch (err) { alert(err.message); }
    });
  });

  // Mobile drawers
  root.querySelectorAll('[data-ab-drawer]').forEach(btn => {
    btn.addEventListener('click', () => {
      const which = btn.getAttribute('data-ab-drawer');
      const panel = root.querySelector('[data-ab-panel="' + which + '"]');
      const backdrop = root.querySelector('[data-ab-drawer-backdrop]');
      const open = !panel?.classList.contains('ab-drawer-open');
      root.querySelectorAll('.ab-drawer').forEach(p => p.classList.remove('ab-drawer-open'));
      if (open && panel) {
        panel.classList.add('ab-drawer-open');
        if (backdrop) backdrop.hidden = false;
      } else if (backdrop) {
        backdrop.hidden = true;
      }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  root.querySelector('[data-ab-drawer-backdrop]')?.addEventListener('click', () => {
    root.querySelectorAll('.ab-drawer').forEach(p => p.classList.remove('ab-drawer-open'));
    const backdrop = root.querySelector('[data-ab-drawer-backdrop]');
    if (backdrop) backdrop.hidden = true;
  });

  els.mediaFile?.addEventListener('change', () => {
    const files = Array.from(els.mediaFile.files || []);
    uploadMediaFiles(files, Number(els.mediaFile.dataset.questionId || state.selectedQuestionId || 0));
    els.mediaFile.value = '';
  });
  els.camera?.addEventListener('change', () => {
    const file = els.camera.files?.[0];
    if (file) {
      uploadMediaFiles([file], Number(els.camera.dataset.questionId || state.selectedQuestionId || 0));
    }
    els.camera.value = '';
  });

  // Paste screenshot into editor
  document.addEventListener('paste', (e) => {
    if (!root.contains(document.activeElement) && !els.editor?.contains(document.activeElement)) return;
    const items = e.clipboardData?.items || [];
    for (const item of items) {
      if (item.type.startsWith('image/')) {
        e.preventDefault();
        const file = item.getAsFile();
        uploadMediaFiles([file], state.selectedQuestionId);
        break;
      }
    }
  });

  els.csvFile?.addEventListener('change', async () => {
    const file = els.csvFile.files?.[0];
    if (!file) return;
    const text = await file.text();
    try {
      const preview = await api('import_csv_preview', { csv: text });
      const msg = 'Valid: ' + preview.valid_count + ', invalid: ' + preview.invalid_count + '. Import valid rows?';
      if (confirm(msg)) {
        await api('import_csv_apply', { csv: text });
        renderAll();
        setSaveState('CSV imported');
      }
    } catch (err) {
      alert(err.message);
    }
    els.csvFile.value = '';
  });

  window.addEventListener('beforeunload', (e) => {
    if (state.dirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // Recovery
  try {
    const raw = localStorage.getItem(recoveryKey);
    if (raw) {
      const saved = JSON.parse(raw);
      if (saved?.at && Date.now() - saved.at < 86400000 && saved.revision === state.revision) {
        if (confirm('Restore unsaved builder draft from this browser?')) {
          // Apply recovered settings into fields
          Object.entries(saved.activity || {}).forEach(([k, v]) => {
            const el = root.querySelector('[data-ab-setting="' + k + '"]');
            if (el) el.value = v;
            const bel = root.querySelector('[data-ab-setting-bool="' + k + '"]');
            if (bel) bel.checked = !!Number(v);
          });
          markDirty();
        }
      }
    }
  } catch (_) { /* ignore */ }

  // Periodic save
  state.periodicTimer = setInterval(() => {
    if (state.dirty) saveSettings();
  }, 30000);

  // Instructions editor: portal-quill.js already claims [data-target] fields, so
  // only track changes on the existing instance — building a second Quill here
  // stacks a duplicate toolbar on the same field.
  if (typeof Quill !== 'undefined') {
    const instrNode = root.querySelector('[data-ab-quill="instructions_html"]');
    const ta = document.getElementById('ab-instructions');
    if (instrNode) {
      const existing = instrNode._quill
        || (typeof Quill.find === 'function' ? Quill.find(instrNode) : null);
      if (existing) {
        existing.on('text-change', () => markDirty());
        instrNode._quill = existing;
      } else if (instrNode.dataset.quillReady !== '1') {
        const quill = new Quill(instrNode, {
          theme: 'snow',
          modules: { toolbar: [['bold', 'italic'], ['link'], [{ list: 'ordered' }, { list: 'bullet' }]] },
        });
        setQuillHtml(quill, ta?.value || '');
        quill.on('text-change', () => {
          if (ta) ta.value = quill.root.innerHTML;
          markDirty();
        });
        instrNode._quill = quill;
        instrNode.dataset.quillReady = '1';
      }
    }
  }

  if ((state.tree.questions || []).length && !state.selectedQuestionId) {
    state.selectedQuestionId = Number(state.tree.questions[0].id);
  }
  renderAll();
})();
