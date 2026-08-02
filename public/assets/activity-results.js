/**
 * Activity Results / marking client
 */
(function () {
  'use strict';

  const root = document.getElementById('activity-results');
  const bootEl = document.getElementById('ar-bootstrap');
  if (!root || !bootEl) return;

  let boot;
  try {
    boot = JSON.parse(bootEl.textContent || '{}');
  } catch (e) {
    console.error('Invalid results bootstrap');
    return;
  }

  const csrf = root.dataset.csrf || boot.csrf || '';
  const activityId = Number(boot.activity?.id || root.dataset.activityId || 0);

  const state = {
    detail: boot.detail || null,
    attempts: boot.attempts || [],
    summary: boot.summary || {},
  };

  const els = {
    list: root.querySelector('[data-ar-attempt-list]'),
    body: root.querySelector('[data-ar-detail-body]'),
    empty: root.querySelector('[data-ar-detail-empty]'),
    reopenPrompt: root.querySelector('[data-ar-reopen-prompt]'),
    reopenNote: root.querySelector('[data-ar-reopen-note]'),
    reopenConfirm: root.querySelector('[data-ar-reopen-confirm]'),
    reopenCancel: root.querySelector('[data-ar-reopen-cancel]'),
  };

  async function api(action, body, asForm) {
    if (action === 'export_csv') {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'activity-results.php?id=' + activityId;
      form.innerHTML =
        '<input type="hidden" name="_token" value="' + escapeAttr(csrf) + '">' +
        '<input type="hidden" name="action" value="export_csv">' +
        '<input type="hidden" name="id" value="' + activityId + '">';
      document.body.appendChild(form);
      form.submit();
      form.remove();
      return {};
    }

    const res = await fetch('activity-results.php?id=' + activityId, {
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
      }, body || {})),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid response' }));
    if (!res.ok || data.ok === false) {
      // Some errors (e.g. unmarked answers) still carry fresh detail.
      if (data && data.detail) state.detail = data.detail;
      throw new Error(data.error || 'Request failed');
    }
    return data;
  }

  function escapeAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtPoints(n) {
    const num = Number(n || 0);
    return Number.isInteger(num) ? String(num) : String(Math.round(num * 100) / 100);
  }

  function typeLabel(type) {
    const map = {
      single_choice: 'Multiple choice',
      multiple_choice: 'Select all that apply',
      true_false: 'True / false',
      short_text: 'Short answer',
      numeric: 'Numeric',
      long_response: 'Written response',
      fill_blank: 'Fill the blanks',
      ordering: 'Ordering',
      matching: 'Matching',
      rating_scale: 'Rating scale',
    };
    return map[type] || String(type || '').replace(/_/g, ' ');
  }

  function nl2br(text) {
    return escapeHtml(text).replace(/\r?\n/g, '<br>');
  }

  /* ── Rendering: a student's response ───────────────────────────────────── */

  function renderResponse(view) {
    const v = view || {};
    const kind = v.kind || 'empty';

    if (kind === 'empty') {
      return '<p class="ar-response ar-response--empty">No response given</p>';
    }

    if (kind === 'options') {
      let html = '<ul class="ar-options">';
      (v.items || []).forEach(item => {
        let cls = 'ar-option';
        let mark = '';
        if (item.chosen && item.correct) {
          cls += ' is-correct';
          mark = '<span class="ar-option-mark" aria-hidden="true">✓</span>';
        } else if (item.chosen && !item.correct) {
          cls += ' is-wrong';
          mark = '<span class="ar-option-mark" aria-hidden="true">✕</span>';
        } else if (item.correct) {
          cls += ' is-expected';
          mark = '<span class="ar-option-mark" aria-hidden="true">·</span>';
        } else {
          mark = '<span class="ar-option-mark" aria-hidden="true"></span>';
        }
        html += '<li class="' + cls + '">' + mark + '<span>' + escapeHtml(item.label) + '</span>';
        if (!item.chosen && item.correct) html += '<em>correct answer</em>';
        html += '</li>';
      });
      return html + '</ul>';
    }

    if (kind === 'rows') {
      let html = '<ul class="ar-rows">';
      (v.rows || []).forEach(row => {
        html += '<li class="ar-row' + (row.correct ? ' is-correct' : ' is-wrong') + '">' +
          '<span class="ar-row-label">' + escapeHtml(row.label) + '</span>' +
          '<span class="ar-row-given">' + (row.given ? escapeHtml(row.given) : '<em>blank</em>') + '</span>';
        if (!row.correct && row.expected) {
          html += '<span class="ar-row-expected">expected ' + escapeHtml(row.expected) + '</span>';
        }
        html += '</li>';
      });
      return html + '</ul>';
    }

    if (kind === 'value') {
      let html = '<p class="ar-response ar-response--value">' + escapeHtml(v.text || '');
      if (v.expected_text) html += ' <small>expected ' + escapeHtml(v.expected_text) + '</small>';
      return html + '</p>';
    }

    let html = '<blockquote class="ar-response ar-response--text">' + nl2br(v.text || '') + '</blockquote>';
    if (v.word_count) {
      html += '<p class="ar-response-meta">' + v.word_count + ' word' + (v.word_count === 1 ? '' : 's') + '</p>';
    }
    return html;
  }

  /* ── Rendering: the marking suggestion ─────────────────────────────────── */

  function renderSuggestion(suggestion) {
    const s = suggestion;
    if (!s) return '';

    const pct = s.similarity_percent;
    const bits = [];
    if (pct != null) bits.push(pct + '% match with your expected answer');
    if (s.keyword_coverage != null) {
      const hits = (s.keyword_hits || []).length;
      const total = hits + (s.keyword_misses || []).length;
      bits.push(hits + ' of ' + total + ' key points');
    }
    if (s.word_count != null) bits.push(s.word_count + ' words written');

    let html = '<div class="ar-suggest ar-suggest--' + escapeAttr(s.verdict) + '">';
    html += '<div class="ar-suggest-head">';
    html += '<span class="ar-suggest-verdict">' + escapeHtml(s.verdict_label || '') + '</span>';
    html += '<span class="ar-suggest-confidence">' + escapeHtml(String(s.confidence || '')) + ' confidence</span>';
    html += '</div>';

    if (pct != null) {
      html += '<div class="ar-meter" role="img" aria-label="' + pct + ' percent match">' +
        '<i style="width:' + Math.max(2, Math.min(100, pct)) + '%"></i></div>';
    }

    html += '<p class="ar-suggest-detail">' + escapeHtml(bits.join(' · ')) + '</p>';

    if ((s.keyword_hits || []).length || (s.keyword_misses || []).length) {
      html += '<p class="ar-keywords">';
      (s.keyword_hits || []).forEach(k => {
        html += '<span class="ar-keyword is-hit">' + escapeHtml(k.replace(/\|/g, ' / ')) + '</span>';
      });
      (s.keyword_misses || []).forEach(k => {
        html += '<span class="ar-keyword is-miss">' + escapeHtml(k.replace(/\|/g, ' / ')) + '</span>';
      });
      html += '</p>';
    }

    html += '<div class="ar-suggest-actions">';
    html += '<button type="button" class="ar-btn ar-btn--accept" data-ar-accept data-score="' +
      escapeAttr(s.suggested_score) + '">Accept ' + fmtPoints(s.suggested_score) + ' / ' + fmtPoints(s.points) + '</button>';
    html += '<span class="ar-suggest-note">A suggestion only — the mark is yours to set.</span>';
    html += '</div>';

    return html + '</div>';
  }

  /* ── Rendering: one answer card ────────────────────────────────────────── */

  function renderAnswerCard(q, ans, index, rubric) {
    const points = Number(q.points || 0);
    const isManual = Number(q.manual_marking) === 1;
    const awaiting = !!ans.awaiting_marking;
    const finalScore = ans.manual_score ?? ans.final_score ?? ans.auto_score;

    let cardCls = 'ar-answer-card';
    if (awaiting) cardCls += ' is-pending';
    else if (isManual) cardCls += ' is-marked';
    else if (ans.auto_correct === true) cardCls += ' is-auto-correct';
    else if (ans.auto_correct === false) cardCls += ' is-auto-wrong';

    let statePill;
    if (awaiting) {
      statePill = '<span class="ar-q-state ar-q-state--pending">Needs your mark</span>';
    } else if (isManual) {
      statePill = '<span class="ar-q-state ar-q-state--marked">Marked ' +
        fmtPoints(finalScore) + ' / ' + fmtPoints(points) + '</span>';
    } else {
      const cls = ans.auto_correct === true ? 'correct' : (ans.auto_correct === false ? 'wrong' : 'auto');
      statePill = '<span class="ar-q-state ar-q-state--' + cls + '">Auto-marked ' +
        fmtPoints(finalScore ?? 0) + ' / ' + fmtPoints(points) + '</span>';
    }

    let html = '<article class="' + cardCls + '" data-question-id="' + q.id + '">';
    html += '<header class="ar-answer-head">';
    html += '<span class="ar-q-index">Q' + (index + 1) + '</span>';
    html += '<span class="ar-q-type">' + escapeHtml(typeLabel(q.question_type)) +
      ' · ' + fmtPoints(points) + ' pts</span>';
    html += statePill;
    html += '</header>';

    if (q.prompt_html) {
      html += '<div class="ar-answer-prompt ap-rich">' + q.prompt_html + '</div>';
    }

    html += '<div class="ar-answer-response">' + renderResponse(ans.view) + '</div>';

    const expected = String(q.expected_answer || '');
    if (isManual && expected) {
      html += '<details class="ar-expected"' + (awaiting ? ' open' : '') + '>';
      html += '<summary>Expected answer</summary>';
      html += '<div class="ar-expected-body">' + nl2br(expected) + '</div>';
      html += '</details>';
    }

    if (isManual && ans.suggestion) {
      html += renderSuggestion(ans.suggestion);
    } else if (isManual && !expected) {
      html += '<p class="ar-suggest-hint">Add an expected answer to this question in the builder and the portal ' +
        'will suggest a mark here next time.</p>';
    }

    const markRow =
      '<div class="ar-mark-row">' +
      '<label class="ar-mark-score"><span>Mark</span>' +
      '<input type="number" min="0" max="' + points + '" step="0.5" data-ar-score inputmode="decimal" value="' +
      escapeAttr(ans.manual_score ?? (isManual ? '' : (ans.final_score ?? ''))) + '">' +
      '<small>of ' + fmtPoints(points) + '</small></label>' +
      '<div class="ar-quick">' +
      '<button type="button" class="ar-chip" data-ar-quick="' + points + '">Full</button>' +
      '<button type="button" class="ar-chip" data-ar-quick="' + (Math.round(points * 0.5 * 2) / 2) + '">Half</button>' +
      '<button type="button" class="ar-chip" data-ar-quick="0">Zero</button>' +
      '</div>' +
      '<label class="ar-mark-feedback"><span>Feedback for the student <small>optional</small></span>' +
      '<textarea rows="2" data-ar-feedback placeholder="Add a comment…">' +
      escapeHtml(ans.feedback_html || '') + '</textarea></label>' +
      '<button type="button" class="ar-btn ar-btn--primary" data-ar-save-mark>Save mark</button>' +
      '</div>';

    if (isManual) {
      html += markRow;
    } else {
      html += '<details class="ar-override"><summary>Override this mark</summary>' + markRow + '</details>';
    }

    if (rubric && Array.isArray(rubric.criteria) && rubric.criteria.length) {
      html += '<details class="ar-rubric"><summary>Rubric · ' + escapeHtml(rubric.title || '') + '</summary>';
      rubric.criteria.forEach(c => {
        html += '<div class="ar-rubric-criterion"><span>' + escapeHtml(c.title || '') +
          ' <small>max ' + fmtPoints(c.maximum_points || 0) + '</small></span>';
        if (Array.isArray(c.levels)) {
          html += '<select data-ar-rubric-level><option value="">Select level</option>';
          c.levels.forEach(l => {
            html += '<option value="' + Number(l.points || 0) + '">' + escapeHtml(l.title || '') +
              ' — ' + fmtPoints(l.points || 0) + '</option>';
          });
          html += '</select>';
        }
        html += '</div>';
      });
      html += '<button type="button" class="ar-btn" data-ar-apply-rubric>Total into mark</button>';
      html += '</details>';
    }

    return html + '</article>';
  }

  /* ── Rendering: whole detail pane ──────────────────────────────────────── */

  function renderDetail() {
    const d = state.detail;
    if (!d) {
      if (els.empty) els.empty.hidden = false;
      if (els.body) {
        els.body.hidden = true;
        els.body.innerHTML = '';
      }
      return;
    }
    if (els.empty) els.empty.hidden = true;
    if (!els.body) return;
    els.body.hidden = false;

    const a = d.attempt || {};
    const marking = d.marking || {};
    const pending = Number(marking.needs_marking || 0);
    const suggestions = Number(marking.suggestions_ready || 0);
    const isInProgress = String(a.status || '') === 'in_progress';
    const isMarked = ['marked', 'released'].includes(String(a.status || ''));
    const isReleased = String(a.status || '') === 'released';

    let html = '';
    html += '<header class="ar-detail-head">';
    html += '<span class="ar-avatar">' + escapeHtml(a.student_initials || '?') + '</span>';
    html += '<div class="ar-detail-who"><h2>' + escapeHtml(a.student_name || 'Student') + '</h2>';
    html += '<p>Attempt ' + Number(a.attempt_number || 0) + ' · ' +
      escapeHtml(String(a.status || '').replace(/_/g, ' ')) +
      (a.submitted_at ? ' · submitted ' + escapeHtml(String(a.submitted_at)) : '') + '</p></div>';
    html += '<div class="ar-detail-score">';
    html += a.percentage != null
      ? '<strong>' + Math.round(Number(a.percentage)) + '%</strong>'
      : '<strong class="is-pending">Pending</strong>';
    html += '<span>' + (a.score != null ? fmtPoints(a.score) : '—') + ' / ' +
      (a.maximum_score != null ? fmtPoints(a.maximum_score) : '—') + '</span>';
    html += '</div></header>';

    if (a.end_reason && a.end_reason !== 'submitted') {
      const endReasonIcon = a.end_reason === 'time_expired'
        ? '⏱'
        : (a.end_reason === 'page_left' ? '↗' : '!');
      html += '<section class="ar-end-reason ar-end-reason--' + escapeAttr(a.end_reason_class || 'neutral') + '" role="status">';
      html += '<span class="ar-end-reason-icon" aria-hidden="true">' + endReasonIcon + '</span><div>';
      html += '<strong>' + escapeHtml(a.end_reason_label || 'Attempt ended') + '</strong>';
      html += '<p>' + escapeHtml(a.end_reason_description || 'This attempt has ended.') + '</p></div>';
      if (a.can_reopen) {
        html += '<button type="button" class="ar-btn ar-btn--primary" data-ar-action="reopen-attempt">Reopen attempt</button>';
      }
      html += '</section>';
    } else if (isInProgress && Number(a.reopen_count || 0) > 0) {
      html += '<section class="ar-reopened-banner" role="status"><span aria-hidden="true">↻</span><div>';
      html += '<strong>Attempt reopened</strong><p>The student can return once and continue this assessment.';
      if (a.reopen_note) html += ' Note: ' + escapeHtml(a.reopen_note);
      html += '</p></div></section>';
    }

    const integrity = d.integrity_review || { level: 'clear', flagged_count: 0 };
    if (Number(integrity.flagged_count || 0) > 0) {
      html += '<section class="ar-integrity-alert ar-integrity-alert--' + escapeAttr(integrity.level || 'review') + '" role="alert">';
      html += '<span class="ar-integrity-alert-icon" aria-hidden="true">!</span>';
      html += '<div><strong>' + escapeHtml(integrity.label || 'Integrity review needed') + '</strong>';
      html += '<p>' + escapeHtml(integrity.message || 'Review the recorded signals before releasing this result.') + '</p></div>';
      html += '<button type="button" class="ar-btn ar-btn--integrity" data-ar-action="show-integrity">Review signals</button>';
      html += '</section>';
    }

    if (isInProgress) {
      html += '<div class="ar-mark-banner ar-mark-banner--reopened">';
      html += '<div class="ar-mark-banner-text"><strong>Waiting for the student</strong>';
      html += '<span>Marking and result release become available after they submit or the attempt ends again.</span></div></div>';
    } else if (pending > 0) {
      html += '<div class="ar-mark-banner ar-mark-banner--pending">';
      html += '<div class="ar-mark-banner-text">';
      html += '<strong>' + pending + (pending === 1 ? ' answer needs' : ' answers need') + ' your mark</strong>';
      html += '<span>Everything else is auto-marked. The grade stays pending until you finish.</span>';
      html += '</div><div class="ar-mark-banner-actions">';
      if (suggestions > 0) {
        html += '<button type="button" class="ar-btn ar-btn--primary" data-ar-action="apply-suggestions">' +
          'Fill ' + suggestions + ' suggested mark' + (suggestions === 1 ? '' : 's') + '</button>';
      }
      html += '<button type="button" class="ar-btn" data-ar-action="next-unmarked">Go to next</button>';
      html += '</div></div>';
    } else if (isMarked) {
      html += '<div class="ar-mark-banner ar-mark-banner--done">';
      html += '<div class="ar-mark-banner-text"><strong>' + (isReleased ? 'Result released' : 'Marking complete') + '</strong>';
      html += '<span>' + (isReleased
        ? 'The student can now see this result in Grades.'
        : 'The grade is finalised. Release it when you are ready for the student to see it.') + '</span></div>';
      if (!isReleased) {
        html += '<div class="ar-mark-banner-actions"><button type="button" class="ar-btn ar-btn--primary" data-ar-action="release-one">Release to student</button></div>';
      }
      html += '</div>';
    } else {
      html += '<div class="ar-mark-banner ar-mark-banner--done">';
      html += '<div class="ar-mark-banner-text"><strong>All answers marked</strong>';
      html += '<span>Complete marking to finalise, then release when you are ready.</span></div>';
      html += '<div class="ar-mark-banner-actions">';
      html += '<button type="button" class="ar-btn ar-btn--primary" data-ar-action="complete-marking">Complete marking</button>';
      html += '<button type="button" class="ar-btn" data-ar-action="release-one">Release to student</button>';
      html += '</div></div>';
    }

    html += '<section class="ar-answers">';
    (d.questions || []).forEach((q, i) => {
      const ans = (d.answers || {})[q.id] || (d.answers || {})[String(q.id)] || {};
      html += renderAnswerCard(q, ans, i, (d.rubrics || {})[q.id]);
    });
    html += '</section>';

    if (!isInProgress) {
      html += '<section class="ar-panel ar-overall"><h3>Overall feedback</h3>';
      html += '<textarea rows="3" data-ar-overall placeholder="Feedback on the whole attempt…">' +
        escapeHtml(a.overall_feedback_html || '') + '</textarea>';
      html += '<div class="ar-panel-actions">';
      if (isMarked) {
        html += '<button type="button" class="ar-btn ar-btn--primary" data-ar-action="save-feedback">Save feedback</button>';
      } else {
        html += '<button type="button" class="ar-btn ar-btn--primary" data-ar-action="complete-marking">Complete marking</button>';
      }
      if (!isReleased) html += '<button type="button" class="ar-btn" data-ar-action="release-one">Release to student</button>';
      html += '<button type="button" class="ar-btn ar-btn--danger" data-ar-action="invalidate">Invalidate attempt</button>';
      html += '<button type="button" class="ar-btn ar-btn--danger" data-ar-action="delete-attempt">Delete attempt</button>';
      html += '</div></section>';
    }

    const events = d.integrity_events || [];
    html += '<details class="ar-panel ar-integrity" data-ar-integrity-panel' +
      (Number(integrity.flagged_count || 0) > 0 ? ' open' : '') + '><summary>';
    html += '<span>Integrity checks</span><span class="ar-integrity-summary ar-integrity-summary--' +
      escapeAttr(integrity.level || 'clear') + '">' + escapeHtml(integrity.label || d.integrity_summary || 'No signals') + '</span></summary>';
    html += '<p class="ar-integrity-note">Signals are informational only and do not prove misconduct.</p>';
    if (events.length) {
      html += '<ol class="ar-integrity-list">';
      events.forEach(ev => {
        const meta = ev.metadata || {};
        const bits = [];
        if (meta.char_count != null) bits.push(meta.char_count + ' chars');
        if (meta.has_html) bits.push('html present');
        if (ev.source_classification && ev.source_classification !== 'source_not_available') {
          bits.push(String(ev.source_classification).replace(/_/g, ' '));
        }
        html += '<li class="ar-integrity-event ar-integrity-event--' + escapeAttr(ev.severity || 'review') + '">';
        html += '<span class="ar-integrity-event-level">' +
          escapeHtml(ev.severity === 'high' ? 'Review now' : (ev.severity === 'info' ? 'Info' : 'Review')) + '</span>';
        html += '<strong>' + escapeHtml(ev.label || String(ev.event_type || '').replace(/_/g, ' ')) + '</strong>';
        html += '<time>' + escapeHtml(ev.occurred_at || ev.received_at || '') + '</time>';
        if (bits.length) html += '<span>' + escapeHtml(bits.join(', ')) + '</span>';
        html += '</li>';
      });
      html += '</ol>';
    } else {
      html += '<p>No events recorded for this attempt.</p>';
    }
    html += '</details>';

    const acc = d.accommodation || {};
    html += '<details class="ar-panel ar-accommodation"><summary>Accommodations</summary>';
    html += '<div class="ar-acc-grid">';
    html += '<label><span>Extra time %</span><input type="number" data-ar-acc="extra_time_percent" value="' +
      escapeAttr(acc.extra_time_percent ?? 0) + '"></label>';
    html += '<label><span>Extra minutes</span><input type="number" data-ar-acc="extra_minutes" value="' +
      escapeAttr(acc.extra_minutes ?? 0) + '"></label>';
    html += '<label><span>Attempts override</span><input type="number" data-ar-acc="max_attempts_override" value="' +
      escapeAttr(acc.max_attempts_override ?? '') + '"></label>';
    html += '</div>';
    html += '<div class="ar-acc-checks">';
    html += '<label><input type="checkbox" data-ar-acc-bool="allow_paste"' +
      (Number(acc.allow_paste) ? ' checked' : '') + '><span>Allow paste</span></label>';
    html += '<label><input type="checkbox" data-ar-acc-bool="fullscreen_exempt"' +
      (Number(acc.fullscreen_exempt) ? ' checked' : '') + '><span>Fullscreen exempt</span></label>';
    html += '</div>';
    html += '<button type="button" class="ar-btn" data-ar-action="save-accommodation">Save accommodations</button>';
    html += '</details>';

    els.body.innerHTML = html;
    bindDetailEvents();
  }

  /** Re-render without losing the teacher's place on the page. */
  function rerenderKeepingPlace(focusNextUnmarked) {
    const scrollY = window.scrollY;
    const panel = root.querySelector('[data-ar-detail]');
    const panelTop = panel ? panel.scrollTop : 0;
    renderDetail();
    window.scrollTo({ top: scrollY, behavior: 'auto' });
    if (panel) panel.scrollTop = panelTop;
    if (focusNextUnmarked) goToNextUnmarked();
  }

  function goToNextUnmarked() {
    const card = els.body?.querySelector('.ar-answer-card.is-pending');
    if (!card) return false;
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.classList.add('is-focused');
    setTimeout(() => card.classList.remove('is-focused'), 1200);
    const input = card.querySelector('[data-ar-score]');
    if (input) setTimeout(() => input.focus(), 180);
    return true;
  }

  async function saveMark(card, opts) {
    const qid = Number(card?.dataset.questionId || 0);
    if (!qid || !state.detail?.attempt?.id) return;
    const scoreEl = card.querySelector('[data-ar-score]');
    const raw = opts && opts.score != null ? opts.score : scoreEl?.value;
    if (raw === '' || raw == null) {
      alert('Enter a mark first.');
      scoreEl?.focus();
      return;
    }
    const data = await api('mark_answer', {
      attempt_id: state.detail.attempt.id,
      question_id: qid,
      manual_score: raw,
      feedback_html: card.querySelector('[data-ar-feedback]')?.value || '',
    });
    state.detail = data.detail;
    rerenderKeepingPlace(!!(opts && opts.advance));
    refreshAttemptCard();
  }

  function bindDetailEvents() {
    els.body.querySelectorAll('[data-ar-save-mark]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await saveMark(btn.closest('[data-question-id]'), { advance: true });
        } catch (err) {
          alert(err.message);
        }
      });
    });

    els.body.querySelectorAll('[data-ar-accept]').forEach(btn => {
      btn.addEventListener('click', async () => {
        try {
          await saveMark(btn.closest('[data-question-id]'), {
            score: btn.getAttribute('data-score'),
            advance: true,
          });
        } catch (err) {
          alert(err.message);
        }
      });
    });

    els.body.querySelectorAll('[data-ar-quick]').forEach(btn => {
      btn.addEventListener('click', () => {
        const card = btn.closest('[data-question-id]');
        const input = card?.querySelector('[data-ar-score]');
        if (input) {
          input.value = btn.getAttribute('data-ar-quick') || '0';
          input.focus();
        }
      });
    });

    // Enter in the mark box saves and moves on.
    els.body.querySelectorAll('[data-ar-score]').forEach(input => {
      input.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        try {
          await saveMark(input.closest('[data-question-id]'), { advance: true });
        } catch (err) {
          alert(err.message);
        }
      });
    });

    els.body.querySelectorAll('[data-ar-apply-rubric]').forEach(btn => {
      btn.addEventListener('click', () => {
        const card = btn.closest('[data-question-id]');
        let total = 0;
        card.querySelectorAll('[data-ar-rubric-level]').forEach(sel => {
          if (sel.value !== '') total += Number(sel.value);
        });
        const scoreInput = card.querySelector('[data-ar-score]');
        if (scoreInput) {
          scoreInput.value = String(total);
          scoreInput.focus();
        }
      });
    });
  }

  const STATUS_META = {
    awaiting_manual_marking: { label: 'Needs marking', cls: 'warn' },
    marked: { label: 'Marked', cls: 'info' },
    released: { label: 'Released', cls: 'good' },
    in_progress: { label: 'In progress', cls: 'muted' },
    invalidated: { label: 'Invalidated', cls: 'bad' },
    auto_submitted: { label: 'Auto-submitted', cls: 'muted' },
    submitted: { label: 'Submitted', cls: 'info' },
  };

  function refreshAttemptCard() {
    const a = state.detail?.attempt;
    if (!a) return;
    const card = els.list?.querySelector('[data-ar-attempt="' + a.id + '"]');
    if (!card) return;
    const meta = STATUS_META[a.status] || { label: String(a.status || '').replace(/_/g, ' '), cls: 'muted' };
    const pill = card.querySelector('.ar-attempt-pill');
    if (pill) {
      pill.textContent = meta.label;
      pill.className = 'ar-attempt-pill ar-attempt-pill--' + meta.cls;
    }
    const num = card.querySelector('.ar-attempt-num');
    if (num) num.textContent = 'Attempt ' + a.attempt_number;
    const score = card.querySelector('.ar-attempt-score');
    if (score) score.textContent = a.percentage != null ? Math.round(Number(a.percentage)) + '%' : '—';
  }

  function selectedAttemptIds() {
    return Array.from(root.querySelectorAll('[data-ar-select-attempt]:checked'))
      .map((el) => Number(el.value))
      .filter((id) => id > 0);
  }

  function syncBulkBar() {
    const ids = selectedAttemptIds();
    const bar = root.querySelector('[data-ar-bulk-bar]');
    if (bar) bar.hidden = ids.length === 0;
    const btn = root.querySelector('[data-ar-action="delete-selected"]');
    if (btn) btn.disabled = ids.length === 0;
    const count = root.querySelector('[data-ar-bulk-count]');
    if (count) count.textContent = ids.length + (ids.length === 1 ? ' selected' : ' selected');
    const all = Array.from(root.querySelectorAll('[data-ar-select-attempt]'));
    const selectAll = root.querySelector('[data-ar-select-all]');
    if (selectAll && all.length) {
      selectAll.checked = ids.length > 0 && ids.length === all.length;
      selectAll.indeterminate = ids.length > 0 && ids.length < all.length;
    }
  }

  function syncListCount() {
    const count = root.querySelector('[data-ar-list-count]');
    if (!count) return;
    const n = root.querySelectorAll('[data-ar-attempt-row]').length;
    count.textContent = n + (n === 1 ? ' submission' : ' submissions');
  }

  function updateSummary(summary) {
    if (!summary) return;
    state.summary = summary;
    const stats = root.querySelectorAll('[data-ar-summary] .activity-results-stat strong');
    if (stats[0]) stats[0].textContent = String(summary.attempts ?? 0);
    if (stats[1]) stats[1].textContent = String(summary.students ?? 0);
    if (stats[2]) {
      stats[2].textContent = summary.avg_percentage != null ? (summary.avg_percentage + '%') : '—';
    }
    if (stats[3]) stats[3].textContent = String(summary.awaiting_marking ?? 0);
    if (stats[4]) stats[4].textContent = String(summary.integrity_flagged ?? 0);
    const integrityStat = root.querySelector('.activity-results-stat--integrity');
    if (integrityStat) integrityStat.classList.toggle('has-flags', Number(summary.integrity_flagged || 0) > 0);
  }

  function askToReopenAttempt() {
    return new Promise((resolve) => {
      const overlay = els.reopenPrompt;
      if (!overlay || !els.reopenConfirm || !els.reopenCancel || !els.reopenNote) {
        resolve(null);
        return;
      }

      const previouslyFocused = document.activeElement;
      els.reopenNote.value = '';
      overlay.hidden = false;
      requestAnimationFrame(() => {
        overlay.classList.add('ap-confirm--in');
        els.reopenNote.focus();
      });

      const finish = (approved) => {
        overlay.classList.remove('ap-confirm--in');
        els.reopenConfirm.removeEventListener('click', approve);
        els.reopenCancel.removeEventListener('click', cancel);
        overlay.removeEventListener('click', backdrop);
        document.removeEventListener('keydown', keydown);
        window.setTimeout(() => { overlay.hidden = true; }, 170);
        if (previouslyFocused instanceof HTMLElement) previouslyFocused.focus();
        resolve(approved ? els.reopenNote.value.trim() : null);
      };
      const approve = () => finish(true);
      const cancel = () => finish(false);
      const backdrop = (event) => {
        if (event.target === overlay) cancel();
      };
      const keydown = (event) => {
        if (event.key === 'Escape') cancel();
      };

      els.reopenConfirm.addEventListener('click', approve);
      els.reopenCancel.addEventListener('click', cancel);
      overlay.addEventListener('click', backdrop);
      document.addEventListener('keydown', keydown);
    });
  }

  async function deleteAttempts(ids) {
    const unique = Array.from(new Set(ids.map(Number).filter((id) => id > 0)));
    if (!unique.length) return;
    const label = unique.length === 1
      ? 'Delete this attempt permanently? The student can use the attempt slot again if limits allow.'
      : ('Delete ' + unique.length + ' attempts permanently? Students regain those attempt slots if limits allow.');
    if (!confirm(label)) return;

    const data = await api('delete_attempts', { attempt_ids: unique });
    const deleted = new Set((data.attempt_ids || unique).map(Number));
    state.attempts = (state.attempts || []).filter((row) => !deleted.has(Number(row.id)));

    deleted.forEach((id) => {
      root.querySelector('[data-ar-attempt-row="' + id + '"]')?.remove();
    });

    updateSummary(data.summary);
    syncBulkBar();
    syncListCount();

    const remaining = Array.from(els.list?.querySelectorAll('[data-ar-attempt]') || []);
    if (state.detail?.attempt?.id && deleted.has(Number(state.detail.attempt.id))) {
      if (remaining.length) {
        await loadAttempt(Number(remaining[0].dataset.arAttempt));
      } else {
        state.detail = null;
        if (els.body) {
          els.body.innerHTML = '';
          els.body.hidden = true;
        }
        if (els.empty) els.empty.hidden = false;
        if (els.list && !els.list.querySelector('[data-ar-attempt-row]')) {
          els.list.innerHTML =
            '<div class="ar-empty ar-empty--panel"><strong>No submissions yet</strong>' +
            '<p>When students take this activity, their attempts show up here for review and marking.</p></div>';
          root.querySelector('[data-ar-bulk-bar]')?.remove();
        }
      }
    }
  }

  async function loadAttempt(id) {
    try {
      const data = await api('load_attempt', { attempt_id: id });
      state.detail = data.detail;
      els.list?.querySelectorAll('.ar-attempt-card').forEach(c => {
        c.classList.toggle('is-selected', Number(c.dataset.arAttempt) === Number(id));
      });
      els.list?.querySelectorAll('[data-ar-attempt-row]').forEach(row => {
        row.classList.toggle('is-selected', Number(row.dataset.arAttemptRow) === Number(id));
      });
      renderDetail();
      history.replaceState(null, '', 'activity-results.php?id=' + activityId + '&attempt=' + id);
    } catch (err) {
      alert(err.message);
    }
  }

  els.list?.addEventListener('click', (e) => {
    const del = e.target.closest('[data-ar-delete-attempt]');
    if (del) {
      e.preventDefault();
      e.stopPropagation();
      deleteAttempts([Number(del.getAttribute('data-ar-delete-attempt'))]).catch((err) => {
        alert(err.message || 'Delete failed');
      });
      return;
    }
    if (e.target.closest('[data-ar-select-attempt], .ar-attempt-check')) {
      return;
    }
    const btn = e.target.closest('[data-ar-attempt]');
    if (!btn) return;
    loadAttempt(Number(btn.dataset.arAttempt));
  });

  root.addEventListener('change', (e) => {
    if (e.target.matches('[data-ar-select-all]')) {
      const on = !!e.target.checked;
      root.querySelectorAll('[data-ar-select-attempt]').forEach((el) => {
        el.checked = on;
      });
      syncBulkBar();
      return;
    }
    if (e.target.matches('[data-ar-select-attempt]')) {
      syncBulkBar();
    }
  });

  root.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ar-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-ar-action');
    try {
      if (action === 'export-csv') {
        await api('export_csv');
      } else if (action === 'release-all') {
        const flagged = Number(state.summary?.integrity_flagged || 0);
        const releaseMessage = flagged > 0
          ? ('Release all results? ' + flagged + ' attempt' + (flagged === 1 ? ' has' : 's have') +
            ' integrity flags. Review those attempts before continuing.')
          : 'Release results for all submitted attempts?';
        if (!confirm(releaseMessage)) return;
        await api('release_results', { all: true });
        location.reload();
      } else if (action === 'show-integrity') {
        const panel = root.querySelector('[data-ar-integrity-panel]');
        if (panel) {
          panel.open = true;
          panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      } else if (action === 'delete-selected') {
        await deleteAttempts(selectedAttemptIds());
      } else if (action === 'delete-attempt') {
        if (!state.detail?.attempt?.id) return;
        await deleteAttempts([state.detail.attempt.id]);
      } else if (action === 'reopen-attempt') {
        if (!state.detail?.attempt?.id) return;
        const note = await askToReopenAttempt();
        if (note === null) return;
        const data = await api('reopen_attempt', {
          attempt_id: state.detail.attempt.id,
          note,
        });
        state.detail = data.detail;
        renderDetail();
        refreshAttemptCard();
      } else if (action === 'next-unmarked') {
        if (!goToNextUnmarked()) alert('Nothing left to mark on this attempt.');
      } else if (action === 'apply-suggestions') {
        if (!state.detail?.attempt?.id) return;
        if (!confirm('Fill in the suggested mark for each unmarked written answer? You can still change any of them before releasing.')) return;
        const data = await api('apply_suggestions', { attempt_id: state.detail.attempt.id });
        state.detail = data.detail;
        rerenderKeepingPlace(false);
        refreshAttemptCard();
      } else if (action === 'release-one') {
        if (!state.detail?.attempt?.id) return;
        const flags = Number(state.detail?.integrity_review?.flagged_count || 0);
        if (flags > 0 && !confirm('This attempt has integrity flags. Release the result after reviewing the signal timeline?')) return;
        await api('release_results', { attempt_id: state.detail.attempt.id });
        const data = await api('load_attempt', { attempt_id: state.detail.attempt.id });
        state.detail = data.detail;
        renderDetail();
        refreshAttemptCard();
      } else if (action === 'complete-marking') {
        if (!state.detail?.attempt?.id) return;
        const overall = els.body.querySelector('[data-ar-overall]')?.value || '';
        const data = await api('complete_marking', {
          attempt_id: state.detail.attempt.id,
          overall_feedback_html: overall,
        });
        state.detail = data.detail;
        renderDetail();
        refreshAttemptCard();
      } else if (action === 'save-feedback') {
        if (!state.detail?.attempt?.id) return;
        const overall = els.body.querySelector('[data-ar-overall]')?.value || '';
        const data = await api('complete_marking', {
          attempt_id: state.detail.attempt.id,
          overall_feedback_html: overall,
        });
        state.detail = data.detail;
        const feedbackButton = els.body.querySelector('[data-ar-action="save-feedback"]');
        if (feedbackButton) {
          feedbackButton.textContent = 'Feedback saved';
          feedbackButton.classList.add('is-saved');
        }
        refreshAttemptCard();
      } else if (action === 'invalidate') {
        if (!state.detail?.attempt?.id) return;
        const reason = prompt('Optional note for invalidation (neutral language):', '') || '';
        const data = await api('invalidate_attempt', {
          attempt_id: state.detail.attempt.id,
          reason,
        });
        state.detail = data.detail;
        renderDetail();
        refreshAttemptCard();
      } else if (action === 'save-accommodation') {
        if (!state.detail?.attempt?.user_id) return;
        const fields = {};
        els.body.querySelectorAll('[data-ar-acc]').forEach(el => {
          fields[el.getAttribute('data-ar-acc')] = el.value;
        });
        els.body.querySelectorAll('[data-ar-acc-bool]').forEach(el => {
          fields[el.getAttribute('data-ar-acc-bool')] = el.checked ? 1 : 0;
        });
        await api('save_accommodation', {
          user_id: state.detail.attempt.user_id,
          fields,
        });
        btn.textContent = 'Saved';
        setTimeout(() => { btn.textContent = 'Save accommodations'; }, 1500);
      }
    } catch (err) {
      if (action === 'complete-marking' && state.detail) {
        renderDetail();
        goToNextUnmarked();
      }
      alert(err.message || 'Action failed');
    }
  });

  syncBulkBar();

  document.addEventListener('keydown', (e) => {
    if (!e.altKey) return;
    const cards = Array.from(els.list?.querySelectorAll('[data-ar-attempt]') || []);
    if (!cards.length || !state.detail?.attempt?.id) return;
    const idx = cards.findIndex(c => Number(c.dataset.arAttempt) === Number(state.detail.attempt.id));
    if (e.key === 'ArrowDown' && idx < cards.length - 1) {
      e.preventDefault();
      loadAttempt(Number(cards[idx + 1].dataset.arAttempt));
    }
    if (e.key === 'ArrowUp' && idx > 0) {
      e.preventDefault();
      loadAttempt(Number(cards[idx - 1].dataset.arAttempt));
    }
  });

  renderDetail();
})();
