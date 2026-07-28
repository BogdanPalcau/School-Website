/**
 * Activity Player client
 */
(function () {
  'use strict';

  const root = document.getElementById('activity-player');
  const bootEl = document.getElementById('ap-bootstrap');
  if (!root || !bootEl) return;

  let boot;
  try {
    boot = JSON.parse(bootEl.textContent || '{}');
  } catch (e) {
    console.error('Invalid player bootstrap');
    return;
  }

  const csrf = root.dataset.csrf || boot.csrf || '';
  const activityId = Number(boot.activity?.id || root.dataset.activityId || 0);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const state = {
    token: null,
    attempt: null,
    activity: boot.activity || {},
    questions: [],
    answers: {},
    sections: [],
    index: 0,
    saveQueue: [],
    online: navigator.onLine,
    timerInterval: null,
    integrityEnabled: !!(boot.activity?.integrity_enabled),
    mode: boot.activity?.mode || 'quiz',
    startedAtMs: Date.now(),
    isFinishing: false,
    leaveSent: false,
    exitConfirmed: false,
    connectionExpired: false,
    offlineDeadline: 0,
    offlineTimer: null,
    historyGuardArmed: false,
    flushPromise: null,
  };

  const ASSESSMENT_NETWORK_GRACE_MS = 30000;

  const els = {
    landing: root.querySelector('[data-ap-landing]'),
    shell: root.querySelector('[data-ap-shell]'),
    result: root.querySelector('[data-ap-result]'),
    questionRoot: root.querySelector('[data-ap-question-root]'),
    nav: root.querySelector('[data-ap-nav]'),
    counter: root.querySelector('[data-ap-question-counter]'),
    progressFill: root.querySelector('[data-ap-progress-fill]'),
    progressText: root.querySelector('[data-ap-progress-text]'),
    timer: root.querySelector('[data-ap-timer]'),
    timerValue: root.querySelector('[data-ap-timer-value]'),
    saveState: root.querySelector('[data-ap-save-state]'),
    feedback: root.querySelector('[data-ap-feedback]'),
    banner: root.querySelector('[data-ap-banner]'),
    network: root.querySelector('[data-ap-network]'),
    networkTitle: root.querySelector('[data-ap-network-title]'),
    networkText: root.querySelector('[data-ap-network-text]'),
    ack: root.querySelector('[data-ap-integrity-ack]'),
    ackError: root.querySelector('[data-ap-integrity-error]'),
    integrityNotice: root.querySelector('[data-ap-integrity-notice]'),
    confirm: root.querySelector('[data-ap-confirm]'),
    confirmBody: root.querySelector('[data-ap-confirm-body]'),
    confirmOk: root.querySelector('[data-ap-confirm-ok]'),
    confirmCancel: root.querySelector('[data-ap-confirm-cancel]'),
  };

  async function api(action, body) {
    const res = await fetch('activity.php?id=' + activityId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'fetch',
      },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({
        action,
        _token: csrf,
        id: activityId,
        token: state.token,
        attempt_id: state.attempt?.id || 0,
      }, body || {})),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
    if (!res.ok || data.ok === false) {
      const err = new Error(data.error || 'Request failed');
      err.data = data;
      throw err;
    }
    return data;
  }

  function setSaveState(msg) {
    if (els.saveState) els.saveState.textContent = msg || '';
  }

  function answerValueForQuestion(q, answer) {
    if (answer == null || typeof answer !== 'object') return answer;

    switch (q.question_type) {
      case 'single_choice':
      case 'true_false':
        return answer.option_id ?? answer.value ?? null;
      case 'multiple_choice':
        return Array.isArray(answer.option_ids) ? answer.option_ids : [];
      case 'ordering':
        return Array.isArray(answer.order) ? answer.order : [];
      case 'matching':
        return answer.matches && typeof answer.matches === 'object' ? answer.matches : {};
      case 'numeric':
      case 'rating_scale':
        return answer.value ?? answer.number ?? '';
      case 'fill_blank':
        return Array.isArray(answer.blanks) ? answer.blanks : [];
      default:
        return answer.text ?? answer.value ?? '';
    }
  }

  function answerIsEmpty(q, answer) {
    const value = answerValueForQuestion(q, answer);
    if (value == null || value === '') return true;
    if (Array.isArray(value)) {
      return value.length === 0 || value.every((entry) => entry == null || entry === '');
    }
    if (typeof value === 'object') {
      const entries = Object.values(value);
      return entries.length === 0 || entries.every((entry) => entry == null || entry === '');
    }
    return false;
  }

  function answeredCount() {
    return state.questions.filter(q => {
      const a = state.answers[q.id];
      return !!a && !answerIsEmpty(q, a.answer);
    }).length;
  }

  function updateProgress() {
    const total = state.questions.length || 1;
    const done = answeredCount();
    const pct = Math.round((done / total) * 100);
    if (els.progressFill) els.progressFill.style.width = pct + '%';
    if (els.progressText) els.progressText.textContent = pct + '%';
    if (els.counter) els.counter.textContent = 'Question ' + (state.index + 1) + ' of ' + state.questions.length;
  }

  function loadFromPayload(data) {
    clearNetworkGrace();
    state.isFinishing = false;
    state.leaveSent = false;
    state.exitConfirmed = false;
    state.connectionExpired = false;
    root.classList.remove('ap-assessment-paused');
    if (els.network) els.network.hidden = true;
    state.token = data.token || state.token;
    state.attempt = data.attempt || null;
    state.activity = Object.assign({}, state.activity, data.activity || {});
    state.questions = data.questions || [];
    state.answers = data.answers || {};
    state.sections = data.sections || [];
    state.integrityEnabled = !!(state.activity.integrity_enabled);
    state.mode = state.activity.mode || state.mode;
    state.index = 0;
    showShell();
    armAssessmentHistoryGuard();
    renderQuestion();
    startTimer();
    setupIntegrity();
    flushQueue();
  }

  function showShell() {
    if (els.landing) els.landing.hidden = true;
    if (els.result) els.result.hidden = true;
    if (els.shell) els.shell.hidden = false;
  }

  function armAssessmentHistoryGuard() {
    if (state.mode !== 'assessment' || state.historyGuardArmed) return;
    window.history.pushState({ rieoAssessmentGuard: true }, '', window.location.href);
    state.historyGuardArmed = true;
  }

  function assessmentIsActive() {
    return state.mode === 'assessment'
      && !state.isFinishing
      && !!state.attempt?.id
      && !!state.token
      && (!els.shell || !els.shell.hidden);
  }

  function clearNetworkGrace() {
    if (state.offlineTimer) window.clearInterval(state.offlineTimer);
    state.offlineTimer = null;
    state.offlineDeadline = 0;
  }

  function updateNetworkGrace() {
    if (!els.network || !state.offlineDeadline) return;
    const seconds = Math.max(0, Math.ceil((state.offlineDeadline - Date.now()) / 1000));
    if (els.networkTitle) els.networkTitle.textContent = 'Connection lost — ' + seconds + 's to reconnect';
    if (els.networkText) {
      els.networkText.textContent = 'Your answers remain saved on this device. Reconnect before the countdown ends to continue.';
    }
    if (seconds > 0) return;
    clearNetworkGrace();
    state.connectionExpired = true;
    root.classList.add('ap-assessment-paused');
    if (els.networkTitle) els.networkTitle.textContent = 'Assessment ended: connection lost';
    if (els.networkText) {
      els.networkText.textContent = 'The 30-second recovery window expired. Reconnect so the attempt can be safely closed.';
    }
    setSaveState('Connection recovery window expired');
  }

  function startNetworkGrace() {
    if (!assessmentIsActive() || state.connectionExpired) return;
    state.offlineDeadline = Date.now() + ASSESSMENT_NETWORK_GRACE_MS;
    if (els.network) els.network.hidden = false;
    updateNetworkGrace();
    state.offlineTimer = window.setInterval(updateNetworkGrace, 250);
  }

  async function closeExpiredConnectionAttempt() {
    if (!state.connectionExpired || !assessmentIsActive()) return;
    state.isFinishing = true;
    if (els.networkTitle) els.networkTitle.textContent = 'Connection restored';
    if (els.networkText) els.networkText.textContent = 'Closing the ended attempt and returning to the activity page…';
    try {
      await api('leave_assessment', { end_reason: 'connection_lost' });
      window.location.reload();
    } catch (err) {
      state.isFinishing = false;
      if (els.networkTitle) els.networkTitle.textContent = 'Could not close the attempt';
      if (els.networkText) els.networkText.textContent = 'Your connection is back. Refresh the page to finish closing this attempt.';
    }
  }

  function showLanding() {
    if (els.shell) els.shell.hidden = true;
    if (els.result) els.result.hidden = true;
    if (els.landing) els.landing.hidden = false;
  }

  function currentQuestion() {
    return state.questions[state.index] || null;
  }

  function renderNav() {
    if (!els.nav) return;
    els.nav.innerHTML = '';
    state.questions.forEach((q, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      const answered = !!state.answers[q.id] && !answerIsEmpty(q, state.answers[q.id].answer);
      b.className = 'ap-qnav-item'
        + (i === state.index ? ' is-current' : '')
        + (answered ? ' is-answered' : '');
      b.innerHTML = '<span class="ap-qnav-num">' + (i + 1) + '</span><span class="ap-qnav-label">Question ' + (i + 1) + '</span>';
      b.setAttribute('aria-label', 'Go to question ' + (i + 1) + (answered ? ', answered' : ''));
      b.setAttribute('aria-current', i === state.index ? 'true' : 'false');
      b.addEventListener('click', () => {
        if (!canNavigateTo(i)) return;
        state.index = i;
        renderQuestion();
      });
      els.nav.appendChild(b);
    });
  }

  function canNavigateTo(i) {
    const policy = state.activity.navigation_policy || 'free';
    if (policy === 'free') return true;
    if (policy === 'sequential') return i <= state.index + 1 && i >= 0;
    if (policy === 'no_return') return i >= state.index;
    return true;
  }

  function optionLetter(i) {
    return String.fromCharCode(65 + (i % 26));
  }

  const BADGE_ICON_PATHS = {
    steps: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    flame: '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 17a2.5 2.5 0 0 0 2.5-2.5c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7.5 7.5 0 1 1-15 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 15.3-6.36L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.3 6.36L3 16"/><path d="M8 21v-5H3"/>',
    map: '<polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>',
    practice: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8"/><path d="M8 11h6"/><path d="M8 15h4"/>',
    bolt: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    badge: '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
  };

  function badgeIconSvg(key) {
    const body = BADGE_ICON_PATHS[key] || BADGE_ICON_PATHS.badge;
    return '<svg class="ap-badge-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
  }

  function fireConfetti(container) {
    if (reduceMotion || !container) return;
    const burst = document.createElement('div');
    burst.className = 'ap-confetti';
    burst.setAttribute('aria-hidden', 'true');
    const colors = ['#c1202f', '#f2b705', '#2f8f5b', '#2f6fed', '#a24fd6'];
    for (let i = 0; i < 26; i++) {
      const piece = document.createElement('span');
      piece.style.setProperty('--ap-confetti-x', (Math.random() * 200 - 100).toFixed(0) + 'px');
      piece.style.setProperty('--ap-confetti-rot', (Math.random() * 720 - 360).toFixed(0) + 'deg');
      piece.style.setProperty('--ap-confetti-delay', (Math.random() * 0.25).toFixed(2) + 's');
      piece.style.left = (Math.random() * 100).toFixed(1) + '%';
      piece.style.background = colors[i % colors.length];
      burst.appendChild(piece);
    }
    container.appendChild(burst);
    setTimeout(() => burst.remove(), 2200);
  }

  function renderChoice(type, o, i, checked) {
    const inputType = type === 'multiple_choice' ? 'checkbox' : 'radio';
    const nameAttr = inputType === 'radio' ? ' name="q' + (currentQuestion()?.id || '') + '"' : '';
    return '<label class="ap-option">'
      + '<input class="visually-hidden" type="' + inputType + '"' + nameAttr + ' value="' + o.id + '"' + checked + '>'
      + '<span class="ap-option-marker" aria-hidden="true">' + optionLetter(i) + '</span>'
      + '<span class="ap-option-body">' + (o.option_text_html || '') + '</span>'
      + '</label>';
  }

  function renderQuestion() {
    const q = currentQuestion();
    if (!q || !els.questionRoot) return;
    renderNav();
    updateProgress();

    const prevBtn = root.querySelector('[data-ap-action="prev"]');
    const nextBtn = root.querySelector('[data-ap-action="next"]');
    const submitBtn = root.querySelector('.ap-btn-submit[data-ap-action="submit"]');
    const earlySubmit = root.querySelector('[data-ap-early-submit]');
    const total = state.questions.length;
    const onLast = total > 0 && state.index >= total - 1;
    const noReturn = state.activity.navigation_policy === 'no_return';

    if (prevBtn) prevBtn.disabled = state.index <= 0 || noReturn;
    if (nextBtn) {
      nextBtn.hidden = onLast;
      nextBtn.disabled = onLast || !canNavigateTo(state.index + 1);
    }
    if (submitBtn) {
      submitBtn.hidden = !onLast;
      submitBtn.disabled = !onLast;
    }
    // Quiet early-submit link only while there are still questions ahead.
    if (earlySubmit) earlySubmit.hidden = onLast || total <= 1;

    const existing = answerValueForQuestion(q, state.answers[q.id]?.answer);
    const points = q.points != null ? Number(q.points) : null;
    let html = '<div class="ap-q-head">'
      + '<p class="ap-q-kicker">Question ' + (state.index + 1) + ' of ' + state.questions.length + '</p>'
      + (points != null ? '<span class="ap-q-points">' + (points === 1 ? '1 point' : points + ' points') + '</span>' : '')
      + '</div>';
    html += '<div class="ap-q-stem ap-rich">' + (q.prompt_html || '') + '</div>';
    html += '<div class="ap-answer" data-ap-answer>';

    switch (q.question_type) {
      case 'single_choice':
      case 'true_false':
        (q.options || []).forEach((o, i) => {
          const checked = Number(existing) === Number(o.id) || existing === o.id ? ' checked' : '';
          html += renderChoice(q.question_type, o, i, checked);
        });
        break;
      case 'multiple_choice': {
        const selected = Array.isArray(existing) ? existing.map(Number) : [];
        (q.options || []).forEach((o, i) => {
          const checked = selected.includes(Number(o.id)) ? ' checked' : '';
          html += renderChoice('multiple_choice', o, i, checked);
        });
        break;
      }
      case 'long_response':
        html += '<textarea class="ap-text-input" rows="8" data-ap-input maxlength="50000" placeholder="Type your answer…">' + escapeText(existing || '') + '</textarea>';
        break;
      case 'numeric':
        html += '<input class="ap-text-input ap-text-input--narrow" type="number" step="any" data-ap-input value="' + escapeText(existing ?? '') + '" placeholder="Enter a number">';
        break;
      case 'rating_scale': {
        const min = Number(q.settings?.min ?? 1);
        const max = Number(q.settings?.max ?? 5);
        html += '<div class="ap-rating">';
        for (let i = min; i <= max; i++) {
          html += '<label class="ap-rating-opt"><input type="radio" name="q' + q.id + '" value="' + i + '"' + (Number(existing) === i ? ' checked' : '') + '><span>' + i + '</span></label>';
        }
        html += '</div>';
        break;
      }
      case 'ordering':
        html += '<p class="ap-hint">Set the order for each item (1 = first).</p>';
        (q.options || []).forEach((o, i) => {
          const orderVal = Array.isArray(existing) ? (existing.indexOf(o.id) + 1 || i + 1)
            : (Array.isArray(existing?.order) ? (existing.order.indexOf(o.id) + 1 || i + 1) : (i + 1));
          html += '<div class="ap-order-row"><span class="ap-order-text">' + (o.option_text_html || '') + '</span>' +
            '<input class="ap-text-input ap-text-input--order" type="number" min="1" data-order-id="' + o.id + '" value="' + orderVal + '" aria-label="Order"></div>';
        });
        break;
      case 'matching': {
        const lefts = Array.isArray(q.settings?.match_lefts) ? q.settings.match_lefts : [];
        let choices = Array.isArray(q.settings?.match_choices) ? q.settings.match_choices.slice() : [];
        // Stable shuffle per attempt+question so refresh keeps the same pool order.
        const seed = String((state.attempt && state.attempt.id) || 0) + ':' + String(q.id);
        choices = seededShuffle(choices, seed);
        const given = (existing && typeof existing === 'object' && !Array.isArray(existing))
          ? (existing.matches || existing)
          : {};
        html += '<p class="ap-hint">Match each item on the left with an answer on the right.</p>';
        html += '<div class="ap-match-list">';
        lefts.forEach((left) => {
          const selected = given[left] != null ? String(given[left]) : '';
          html += '<div class="ap-match-row">' +
            '<span class="ap-match-left">' + escapeText(left) + '</span>' +
            '<select class="ap-text-input ap-match-select" data-ap-match-left="' + escapeText(left) + '" aria-label="Match for ' + escapeText(left) + '">' +
            '<option value="">Choose…</option>';
          choices.forEach((choice) => {
            const val = String(choice);
            html += '<option value="' + escapeText(val) + '"' + (selected === val ? ' selected' : '') + '>' +
              escapeText(val) + '</option>';
          });
          html += '</select></div>';
        });
        html += '</div>';
        if (!lefts.length) {
          html += '<p class="ap-hint">This matching question has no pairs yet.</p>';
        }
        break;
      }
      default:
        html += '<input class="ap-text-input" type="text" data-ap-input value="' + escapeText(existing || '') + '" placeholder="Type your answer…">';
    }
    html += '</div>';
    if (q.hint_html) html += '<details class="ap-hint-block"><summary>Show hint</summary><div class="ap-rich">' + q.hint_html + '</div></details>';

    els.questionRoot.innerHTML = html;
    if (els.feedback) {
      els.feedback.innerHTML = '';
      els.feedback.hidden = true;
    }
    els.questionRoot.classList.remove('ap-anim-in');
    if (!reduceMotion && ['practice', 'quiz', 'challenge'].includes(state.mode)) {
      void els.questionRoot.offsetWidth;
      els.questionRoot.classList.add('ap-anim-in');
    }

    const answerBox = els.questionRoot.querySelector('[data-ap-answer]');
    answerBox?.addEventListener('change', () => {
      queueSave(q);
      renderNav();
      updateProgress();
    });
    answerBox?.addEventListener('input', debounce(() => queueSave(q), 600));
  }

  function escapeText(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function seededShuffle(list, seed) {
    const arr = list.slice();
    let h = 2166136261;
    for (let i = 0; i < seed.length; i++) {
      h ^= seed.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    for (let i = arr.length - 1; i > 0; i--) {
      h = (h + 0x6D2B79F5) | 0;
      let t = Math.imul(h ^ (h >>> 15), 1 | h);
      t ^= t + Math.imul(t ^ (t >>> 7), 61 | t);
      const j = ((t ^ (t >>> 14)) >>> 0) % (i + 1);
      const tmp = arr[i];
      arr[i] = arr[j];
      arr[j] = tmp;
    }
    return arr;
  }

  function readAnswer(q) {
    const box = els.questionRoot.querySelector('[data-ap-answer]');
    if (!box) return null;
    switch (q.question_type) {
      case 'single_choice':
      case 'true_false': {
        const sel = box.querySelector('input[type="radio"]:checked');
        return sel ? { option_id: Number(sel.value) } : null;
      }
      case 'rating_scale': {
        const sel = box.querySelector('input[type="radio"]:checked');
        return sel ? { value: Number(sel.value) } : null;
      }
      case 'multiple_choice':
        return { option_ids: Array.from(box.querySelectorAll('input[type="checkbox"]:checked')).map(el => Number(el.value)) };
      case 'ordering': {
        const rows = Array.from(box.querySelectorAll('[data-order-id]'));
        rows.sort((a, b) => Number(a.value) - Number(b.value));
        return { order: rows.map(r => Number(r.getAttribute('data-order-id'))) };
      }
      case 'matching': {
        const matches = {};
        let filled = 0;
        box.querySelectorAll('[data-ap-match-left]').forEach((sel) => {
          const left = sel.getAttribute('data-ap-match-left') || '';
          const val = sel.value || '';
          if (left !== '' && val !== '') {
            matches[left] = val;
            filled++;
          }
        });
        return filled ? { matches } : null;
      }
      case 'numeric': {
        const v = box.querySelector('[data-ap-input]')?.value;
        const unit = box.querySelector('[data-ap-unit]')?.value || '';
        return v === '' || v == null ? null : { value: Number(v), unit };
      }
      case 'fill_blank': {
        const blanks = Array.from(box.querySelectorAll('[data-ap-blank]')).map(el => el.value || '');
        return { blanks };
      }
      default:
        return { text: box.querySelector('[data-ap-input], textarea')?.value ?? '' };
    }
  }

  function queueSave(q) {
    const answer = readAnswer(q);
    const prev = state.answers[q.id] || { revision: 0 };
    const revision = Number(prev.revision || 0) + 1;
    state.answers[q.id] = { answer, revision };
    updateProgress();
    state.saveQueue.push({ question_id: q.id, answer, revision });
    setSaveState(state.online ? 'Saving…' : 'Offline — queued');
    flushQueue();
  }

  async function flushQueue() {
    if (!state.online || !state.token || !state.attempt) return;
    if (state.flushPromise) return state.flushPromise;

    state.flushPromise = (async () => {
      while (state.saveQueue.length) {
        const item = state.saveQueue[0];
        try {
          const data = await api('save_answer', item);
          if (state.answers[item.question_id]) {
            state.answers[item.question_id].revision = data.revision || item.revision;
          }
          state.saveQueue.shift();
          setSaveState('Saved');
          if (data.feedback && els.feedback) {
            showImmediateFeedback(data.feedback);
          } else {
            funPulse();
          }
        } catch (err) {
          if (err.data?.conflict) {
            state.answers[item.question_id] = {
              answer: item.answer,
              revision: err.data.revision || item.revision,
            };
            state.saveQueue.shift();
            setSaveState('Synced');
            continue;
          }
          setSaveState(err.message || 'Save failed');
          break;
        }
      }
    })();

    try {
      await state.flushPromise;
    } finally {
      state.flushPromise = null;
    }
  }

  function funPulse() {
    if (reduceMotion) return;
    if (!['practice', 'quiz', 'challenge'].includes(state.mode)) return;
    els.questionRoot?.classList.add('ap-fun-pulse');
    setTimeout(() => els.questionRoot?.classList.remove('ap-fun-pulse'), 400);
  }

  function showImmediateFeedback(fb) {
    if (!els.feedback || !fb) return;
    const correct = fb.correct === true;
    const partial = fb.correct === null ? false : fb.correct === false && Number(fb.score) > 0;
    let cls = 'ap-feedback';
    if (correct) cls += ' ap-feedback--correct ap-correct-glow';
    else if (partial) cls += ' ap-feedback--partial';
    else cls += ' ap-feedback--incorrect' + (reduceMotion ? '' : ' ap-incorrect-shake');

    let html = '<div class="' + cls + '" role="status" aria-live="polite">';
    html += '<strong>' + escapeText(fb.message || (correct ? 'Correct!' : 'Not quite')) + '</strong>';
    if (fb.score != null && fb.points != null) {
      html += '<span> ' + escapeText(String(fb.score)) + ' / ' + escapeText(String(fb.points)) + ' pts</span>';
    }
    if (fb.explanation_html) html += '<div class="ap-rich">' + fb.explanation_html + '</div>';
    if (fb.option_feedback_html) html += '<div class="ap-rich">' + fb.option_feedback_html + '</div>';
    if (correct && !reduceMotion && ['practice', 'quiz', 'challenge'].includes(state.mode)) {
      html += '<span class="ap-xp-bubble" aria-hidden="true">+</span>';
    }
    html += '</div>';
    els.feedback.innerHTML = html;
    els.feedback.hidden = false;
  }

  function debounce(fn, ms) {
    let t;
    return function (...args) {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  function startTimer() {
    clearInterval(state.timerInterval);
    const expires = state.attempt?.expires_at;
    if (!expires || !els.timer) {
      if (els.timer) els.timer.hidden = true;
      return;
    }
    els.timer.hidden = false;
    const tick = async () => {
      const end = Date.parse(expires.replace(' ', 'T') + 'Z') || Date.parse(expires);
      const now = Date.now();
      let remain = Math.max(0, Math.floor((end - now) / 1000));
      if (els.timerValue) {
        const m = Math.floor(remain / 60);
        const s = remain % 60;
        els.timerValue.textContent = m + ':' + String(s).padStart(2, '0');
      }
      if (remain <= 0) {
        clearInterval(state.timerInterval);
        try {
          await flushQueue();
          const data = await api('submit', {});
          showResult(data);
        } catch (err) {
          alert('Time is up. ' + (err.message || ''));
        }
      }
    };
    tick();
    state.timerInterval = setInterval(tick, 1000);
    // Sync every 30s
    setInterval(async () => {
      if (!state.attempt?.id || !state.token) return;
      try {
        const data = await api('sync_timer', {});
        if (data.attempt?.status && data.attempt.status !== 'in_progress') {
          const result = await api('result', { attempt_id: state.attempt.id });
          showResult({ player: result, attempt: result.attempt });
        }
      } catch (_) { /* ignore */ }
    }, 30000);
  }

  function setupIntegrity() {
    if (!state.integrityEnabled || state.mode !== 'assessment') return;

    const send = (eventType, metadata, sourceClassification) => {
      const key = eventType + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
      api('integrity_event', {
        event_type: eventType,
        idempotency_key: key,
        question_id: currentQuestion()?.id || null,
        source_classification: sourceClassification || 'source_not_available',
        metadata: metadata || {},
        client_elapsed_ms: Date.now() - state.startedAtMs,
        occurred_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      }).catch(() => {});
    };

    document.addEventListener('paste', (e) => {
      const policy = state.activity.paste_policy || 'allow';
      const html = e.clipboardData?.getData('text/html') || '';
      const text = e.clipboardData?.getData('text/plain') || '';
      const meta = {
        char_count: text.length,
        has_html: html ? 1 : 0,
      };
      // NEVER send clipboard text
      let classification = 'source_not_available';
      if (html) {
        if (/https?:\/\//i.test(html)) classification = 'external_or_unknown';
      }
      if (policy === 'block_log') {
        e.preventDefault();
        send('paste_blocked', meta, classification);
      } else if (policy === 'allow_log') {
        send('paste_attempt', meta, classification);
      } else {
        send('paste_allowed', meta, classification);
      }
    });

    document.addEventListener('copy', (e) => {
      const policy = state.activity.copy_policy || 'allow';
      if (policy === 'block_log') {
        e.preventDefault();
        send('copy_attempt', { blocked: 1 });
      } else if (policy === 'log') {
        send('copy_attempt', {});
      }
    });

    document.addEventListener('visibilitychange', () => {
      if (document.hidden) send('visibility_hidden', {});
    });
    window.addEventListener('blur', () => send('window_blur', {}));
    window.addEventListener('focus', () => send('window_focus', {}));

    document.addEventListener('fullscreenchange', () => {
      if (!document.fullscreenElement) send('fullscreen_exit', {});
    });

    try {
      const channel = new BroadcastChannel('portal-activity-' + activityId);
      channel.postMessage({ type: 'hello', attempt: state.attempt?.id });
      channel.onmessage = (ev) => {
        if (ev.data?.type === 'hello' && ev.data.attempt !== state.attempt?.id) {
          send('multiple_tab_detected', {});
        }
      };
    } catch (_) { /* BroadcastChannel unavailable */ }
  }

  function showResult(data) {
    state.isFinishing = true;
    clearInterval(state.timerInterval);
    if (els.shell) els.shell.hidden = true;
    if (els.landing) els.landing.hidden = true;
    if (!els.result) return;
    els.result.hidden = false;

    const player = data.player || data;
    const attempt = player.attempt || data.attempt || {};
    const gamification = data.gamification || null;
    const showScore = attempt.percentage != null;
    const pct = showScore ? Math.round(Number(attempt.percentage)) : null;
    const celebrateMode = !reduceMotion && ['practice', 'quiz', 'challenge'].includes(state.mode);
    const hasRewards = !!gamification && ((gamification.xp || 0) > 0 || (gamification.badges || []).length > 0);

    let html = '<article class="ap-result-card' + (showScore && celebrateMode ? ' ap-anim-celebrate' : '') + '">';
    html += '<div class="ap-toolbar"><a class="ap-back" href="' + (boot.urls?.course || 'courses.php') + '"><span aria-hidden="true">←</span> Back to course</a></div>';

    if (showScore) {
      const tier = pct >= 90 ? 'gold' : (pct >= 70 ? 'good' : (pct >= 50 ? 'ok' : 'low'));
      const tierLabel = pct >= 90 ? 'Excellent work' : (pct >= 70 ? 'Nice work' : (pct >= 50 ? 'Submitted' : 'Keep practising'));
      html += '<div class="ap-result-head">';
      html += '<div class="ap-score-ring ap-score-ring--' + tier + '" style="--ap-score-pct:' + pct + '">'
        + '<span class="ap-score-ring-value">' + pct + '<small>%</small></span></div>';
      html += '<div class="ap-result-head-text">';
      html += '<p class="ap-result-kicker">' + escapeText(tierLabel) + '</p>';
      html += '<h2>Submitted</h2>';
      html += '<p class="ap-lobby-lead">Attempt ' + (attempt.attempt_number || '') + ' · ' + escapeText(attempt.status || '') + '</p>';
      html += '</div></div>';
    } else {
      html += '<h2>Submitted</h2>';
      html += '<p class="ap-lobby-lead">Attempt ' + (attempt.attempt_number || '') + ' · ' + escapeText(attempt.status || '') + '</p>';
      html += '<p>Your responses were submitted. Results will appear when released.</p>';
    }

    if (hasRewards) {
      html += '<div class="ap-rewards-panel">';
      html += '<p class="ap-panel-title">Earned this attempt</p>';
      html += '<div class="ap-rewards-row">';
      if ((gamification.xp || 0) > 0) {
        html += '<span class="ap-xp-chip">' + badgeIconSvg('bolt') + '<span>+' + gamification.xp + ' XP</span></span>';
      }
      (gamification.badges || []).forEach((b) => {
        html += '<span class="ap-badge-chip" title="' + escapeText(b.description || '') + '">'
          + badgeIconSvg(b.icon) + '<span>' + escapeText(b.title) + '</span></span>';
      });
      html += '</div></div>';
    } else if (gamification?.pending_review) {
      html += '<div class="ap-rewards-panel ap-rewards-panel--pending">';
      html += '<p class="ap-panel-title">XP pending teacher review</p>';
      html += '<p>Your assessment reward is added after marking, integrity review, and result release.</p>';
      html += '</div>';
    }

    if (player.questions && showScore) {
      html += '<div class="ap-result-questions">';
      (player.questions || []).forEach((q, i) => {
        const ans = player.answers?.[q.id];
        html += '<div class="ap-result-q"><strong>Q' + (i + 1) + '</strong>';
        if (ans?.score != null) html += ' · ' + ans.score + ' pts';
        if (q.explanation_html) html += '<div class="ap-rich">' + q.explanation_html + '</div>';
        if (ans?.feedback_html) html += '<div class="ap-rich">' + ans.feedback_html + '</div>';
        html += '</div>';
      });
      html += '</div>';
    }
    html += '<div class="ap-landing-actions"><a class="button" href="activity.php?id=' + activityId + '">Back to activity</a></div>';
    html += '</article>';
    els.result.innerHTML = html;

    if (celebrateMode && (pct >= 70 || hasRewards)) {
      fireConfetti(els.result.querySelector('.ap-result-card'));
    }
  }

  async function startOrResume(isResume) {
    if (boot.needs_integrity_ack && !isResume) {
      if (!els.ack?.checked) {
        if (els.ackError) els.ackError.hidden = false;
        els.integrityNotice?.classList.add('ap-panel--needs-attention');
        els.integrityNotice?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        els.ack?.focus();
        return;
      }
    }
    try {
      const ack = els.ack?.checked ? 'acknowledged' : '';
      const data = await api(isResume ? 'resume' : 'start', { integrity_ack: ack });
      loadFromPayload(data);
    } catch (err) {
      alert(err.message || 'Could not start');
    }
  }

  els.ack?.addEventListener('change', () => {
    if (els.ackError) els.ackError.hidden = true;
    els.integrityNotice?.classList.remove('ap-panel--needs-attention');
  });

  function askConfirm(message, options = {}) {
    return new Promise((resolve) => {
      const overlay = els.confirm;
      if (!overlay || !els.confirmBody || !els.confirmOk || !els.confirmCancel) {
        resolve(window.confirm(message));
        return;
      }

      const titleEl = overlay.querySelector('.ap-confirm-title');
      const warn = !!options.warning;
      if (titleEl) titleEl.textContent = options.title || 'Submit quiz?';
      els.confirmBody.textContent = message;
      overlay.classList.toggle('ap-confirm--warn', warn);
      els.confirmOk.textContent = options.confirmLabel || 'Submit quiz';
      els.confirmCancel.textContent = options.cancelLabel || 'Keep editing';

      const previouslyFocused = document.activeElement;

      const finish = (ok) => {
        overlay.hidden = true;
        overlay.classList.remove('ap-confirm--in');
        document.removeEventListener('keydown', onKey, true);
        overlay.removeEventListener('click', onOverlay);
        els.confirmOk.removeEventListener('click', onOk);
        els.confirmCancel.removeEventListener('click', onCancel);
        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
          previouslyFocused.focus();
        }
        resolve(ok);
      };
      const onOk = () => finish(true);
      const onCancel = () => finish(false);
      const onOverlay = (e) => {
        if (e.target === overlay) finish(false);
      };
      const onKey = (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          finish(false);
        } else if (e.key === 'Tab') {
          const focusables = [els.confirmCancel, els.confirmOk];
          const idx = focusables.indexOf(document.activeElement);
          if (e.shiftKey) {
            if (idx <= 0) {
              e.preventDefault();
              els.confirmOk.focus();
            }
          } else if (idx === focusables.length - 1) {
            e.preventDefault();
            els.confirmCancel.focus();
          }
        }
      };

      overlay.hidden = false;
      requestAnimationFrame(() => overlay.classList.add('ap-confirm--in'));
      els.confirmOk.addEventListener('click', onOk);
      els.confirmCancel.addEventListener('click', onCancel);
      overlay.addEventListener('click', onOverlay);
      document.addEventListener('keydown', onKey, true);
      // Focus cancel so Enter doesn't accidentally submit.
      els.confirmCancel.focus();
    });
  }

  root.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ap-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-ap-action');
    if (action === 'start') startOrResume(false);
    else if (action === 'resume') startOrResume(true);
    else if (action === 'prev') {
      if (state.index > 0 && canNavigateTo(state.index - 1)) {
        state.index--;
        renderQuestion();
      }
    } else if (action === 'next') {
      if (state.index < state.questions.length - 1 && canNavigateTo(state.index + 1)) {
        state.index++;
        renderQuestion();
      }
    } else if (action === 'submit') {
      const unanswered = state.questions.filter((q) => {
        const a = state.answers[q.id]?.answer;
        return answerIsEmpty(q, a);
      }).length;
      const msg = unanswered > 0
        ? 'You still have ' + unanswered + ' unanswered question' + (unanswered === 1 ? '' : 's') + '. Once submitted, you may not be able to change your answers.'
        : 'Once submitted, you may not be able to change your answers.';
      const ok = await askConfirm(msg, {
        title: unanswered > 0 ? 'Submit with unanswered questions?' : 'Submit quiz?',
        warning: unanswered > 0,
      });
      if (!ok) return;
      try {
        const activeQuestion = currentQuestion();
        if (activeQuestion) queueSave(activeQuestion);
        await flushQueue();
        state.isFinishing = true;
        const data = await api('submit', {});
        showResult(data);
      } catch (err) {
        state.isFinishing = false;
        alert(err.message || 'Submit failed');
      }
    } else if (action === 'view-result') {
      try {
        const data = await api('result', {});
        showResult({ player: data, attempt: data.attempt });
      } catch (err) {
        alert(err.message || 'Result unavailable');
      }
    }
  });

  window.addEventListener('online', () => {
    state.online = true;
    clearNetworkGrace();
    if (state.connectionExpired) {
      closeExpiredConnectionAttempt();
      return;
    }
    if (els.network) els.network.hidden = true;
    setSaveState('Back online');
    flushQueue();
  });
  window.addEventListener('offline', () => {
    state.online = false;
    startNetworkGrace();
    setSaveState('Offline — answers queued');
  });

  async function confirmAssessmentExit(destination) {
    const ok = await askConfirm(
      'Leaving this page will end and submit the assessment. You will not be able to return unless a teacher reopens the attempt.',
      {
        title: 'Leave and end assessment?',
        warning: true,
        confirmLabel: 'Leave and end attempt',
        cancelLabel: 'Stay in assessment',
      }
    );
    if (!ok) return false;
    state.exitConfirmed = true;
    if (destination) window.location.assign(destination);
    return true;
  }

  // Explain the consequence before an in-page link leaves an assessment.
  root.addEventListener('click', async (e) => {
    const link = e.target.closest('a[href]');
    if (!link || !assessmentIsActive()) return;
    e.preventDefault();
    await confirmAssessmentExit(link.href);
  }, true);

  // Browser Back uses the same branded panel. Browsers do not allow a custom
  // interface for refresh or tab close, so those actions quietly end the
  // one-sitting assessment via pagehide instead of showing Chrome's dialog.
  window.addEventListener('popstate', async () => {
    if (!assessmentIsActive() || state.exitConfirmed || !state.historyGuardArmed) return;
    const leave = await confirmAssessmentExit('');
    if (leave) {
      window.history.back();
      return;
    }
    window.history.pushState({ rieoAssessmentGuard: true }, '', window.location.href);
  });

  // Assessments are single-sitting. End the attempt when this document is
  // actually left (navigation, refresh, or tab/window close). Visibility/focus
  // changes remain integrity signals but do not themselves submit the work.
  window.addEventListener('pagehide', () => {
    if (state.mode !== 'assessment'
      || state.isFinishing
      || state.leaveSent
      || !state.attempt?.id
      || !state.token
      || (els.shell && els.shell.hidden)) {
      return;
    }
    state.leaveSent = true;
    const payload = JSON.stringify({
      action: 'leave_assessment',
      _token: csrf,
      id: activityId,
      token: state.token,
      attempt_id: state.attempt.id,
      end_reason: state.connectionExpired ? 'connection_lost' : 'page_left',
    });
    navigator.sendBeacon(
      'activity.php?id=' + activityId,
      new Blob([payload], { type: 'application/json' })
    );
  });

  // Keyboard
  document.addEventListener('keydown', (e) => {
    if (!els.shell || els.shell.hidden) return;
    if (els.confirm && !els.confirm.hidden) return;
    if (e.target.matches('input, textarea, select')) return;
    if (e.key === 'ArrowLeft') root.querySelector('[data-ap-action="prev"]')?.click();
    if (e.key === 'ArrowRight') root.querySelector('[data-ap-action="next"]')?.click();
  });

  if (boot.auto_start_player && boot.in_progress_attempt_id) {
    startOrResume(true);
  }
})();
