/**
 * Flashcard study player — Quizlet-style flip deck.
 */
(function () {
  'use strict';

  const root = document.getElementById('activity-player');
  const bootEl = document.getElementById('ap-bootstrap');
  if (!root || !bootEl || root.dataset.mode !== 'flashcard') return;

  let boot;
  try {
    boot = JSON.parse(bootEl.textContent || '{}');
  } catch (e) {
    return;
  }

  const csrf = root.dataset.csrf || '';
  const activityId = Number(root.dataset.activityId || boot.activity?.id || 0);
  const isPreview = !!boot.preview;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    || document.documentElement.getAttribute('data-motion') === 'reduced';
  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;

  const SWIPE_THRESHOLD = 56;
  const TAP_SLOP = 10;

  const state = {
    attemptId: null,
    sessionToken: '',
    cards: [],
    order: [],
    index: 0,
    flipped: false,
    marks: {},
    known: 0,
    learning: 0,
    finished: false,
    marking: false,
  };

  const swipe = {
    active: false,
    pointerId: null,
    startX: 0,
    startY: 0,
    dx: 0,
    dy: 0,
    dragging: false,
    suppressClick: false,
  };

  const els = {
    landing: root.querySelector('[data-ap-landing]'),
    shell: root.querySelector('[data-ap-shell]'),
    card: root.querySelector('[data-fc-card]'),
    motion: root.querySelector('[data-fc-card-motion]'),
    inner: root.querySelector('[data-fc-card-inner]'),
    front: root.querySelector('[data-fc-front]'),
    back: root.querySelector('[data-fc-back]'),
    progressLabel: root.querySelector('[data-fc-progress-label]'),
    progressFill: root.querySelector('[data-fc-progress-fill]'),
    known: root.querySelector('[data-fc-known]'),
    learning: root.querySelector('[data-fc-learning]'),
    actions: root.querySelector('[data-fc-actions]'),
    end: root.querySelector('[data-fc-end]'),
    endSummary: root.querySelector('[data-fc-end-summary]'),
    shuffle: root.querySelector('[data-fc-shuffle]'),
    stage: root.querySelector('[data-fc-stage]') || root.querySelector('.fc-stage'),
    player: root.querySelector('[data-fc-player]'),
    keys: root.querySelector('[data-fc-keys]'),
  };

  function strip(html) {
    const d = document.createElement('div');
    d.innerHTML = html || '';
    return (d.textContent || '').trim();
  }

  function cardBack(q) {
    const settings = q.settings || {};
    if (typeof settings === 'string') {
      try {
        const parsed = JSON.parse(settings);
        return String(parsed.back || '');
      } catch (_) {
        return '';
      }
    }
    return String(settings.back || strip(q.explanation_html || '') || '');
  }

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
        attempt_id: state.attemptId,
        session_token: state.sessionToken,
        preview: isPreview ? 1 : 0,
      }, body || {})),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  function shuffle(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const t = a[i];
      a[i] = a[j];
      a[j] = t;
    }
    return a;
  }

  function currentCard() {
    const id = state.order[state.index];
    return state.cards.find((c) => Number(c.id) === Number(id)) || null;
  }

  function updateChrome() {
    const total = state.order.length || 1;
    const n = Math.min(state.index + 1, total);
    if (els.progressLabel) {
      els.progressLabel.textContent = state.finished
        ? 'Complete'
        : ('Card ' + n + ' of ' + total);
    }
    if (els.progressFill) {
      const pct = state.finished ? 100 : Math.round((state.index / total) * 100);
      els.progressFill.style.width = pct + '%';
    }
    if (els.known) els.known.textContent = String(state.known);
    if (els.learning) els.learning.textContent = String(state.learning);
  }

  function hintCopy() {
    if (!state.flipped) {
      return 'Click or press Space to flip';
    }
    return coarsePointer
      ? 'Swipe right Know · left Still learning'
      : 'Swipe / drag right Know · left Still learning';
  }

  function setFlipped(on) {
    state.flipped = !!on;
    els.card?.classList.toggle('is-flipped', state.flipped);
    if (reducedMotion) {
      els.inner?.classList.add('fc-no-motion');
    }
    const markBtns = root.querySelectorAll('[data-fc-mark]');
    markBtns.forEach((btn) => {
      btn.disabled = !state.flipped || state.finished || state.marking;
    });
    if (els.keys) {
      els.keys.hidden = !state.flipped || state.finished;
      els.keys.textContent = hintCopy();
    }
    root.querySelectorAll('.fc-face-hint').forEach((el) => {
      el.textContent = hintCopy();
    });
  }

  function resetSwipeVisual() {
    if (!els.card) return;
    els.card.classList.remove('is-dragging', 'is-swipe-known', 'is-swipe-learning', 'is-swipe-out', 'is-swipe-snap');
    els.motion?.classList.remove('is-dragging', 'is-swipe-out', 'is-swipe-snap');
    if (els.motion) {
      els.motion.style.transform = '';
      els.motion.style.opacity = '';
    }
    swipe.dx = 0;
    swipe.dy = 0;
    swipe.dragging = false;
  }

  function applySwipeVisual(dx) {
    if (!els.motion || !state.flipped) return;
    const rot = Math.max(-10, Math.min(10, dx * 0.04));
    els.motion.style.transform = 'translateX(' + dx + 'px) rotate(' + rot + 'deg)';
    els.card.classList.toggle('is-swipe-known', dx > 28);
    els.card.classList.toggle('is-swipe-learning', dx < -28);
  }

  function stripStemImages(html) {
    const box = document.createElement('div');
    box.innerHTML = html || '';
    box.querySelectorAll('img').forEach((el) => el.remove());
    return box.innerHTML;
  }

  function renderCard() {
    const q = currentCard();
    if (!q) {
      finishDeck();
      return;
    }
    resetSwipeVisual();
    state.marking = false;
    if (els.front) {
      let front = stripStemImages(q.prompt_html || '') || ('<p>' + escapeHtml(strip(q.prompt_html)) + '</p>');
      if (Array.isArray(q.media) && q.media.length) {
        front += '<div class="ap-q-media" data-count="' + q.media.length + '">' + q.media.map((m) => {
          const url = escapeHtml(m.url || ('activity-media.php?id=' + m.id));
          const type = String(m.media_type || 'image');
          if (type === 'audio') return '<audio class="ap-media-audio" controls preload="metadata" src="' + url + '"></audio>';
          if (type === 'video') return '<video class="ap-media-video" controls preload="metadata" src="' + url + '"></video>';
          return '<figure class="ap-media-figure"><img class="ap-media-image" src="' + url + '" alt=""></figure>';
        }).join('') + '</div>';
      }
      els.front.innerHTML = front;
    }
    const back = cardBack(q);
    if (els.back) {
      els.back.innerHTML = back
        ? (back.indexOf('<') >= 0 ? back : ('<p>' + escapeHtml(back).replace(/\n/g, '<br>') + '</p>'))
        : '<p><em>No back text</em></p>';
    }
    setFlipped(false);
    updateChrome();
    if (els.stage) els.stage.hidden = false;
    if (els.end) els.end.hidden = true;
    if (els.player) els.player.classList.remove('is-complete');
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  async function mark(value) {
    const q = currentCard();
    if (!q || !state.flipped || state.marking || state.finished) return;
    state.marking = true;
    state.marks[q.id] = value;
    if (value === 'known') state.known += 1;
    else state.learning += 1;

    try {
      await api('save_answer', {
        question_id: q.id,
        answer: { value },
      });
    } catch (_) {
      // Keep studying offline-friendly; marks still advance locally.
    }

    if (state.index >= state.order.length - 1) {
      finishDeck();
      return;
    }
    state.index += 1;
    renderCard();
  }

  function commitSwipe(value) {
    if (!els.card || !els.motion || state.marking) return;
    const dir = value === 'known' ? 1 : -1;
    swipe.suppressClick = true;
    els.card.classList.add('is-swipe-out');
    els.motion.classList.add('is-swipe-out');
    els.card.classList.toggle('is-swipe-known', value === 'known');
    els.card.classList.toggle('is-swipe-learning', value === 'learning');
    if (reducedMotion) {
      resetSwipeVisual();
      mark(value);
      return;
    }
    els.motion.style.transform = 'translateX(' + (dir * Math.max(window.innerWidth, 480)) + 'px) rotate(' + (dir * 14) + 'deg)';
    els.motion.style.opacity = '0';
    window.setTimeout(() => {
      resetSwipeVisual();
      mark(value);
    }, 160);
  }

  async function finishDeck() {
    state.finished = true;
    state.marking = false;
    resetSwipeVisual();
    updateChrome();
    if (els.stage) els.stage.hidden = true;
    if (els.end) els.end.hidden = false;
    if (els.player) els.player.classList.add('is-complete');
    if (els.endSummary) {
      els.endSummary.textContent = 'You marked '
        + state.known + ' as Know and '
        + state.learning + ' as Still learning.';
    }
    try {
      await api('submit', {});
    } catch (_) { /* optional */ }
  }

  function rebuildOrder(onlyLearning) {
    let ids = state.cards.map((c) => Number(c.id));
    if (onlyLearning) {
      ids = ids.filter((id) => state.marks[id] === 'learning' || !state.marks[id]);
      if (ids.length === 0) ids = state.cards.map((c) => Number(c.id));
    }
    if (els.shuffle?.checked) ids = shuffle(ids);
    state.order = ids;
    state.index = 0;
    state.flipped = false;
    state.known = 0;
    state.learning = 0;
    state.marks = {};
    state.finished = false;
  }

  async function startOrResume(resume) {
    const data = await api(resume ? 'resume' : 'start', {});
    state.attemptId = data.attempt?.id || data.attempt_id || null;
    state.sessionToken = data.token || data.session_token || '';
    state.cards = data.questions || [];
    if (state.cards.length === 0) {
      throw new Error('This deck has no cards yet.');
    }
    if (Array.isArray(data.attempt?.question_order) && data.attempt.question_order.length) {
      state.order = data.attempt.question_order.map(Number);
    } else {
      rebuildOrder(false);
    }
    if (els.shuffle?.checked && !(data.resumed)) {
      state.order = shuffle(state.order.length ? state.order : state.cards.map((c) => Number(c.id)));
    } else if (!state.order.length) {
      rebuildOrder(false);
    }
    state.index = 0;
    state.flipped = false;
    state.known = 0;
    state.learning = 0;
    state.marks = {};
    state.finished = false;
    if (els.landing) els.landing.hidden = true;
    if (els.shell) els.shell.hidden = false;
    renderCard();
  }

  function bindDocListeners(on) {
    const method = on ? 'addEventListener' : 'removeEventListener';
    document[method]('pointermove', onPointerMove, { passive: false });
    document[method]('pointerup', onPointerUp);
    document[method]('pointercancel', onPointerCancel);
  }

  function onPointerDown(e) {
    if (!els.card || state.finished || state.marking) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    swipe.active = true;
    swipe.pointerId = e.pointerId;
    swipe.startX = e.clientX;
    swipe.startY = e.clientY;
    swipe.dx = 0;
    swipe.dy = 0;
    swipe.dragging = false;
    bindDocListeners(true);
    try {
      els.card.setPointerCapture(e.pointerId);
    } catch (_) { /* ignore */ }
  }

  function onPointerMove(e) {
    if (!swipe.active || e.pointerId !== swipe.pointerId) return;
    swipe.dx = e.clientX - swipe.startX;
    swipe.dy = e.clientY - swipe.startY;
    const absX = Math.abs(swipe.dx);
    const absY = Math.abs(swipe.dy);

    if (!swipe.dragging && absX > TAP_SLOP && absX >= absY) {
      swipe.dragging = true;
      els.card?.classList.add('is-dragging');
      els.motion?.classList.add('is-dragging');
    }

    if (swipe.dragging) {
      // Marking swipes only after the card is flipped.
      if (state.flipped) {
        applySwipeVisual(swipe.dx);
        e.preventDefault();
      }
    }
  }

  function endPointer(e) {
    if (!swipe.active || (e && e.pointerId !== swipe.pointerId)) return;
    const dx = swipe.dx;
    const dy = swipe.dy;
    const wasDragging = swipe.dragging;
    swipe.active = false;
    swipe.pointerId = null;
    bindDocListeners(false);

    if (wasDragging && state.flipped && Math.abs(dx) >= SWIPE_THRESHOLD && Math.abs(dx) >= Math.abs(dy)) {
      commitSwipe(dx > 0 ? 'known' : 'learning');
      return;
    }

    if (wasDragging) {
      swipe.suppressClick = true;
      if (!reducedMotion && els.motion) {
        els.motion.classList.add('is-swipe-snap');
        window.setTimeout(() => els.motion?.classList.remove('is-swipe-snap'), 200);
      }
      resetSwipeVisual();
    }
  }

  function onPointerUp(e) {
    endPointer(e);
  }

  function onPointerCancel(e) {
    swipe.suppressClick = true;
    endPointer(e);
    resetSwipeVisual();
  }

  if (els.card) {
    els.card.addEventListener('pointerdown', onPointerDown);
  }

  root.addEventListener('click', async (e) => {
    const actionBtn = e.target.closest('[data-ap-action]');
    if (actionBtn) {
      const action = actionBtn.getAttribute('data-ap-action');
      try {
        if (action === 'start') await startOrResume(false);
        else if (action === 'resume') await startOrResume(true);
        else if (action === 'view-result') await startOrResume(false);
      } catch (err) {
        alert(err.message || 'Could not start studying');
      }
      return;
    }

    if (e.target.closest('[data-fc-card]') && !state.finished) {
      if (swipe.suppressClick) {
        swipe.suppressClick = false;
        return;
      }
      setFlipped(!state.flipped);
      return;
    }

    const markBtn = e.target.closest('[data-fc-mark]');
    if (markBtn) {
      mark(markBtn.getAttribute('data-fc-mark'));
      return;
    }

    if (e.target.closest('[data-fc-restart]')) {
      rebuildOrder(false);
      renderCard();
      return;
    }
    if (e.target.closest('[data-fc-restart-learning]')) {
      rebuildOrder(true);
      renderCard();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (els.shell?.hidden || state.finished || state.marking) return;
    if (e.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
    if (e.code === 'Space' || e.key === 'Enter') {
      if (e.target && e.target.closest && e.target.closest('[data-fc-mark]')) return;
      e.preventDefault();
      setFlipped(!state.flipped);
    } else if (e.key === 'ArrowRight' && state.flipped) {
      mark('known');
    } else if (e.key === 'ArrowLeft' && state.flipped) {
      mark('learning');
    }
  });
})();
