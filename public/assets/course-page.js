(function () {
    // External link safety warning (all users).
    const externalLinkModal = document.getElementById('external-link-warning');
    const externalLinkMessage = externalLinkModal?.querySelector('[data-external-link-message]');
    const externalLinkUrl = externalLinkModal?.querySelector('[data-external-link-url]');
    const externalLinkVerdict = externalLinkModal?.querySelector('[data-external-link-verdict]');
    const externalLinkContinue = externalLinkModal?.querySelector('[data-external-link-continue]');
    const externalLinkCancel = externalLinkModal?.querySelector('[data-external-link-cancel]');
    const externalLinkClose = externalLinkModal?.querySelector('.external-link-close');
    let pendingExternalUrl = '';

    function closeExternalLinkModal() {
        if (!externalLinkModal) return;
        externalLinkModal.classList.remove('external-link-overlay--in');
        window.setTimeout(() => {
            externalLinkModal.hidden = true;
            pendingExternalUrl = '';
        }, 160);
    }

    function openExternalLinkModal(url) {
        if (!externalLinkModal) return;
        pendingExternalUrl = url;
        externalLinkModal.hidden = false;
        externalLinkModal.classList.add('external-link-overlay--in');
        if (externalLinkMessage) {
            externalLinkMessage.textContent = 'You are about to visit an external source. External websites may expose you to unsafe content, tracking, or downloads.';
        }
        if (externalLinkUrl) externalLinkUrl.textContent = url;
        if (externalLinkVerdict) {
            externalLinkVerdict.className = 'external-link-verdict external-link-verdict--checking';
            externalLinkVerdict.textContent = 'Checking this URL with Google Safe Browsing...';
        }
        if (externalLinkContinue) {
            externalLinkContinue.disabled = false;
            externalLinkContinue.textContent = 'Continue';
        }
    }

    function applyExternalLinkVerdict(verdict) {
        if (!externalLinkVerdict || !externalLinkContinue) return;
        const status = verdict?.status || 'unchecked';
        const stats = verdict?.stats || null;
        const statText = stats
            ? ' Malicious: ' + (stats.malicious || 0) + ', suspicious: ' + (stats.suspicious || 0) + ', harmless: ' + (stats.harmless || 0) + '.'
            : '';
        externalLinkVerdict.className = 'external-link-verdict external-link-verdict--' + status;
        externalLinkVerdict.textContent = (verdict?.message || 'This link could not be verified automatically.') + statText;
        externalLinkContinue.disabled = status === 'invalid';
        externalLinkContinue.textContent = (status === 'malicious' || status === 'suspicious') ? 'Continue anyway' : 'Continue';
    }

    async function checkExternalLink(url) {
        const pd = document.getElementById('portal-page-data');
        const token = pd?.dataset.csrf || '';
        const slug = pd?.dataset.slug || new URLSearchParams(location.search).get('course') || '';
        const body = new URLSearchParams({ _token: token, action: 'check_external_link', url });
        const res = await fetch('course.php?course=' + encodeURIComponent(slug), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
            body: body.toString(),
        });
        return res.json();
    }

    document.addEventListener('click', e => {
        const trigger = e.target.closest('a[data-safe-external-link], .folder-item--external-link[data-safe-external-link]');
        if (!trigger || !externalLinkModal) return;
        const interactive = e.target.closest('button, input, select, textarea, summary, label, .item-drag-handle, .settings-panel');
        if (interactive) return;
        const url = trigger.dataset.safeUrl || trigger.href || trigger.getAttribute('href') || '';
        if (!url) return;
        e.preventDefault();
        openExternalLinkModal(url);
        checkExternalLink(url)
            .then(data => {
                if (!data.ok) {
                    applyExternalLinkVerdict({ status: 'unchecked', message: data.error || 'This link could not be verified automatically.' });
                    return;
                }
                if (data.url) {
                    pendingExternalUrl = data.url;
                    if (externalLinkUrl) externalLinkUrl.textContent = data.url;
                }
                applyExternalLinkVerdict(data.verdict || null);
            })
            .catch(() => {
                applyExternalLinkVerdict({ status: 'unchecked', message: 'This link could not be verified automatically. Treat it with caution.' });
            });
    });

    document.addEventListener('keydown', e => {
        const trigger = e.target.closest('[data-safe-external-link][role="link"]');
        if (!trigger || (e.key !== 'Enter' && e.key !== ' ')) return;
        e.preventDefault();
        trigger.click();
    });

    externalLinkContinue?.addEventListener('click', () => {
        if (!pendingExternalUrl) return;
        const url = pendingExternalUrl;
        closeExternalLinkModal();
        window.open(url, '_blank', 'noopener,noreferrer');
    });
    externalLinkCancel?.addEventListener('click', closeExternalLinkModal);
    externalLinkClose?.addEventListener('click', closeExternalLinkModal);
    externalLinkModal?.addEventListener('click', e => {
        if (e.target === externalLinkModal) closeExternalLinkModal();
    });
    // ── Document viewer overlay (same-tab, smooth) ──────────────────────────
    // Clicking a course document fades in a full-viewport lightbox (mirrors the
    // assignment-review dialog's open/close transition) with the redesigned
    // view.php loaded inside an iframe. Plain <a href> semantics are preserved
    // so ctrl/cmd/middle-click and "open in new tab" keep working natively.
    const docViewerOverlay = document.getElementById('doc-viewer-overlay');
    const docViewerFrame   = document.getElementById('doc-viewer-frame');
    const docViewerTitle   = document.getElementById('doc-viewer-title');
    const docViewerMeta    = document.getElementById('doc-viewer-meta');
    let docViewerLastFocus = null;

    function anyPortalOverlayOpen() {
        return !!document.querySelector('.rvw-overlay:not([hidden]), .sub-slot-overlay:not([hidden]), .docviewer-overlay:not([hidden])');
    }

    function focusDocViewerFrame() {
        if (!docViewerFrame || docViewerOverlay?.hidden) return;
        try { docViewerFrame.focus({ preventScroll: true }); } catch (_) {
            try { docViewerFrame.focus(); } catch (__) {}
        }
        try { docViewerFrame.contentWindow?.focus(); } catch (_) {}
    }

    function openDocViewer(url, link) {
        if (!docViewerOverlay || !docViewerFrame) { window.location.href = url; return; }
        docViewerLastFocus = document.activeElement;
        const extension = link?.querySelector('.file-ext-badge')?.textContent?.trim() || '';
        const title = Array.from(link?.childNodes || [])
            .filter(node => node.nodeType === Node.TEXT_NODE)
            .map(node => node.textContent.trim())
            .filter(Boolean)
            .join(' ') || link?.textContent?.replace(extension, '').trim() || 'Document viewer';
        if (docViewerTitle) docViewerTitle.textContent = title;
        if (docViewerMeta) docViewerMeta.textContent = extension ? extension + ' document' : '';
        try {
            const next = new URL(url, window.location.href);
            next.searchParams.set('embed', '1');
            docViewerFrame.src = next.pathname + next.search + next.hash;
        } catch (_) {
            docViewerFrame.src = url + (url.includes('?') ? '&' : '?') + 'embed=1';
        }
        docViewerOverlay.hidden = false;
        document.body.classList.add('sub-slot-body-lock');
        requestAnimationFrame(() => {
            docViewerOverlay.classList.add('docviewer-overlay--in');
            // Focus as soon as the dialog is visible so the next key/wheel
            // gesture goes to the viewer (not the course page underneath).
            focusDocViewerFrame();
        });
    }

    docViewerFrame?.addEventListener('load', () => {
        // Re-focus after the document finishes loading — setting src can
        // steal focus back to the parent during navigation.
        if (docViewerOverlay && !docViewerOverlay.hidden) {
            requestAnimationFrame(focusDocViewerFrame);
        }
    });

    function closeDocViewer() {
        if (!docViewerOverlay || docViewerOverlay.hidden) return;
        docViewerOverlay.classList.remove('docviewer-overlay--in');
        setTimeout(() => {
            docViewerOverlay.hidden = true;
            docViewerFrame.src = 'about:blank';
            if (!anyPortalOverlayOpen()) {
                document.body.classList.remove('sub-slot-body-lock');
            }
            if (docViewerLastFocus && typeof docViewerLastFocus.focus === 'function') docViewerLastFocus.focus();
        }, 220);
    }

    document.addEventListener('click', e => {
        const link = e.target.closest('.file-view-link[data-doc-viewer]');
        if (!link) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        openDocViewer(link.href, link);
    });
    document.getElementById('doc-viewer-close')?.addEventListener('click', closeDocViewer);
    docViewerOverlay?.addEventListener('click', e => { if (e.target === docViewerOverlay) closeDocViewer(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && docViewerOverlay && !docViewerOverlay.hidden) closeDocViewer();
    });
    window.addEventListener('message', e => {
        if (e.origin !== window.location.origin) return;
        if (e.data && e.data.type === 'portal-doc-viewer-close') closeDocViewer();
    });

    // ── Shared client-side file renderers (used by the assignment review dialog) ─
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function sanitizeDocHtml(html) {
        const raw = String(html || '');
        if (!raw.trim()) return '';
        if (window.DOMPurify && typeof DOMPurify.sanitize === 'function') {
            return DOMPurify.sanitize(raw, {
                USE_PROFILES: { html: true },
                FORBID_TAGS: ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'link', 'meta', 'base'],
                ALLOW_DATA_ATTR: false,
            });
        }
        // Fail closed if the sanitizer CDN is blocked: never inject raw HTML.
        return '<p>' + escapeHtml(raw.replace(/<[^>]*>/g, ' ')) + '</p>';
    }

    // Split mammoth HTML into Letter-sized sheets (still one DOM tree so text
    // annotations keep working). Packs whole blocks; oversized blocks stay on
    // their own sheet rather than clipping mid-paragraph.
    function paginateReviewDocx(mount, html) {
        const PAGE_H = 1056;
        const empty = '<p class="rvw-doc-empty-msg">This document appears to be empty.</p>';
        const source = document.createElement('div');
        source.innerHTML = (html && String(html).trim()) ? html : empty;

        mount.innerHTML = '';
        mount.classList.add('rvw-docx-pages');

        function makePage(num) {
            const page = document.createElement('article');
            page.className = 'rvw-docx-page';
            page.dataset.page = String(num);
            const body = document.createElement('div');
            body.className = 'rvw-docx-page-body';
            const footer = document.createElement('div');
            footer.className = 'rvw-docx-page-num';
            footer.setAttribute('aria-hidden', 'true');
            footer.textContent = String(num);
            page.appendChild(body);
            page.appendChild(footer);
            return { page, body, footer };
        }

        let pageNum = 1;
        let current = makePage(pageNum);
        // Measure with unconstrained height while packing.
        current.page.style.height = 'auto';
        current.page.style.minHeight = '0';
        current.page.style.overflow = 'visible';
        mount.appendChild(current.page);

        function finalizePage(el) {
            el.style.height = '';
            el.style.minHeight = '';
            el.style.overflow = '';
            // A single oversized block (image/table) should grow the sheet rather than clip.
            if (el.scrollHeight > PAGE_H + 2) {
                el.style.height = 'auto';
                el.style.minHeight = PAGE_H + 'px';
                el.style.overflow = 'visible';
            }
        }

        const nodes = Array.from(source.childNodes);
        if (!nodes.length) {
            current.body.innerHTML = empty;
        }

        nodes.forEach(node => {
            current.body.appendChild(node);
            if (current.page.scrollHeight > PAGE_H && current.body.childNodes.length > 1) {
                current.body.removeChild(node);
                finalizePage(current.page);
                pageNum += 1;
                current = makePage(pageNum);
                current.page.style.height = 'auto';
                current.page.style.minHeight = '0';
                current.page.style.overflow = 'visible';
                mount.appendChild(current.page);
                current.body.appendChild(node);
            }
        });

        finalizePage(current.page);

        Array.from(mount.querySelectorAll('.rvw-docx-page')).forEach((el, i) => {
            const num = el.querySelector('.rvw-docx-page-num');
            if (num) num.textContent = String(i + 1);
            el.dataset.page = String(i + 1);
        });

        return mount.querySelectorAll('.rvw-docx-page').length;
    }

    function wireReviewPageNav(shell, opts) {
        if (!shell) return;
        const preservePage = !!(opts && opts.preservePage);
        const nav = shell.querySelector('[data-rvw-pagenav]');
        const scrollEl = shell.querySelector('.rvw-docx-scroll');
        if (!nav) return;

        const previousPage = (shell._rvwPageNav && shell._rvwPageNav.current) || 1;

        if (shell._rvwPageObserver) {
            shell._rvwPageObserver.disconnect();
            shell._rvwPageObserver = null;
        }

        function getPages() {
            return scrollEl ? Array.from(scrollEl.querySelectorAll('.rvw-docx-page')) : [];
        }

        const pages = getPages();
        if (!scrollEl || pages.length <= 1) {
            nav.hidden = true;
            shell._rvwPageNav = null;
            return;
        }

        const prevBtn = nav.querySelector('[data-rvw-page-prev]');
        const nextBtn = nav.querySelector('[data-rvw-page-next]');
        const input = nav.querySelector('[data-rvw-page-input]');
        const totalEl = nav.querySelector('[data-rvw-page-total]');
        let currentPage = 1;
        const pageCount = pages.length;

        nav.hidden = false;
        if (totalEl) totalEl.textContent = String(pageCount);
        if (input) {
            input.setAttribute('min', '1');
            input.setAttribute('max', String(pageCount));
            input.setAttribute('inputmode', 'numeric');
        }

        function setCurrentPage(n, syncInput) {
            if (!n || n < 1 || n > pageCount) return;
            currentPage = n;
            if (input && (syncInput || document.activeElement !== input)) {
                input.value = String(currentPage);
            }
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= pageCount;
        }

        function scrollToPage(n) {
            const livePages = getPages();
            const count = livePages.length || pageCount;
            if (!count) return;
            const target = Math.max(1, Math.min(count, Math.floor(Number(n)) || 1));
            const el = livePages[target - 1];
            if (el && scrollEl) {
                const pad = 8;
                const delta = el.getBoundingClientRect().top - scrollEl.getBoundingClientRect().top;
                const nextTop = Math.max(0, scrollEl.scrollTop + delta - pad);
                scrollEl.scrollTo({ top: nextTop, behavior: 'smooth' });
            }
            setCurrentPage(target, true);
        }

        function commitInputFromApi() {
            const api = shell._rvwPageNav;
            if (!api || !input) return;
            const raw = String(input.value || '').trim();
            const n = parseInt(raw, 10);
            if (raw === '' || isNaN(n) || n < 1 || n > api.pageCount) {
                input.value = String(api.current);
                return;
            }
            api.scrollToPage(n);
        }

        shell._rvwPageNav = {
            scrollToPage,
            pageCount,
            get current() { return currentPage; },
        };

        if (!nav.dataset.wired) {
            nav.dataset.wired = '1';
            prevBtn?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const api = shell._rvwPageNav;
                if (api) api.scrollToPage(api.current - 1);
            });
            nextBtn?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const api = shell._rvwPageNav;
                if (api) api.scrollToPage(api.current + 1);
            });
            input?.addEventListener('change', commitInputFromApi);
            input?.addEventListener('blur', commitInputFromApi);
            input?.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    commitInputFromApi();
                    input.blur();
                }
            });
            input?.addEventListener('input', () => {
                const cleaned = String(input.value || '').replace(/[^\d]/g, '');
                if (cleaned !== input.value) input.value = cleaned;
            });
        }

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && entry.intersectionRatio > 0.35) {
                        const live = getPages();
                        const idx = live.indexOf(entry.target);
                        if (idx >= 0) setCurrentPage(idx + 1, false);
                    }
                });
            }, { root: scrollEl, threshold: [0.35] });
            pages.forEach(el => observer.observe(el));
            shell._rvwPageObserver = observer;
        }

        setCurrentPage(preservePage ? Math.min(Math.max(1, previousPage), pageCount) : 1, true);
    }

    function renderSheetToTable(sheet) {
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
        if (!rows.length) {
            return '<p class="rvw-doc-error">This workbook sheet is empty.</p>';
        }

        const maxCols = rows.reduce((max, row) => Math.max(max, Array.isArray(row) ? row.length : 0), 0);
        const header = '<tr>'
            + Array.from({ length: maxCols }, (_, i) => '<th>Col ' + (i + 1) + '</th>').join('')
            + '</tr>';

        const body = rows.map(row => {
            const cells = Array.from({ length: maxCols }, (_, i) => '<td>' + escapeHtml(row[i] ?? '') + '</td>').join('');
            return '<tr>' + cells + '</tr>';
        }).join('');

        return '<div class="xlsx-wrap"><table class="xlsx-table"><thead>' + header + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    // ── Tab settings toggle ───────────────────────────────────────────────────
    const settingsBtn   = document.getElementById('tab-settings-btn');
    const settingsPanel = document.getElementById('tab-settings-panel');
    if (settingsBtn && settingsPanel) {
        settingsBtn.addEventListener('click', () => {
            const open = !settingsPanel.hidden;
            settingsPanel.hidden = open;
            settingsBtn.setAttribute('aria-expanded', String(!open));
            settingsBtn.classList.toggle('course-tab--active', !open);
        });
    }

    // ── Item type → show/hide fields ─────────────────────────────────────────
    document.querySelectorAll('.settings-toggle[data-settings-target]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const target = document.getElementById(btn.dataset.settingsTarget);
            const card = btn.closest('details.folder-card');
            if (card) card.open = true;
            if (target) target.hidden = !target.hidden;
        });
    });

    // ── Auto-prepend https:// to bare domains in URL fields ──────────────────
    // These fields accept bare domains like "www.google.com" (the server
    // normalises and validates them properly), so we use type="text" instead
    // of the stricter native type="url" which would reject anything missing
    // a scheme. This just tidies the value up for display/consistency.
    (function () {
        const normalizeUrlValue = (raw) => {
            const value = raw.trim();
            if (value === '' || /^https?:\/\//i.test(value)) return value;
            if (/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+(?:[\/?#].*)?$/i.test(value)) {
                return 'https://' + value;
            }
            return value;
        };
        document.querySelectorAll('input[name="url"], input[name="room"]').forEach(input => {
            input.addEventListener('blur', () => {
                input.value = normalizeUrlValue(input.value);
            });
            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    input.value = normalizeUrlValue(input.value);
                }, true);
            }
        });
    })();

    // ── File upload dropzones + progress (desktop DnD, not folder reorder) ──
    function uploadHasFiles(dt) {
        if (!dt) return false;
        if (dt.files && dt.files.length > 0) return true;
        const types = dt.types;
        if (!types) return true; // some Windows sources omit types until drop
        if (typeof types.contains === 'function') {
            return types.contains('Files')
                || types.contains('application/x-moz-file')
                || types.length === 0;
        }
        const list = Array.from(types);
        if (list.length === 0) return true;
        return list.includes('Files')
            || list.includes('application/x-moz-file')
            || list.some(t => /file/i.test(String(t)));
    }

    function setUploadFilename(zone, name) {
        const el = zone?.querySelector('[data-upload-filename]');
        const row = zone?.querySelector('[data-upload-file-row]');
        if (el) el.textContent = name || '';
        if (row) row.classList.toggle('is-hidden', !name);
        else if (el) el.classList.toggle('is-hidden', !name);
    }

    function clearUploadFile(zone) {
        if (!zone) return;
        const input = zone.querySelector('[data-upload-input]');
        if (input) {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        setUploadError(zone, '');
        setUploadProgress(zone, 0, false);
        syncUploadDropzoneState(zone);
    }

    function setUploadProgress(zone, pct, visible) {
        if (!zone) return;
        const wrap = zone.querySelector('[data-upload-progress]');
        const bar = zone.querySelector('[data-upload-progress-bar]');
        const label = zone.querySelector('[data-upload-progress-label]');
        if (!wrap) return;
        if (!visible) {
            wrap.classList.add('is-hidden');
            zone.classList.remove('is-uploading');
            if (bar) bar.style.width = '0%';
            if (label) label.textContent = '0%';
            return;
        }
        wrap.classList.remove('is-hidden');
        zone.classList.add('is-uploading');
        const safe = Math.max(0, Math.min(100, Math.round(pct || 0)));
        if (bar) bar.style.width = safe + '%';
        if (label) label.textContent = safe + '%';
    }

    function setUploadError(zone, msg) {
        const el = zone?.querySelector('[data-upload-error]');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.classList.remove('is-hidden');
        } else {
            el.textContent = '';
            el.classList.add('is-hidden');
        }
    }

    function assignFileToInput(input, file) {
        if (!input || !file) return false;
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            if (!input.files || input.files.length === 0) return false;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        } catch (_) {
            return false;
        }
    }

    function assignFileListToInput(input, fileList) {
        if (!input || !fileList || !fileList.length) return false;
        try {
            input.files = fileList;
            if (input.files && input.files.length) {
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }
        } catch (_) { /* fall through */ }
        return assignFileToInput(input, fileList[0]);
    }

    function syncUploadDropzoneState(zone) {
        if (!zone) return;
        const input = zone.querySelector('[data-upload-input]');
        zone.classList.toggle('is-disabled', !!(input && input.disabled));
        const file = input?.files?.[0];
        setUploadFilename(zone, file ? file.name : '');
    }

    function initUploadDropzones(scope) {
        (scope || document).querySelectorAll('[data-upload-dropzone]').forEach(zone => {
            if (zone.dataset.uploadBound === '1') return;
            zone.dataset.uploadBound = '1';
            const input = zone.querySelector('[data-upload-input]');
            if (!input) return;

            // accept= makes Chrome show the X cursor on OS file drags.
            input.removeAttribute('accept');
            delete input.dataset.uploadAcceptAll;

            zone.querySelector('[data-upload-clear]')?.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                clearUploadFile(zone);
            });
            input.addEventListener('change', () => {
                setUploadError(zone, '');
                syncUploadDropzoneState(zone);
            });
            syncUploadDropzoneState(zone);
        });
    }

    // OS file DnD lives in assets/upload-dropzone.js (loaded earlier) so a later
    // script error in this file cannot disable drag-and-drop.
    if (!window.__portalUploadDnd) {
      console.warn('upload-dropzone.js did not load — drag-and-drop may not work');
    }

    function xhrFormUpload(url, formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.setRequestHeader('X-Requested-With', 'fetch');
            xhr.responseType = 'text';
            xhr.upload.onprogress = e => {
                if (e.lengthComputable && typeof onProgress === 'function') {
                    onProgress(Math.round((e.loaded / e.total) * 100));
                }
            };
            xhr.onload = () => {
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (_) {
                    reject(new Error('Invalid response'));
                    return;
                }
                resolve({ status: xhr.status, data });
            };
            xhr.onerror = () => reject(new Error('Network error'));
            xhr.send(formData);
        });
    }

    initUploadDropzones(document);

    document.querySelectorAll('form.folder-admin-form').forEach(form => {
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || actionInput.value !== 'create_item') return;
        form.addEventListener('submit', async e => {
            const fileInput = form.querySelector('.item-file-input');
            const hasFile = !!(fileInput && fileInput.files && fileInput.files.length);
            if (!hasFile) return;

            e.preventDefault();
            const zone = form.querySelector('[data-upload-dropzone]');
            const btn = form.querySelector('button[type="submit"]');
            const origLabel = btn ? btn.textContent : '';
            setUploadError(zone, '');
            setUploadProgress(zone, 0, true);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Uploading…';
            }
            try {
                const body = new FormData(form);
                const { data } = await xhrFormUpload(
                    window.location.pathname + window.location.search,
                    body,
                    pct => setUploadProgress(zone, pct, true)
                );
                if (!data || !data.ok) {
                    setUploadProgress(zone, 0, false);
                    setUploadError(zone, (data && data.error) || 'Upload failed.');
                    return;
                }
                setUploadProgress(zone, 100, true);
                window.location.href = data.redirect || (window.location.pathname + window.location.search);
            } catch (_) {
                setUploadProgress(zone, 0, false);
                setUploadError(zone, 'Could not upload. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                }
            }
        });
    });

    document.querySelectorAll('.item-type-select').forEach(sel => {
        const update = () => {
            const form      = sel.closest('form');
            const fileGrp   = form.querySelector('.item-file-group');
            const fileInput = form.querySelector('.item-file-input');
            const fileLabel = form.querySelector('.item-file-label');
            const urlGrp    = form.querySelector('.item-url-group');
            const urlLabel  = form.querySelector('.item-url-label');
            const urlInput  = form.querySelector('input[name="url"]');
            const subGrp    = form.querySelector('.item-submission-group');
            const actGrp    = form.querySelector('.item-activity-group');
            const dlOpt     = form.querySelector('input[name="allow_download"]')?.closest('label');
            const type      = sel.value;
            const isVideo   = type === 'video';
            const isActivity = type === 'activity';
            if (fileGrp) fileGrp.style.display  = type === 'link' || type === 'submission' || isActivity ? 'none' : '';
            if (urlGrp)  urlGrp.style.display   = type === 'submission' || isActivity ? 'none' : '';
            if (subGrp)  subGrp.style.display    = type === 'submission' ? '' : 'none';
            if (actGrp)  actGrp.style.display    = isActivity ? '' : 'none';
            if (dlOpt)   dlOpt.style.display     = (type === 'document' || isVideo) ? '' : 'none';
            if (fileInput && fileLabel) {
                fileInput.setAttribute('accept', isVideo ? fileInput.dataset.videoAccept : fileInput.dataset.docAccept);
                fileLabel.innerHTML = isVideo ? fileInput.dataset.videoHint : fileInput.dataset.docHint;
            }
            if (urlLabel) {
                urlLabel.innerHTML = type === 'link'
                    ? 'Link URL <small>(required)</small>'
                    : (isVideo ? 'Or paste a video link <small>(YouTube or Vimeo only, for student safety)</small>' : 'Or paste URL <small>(optional)</small>');
            }
            if (urlInput) {
                urlInput.required = type === 'link';
                urlInput.placeholder = isVideo
                    ? (urlInput.dataset.videoPlaceholder || urlInput.placeholder)
                    : (urlInput.dataset.docPlaceholder || urlInput.placeholder);
            }
        };
        sel.addEventListener('change', update);
        update();
    });

    // ── Unread announcement notification ──────────────────────────────────────
    (function () {
        const overlay  = document.getElementById('ann-notification');
        if (!overlay) return;

        const pd    = document.getElementById('portal-page-data');
        const slug  = pd?.dataset.slug  ?? '';
        const token = pd?.dataset.csrf  ?? '';

        async function markAndClose() {
            if (overlay.hidden) return;
            const ids = [...overlay.querySelectorAll('[data-ann-id]')].map(el => el.dataset.annId);
            const params = new URLSearchParams({ _token: token, action: 'mark_announcements_read' });
            ids.forEach(id => params.append('announcement_ids[]', id));
            try {
                await fetch('course.php?course=' + encodeURIComponent(slug), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params,
                });
            } catch (_) {}
            // Remove the unread badge from the Announcements tab
            document.querySelector('.course-tab[href*="section=announcements"] .course-tab-badge')?.remove();
            overlay.classList.remove('ann-notify--in');
            overlay.classList.add('ann-notify--out');
            const hideOverlay = () => {
                overlay.hidden = true;
                overlay.classList.remove('ann-notify--out');
            };
            overlay.addEventListener('animationend', hideOverlay, { once: true });
            setTimeout(hideOverlay, 400); // fallback if animationend never fires
        }

        document.getElementById('ann-mark-read')?.addEventListener('click', markAndClose);
        document.getElementById('ann-notify-close')?.addEventListener('click', markAndClose);
        overlay.addEventListener('click', e => { if (e.target === overlay) markAndClose(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') markAndClose(); });

        // Reveal only when ready to animate — never leave an invisible click trap.
        overlay.hidden = false;
        requestAnimationFrame(() => overlay.classList.add('ann-notify--in'));
    })();

    // ── Submission slot modal (all users) ───────────────────────────────────
    let openSubSlotModal = null;

    function openSubSlot(overlay) {
        if (!overlay) return;
        if (openSubSlotModal && openSubSlotModal !== overlay) closeSubSlot(openSubSlotModal);
        overlay.hidden = false;
        overlay.classList.add('sub-slot-overlay--in');
        document.body.classList.add('sub-slot-body-lock');
        openSubSlotModal = overlay;
    }

    function closeSubSlot(overlay) {
        if (!overlay) return;
        overlay.classList.remove('sub-slot-overlay--in');
        let closed = false;
        const finish = () => {
            if (closed) return;
            closed = true;
            overlay.hidden = true;
            if (openSubSlotModal === overlay) {
                openSubSlotModal = null;
                if (!anyPortalOverlayOpen()) {
                    document.body.classList.remove('sub-slot-body-lock');
                }
            }
        };
        overlay.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 220);
    }

    document.querySelectorAll('.sub-slot-card[data-sub-modal]').forEach(card => {
        card.addEventListener('click', e => {
            if (e.target.closest('[data-sub-open-edit]')) return;
            const modal = document.getElementById(card.dataset.subModal || '');
            if (modal) openSubSlot(modal);
        });
        card.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const modal = document.getElementById(card.dataset.subModal || '');
                if (modal) openSubSlot(modal);
            }
        });
    });

    document.querySelectorAll('[data-sub-open-edit]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const modal = document.getElementById(btn.dataset.subOpenEdit || '');
            if (!modal) return;
            openSubSlot(modal);
            if (btn.dataset.subOpenEditForm === '1') {
                const panel = modal.querySelector('[data-sub-slot-edit-panel]');
                if (panel) panel.hidden = false;
            }
        });
    });

    document.querySelectorAll('.sub-slot-dialog-close').forEach(btn => {
        btn.addEventListener('click', () => {
            const overlay = btn.closest('.sub-slot-overlay');
            if (overlay) closeSubSlot(overlay);
        });
    });

    document.querySelectorAll('.sub-slot-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) closeSubSlot(overlay);
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && openSubSlotModal) closeSubSlot(openSubSlotModal);
    });

    document.querySelectorAll('.submit-work-form').forEach(form => {
        const startedAt = Date.now();
        const textArea = form.querySelector('textarea[name="submission_text"]');
        const editField = form.querySelector('input[name="process_edit_seconds"]');
        const pasteField = form.querySelector('input[name="process_paste_events"]');
        const pastedCharsField = form.querySelector('input[name="process_pasted_chars"]');
        const typeSelect = form.querySelector('[data-sub-type-select]');
        const fileInput = form.querySelector('[data-sub-file-input]');
        let pasteEvents = 0;
        let pastedChars = 0;

        if (typeSelect && fileInput) {
            const allowedTypes = Array.from(typeSelect.options)
                .map(o => (o.value || '').toLowerCase())
                .filter(Boolean);

            const fileExt = (file) => {
                const name = String(file?.name || '');
                const dot = name.lastIndexOf('.');
                return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
            };

            const applyDetectedType = (file) => {
                const zone = form.querySelector('[data-upload-dropzone]');
                if (!file) {
                    setUploadError(zone, '');
                    syncUploadDropzoneState(zone);
                    return true;
                }
                let ext = fileExt(file);
                // Normalize common aliases
                if (ext === 'jpeg') ext = allowedTypes.includes('jpeg') ? 'jpeg' : 'jpg';
                if (!allowedTypes.includes(ext)) {
                    fileInput.value = '';
                    setUploadError(zone, 'Unsupported file type. Use PDF, Word, PowerPoint, text, or an image.');
                    syncUploadDropzoneState(zone);
                    return false;
                }
                setUploadError(zone, '');
                // Always force the dropdown to the real extension (do not leave a
                // previously chosen type like PDF selected for a .docx file).
                typeSelect.value = ext;
                fileInput.removeAttribute('accept');
                syncUploadDropzoneState(zone);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) pptxNote.classList.toggle('is-hidden', ext !== 'pptx');
                return true;
            };

            const syncType = () => {
                const type = (typeSelect.value || '').toLowerCase();
                fileInput.disabled = false;
                const file = fileInput.files?.[0];
                if (file && type) {
                    const ext = fileExt(file);
                    // File wins: if the chosen type disagrees with the file, fix the type.
                    if (ext !== type && allowedTypes.includes(ext)) {
                        typeSelect.value = ext;
                    } else if (ext !== type) {
                        fileInput.value = '';
                        setUploadError(form.querySelector('[data-upload-dropzone]'),
                            'Selected type does not match the file. Drop the file again or pick a matching type.');
                    }
                }
                fileInput.removeAttribute('accept');
                const zone = form.querySelector('[data-upload-dropzone]');
                syncUploadDropzoneState(zone);
                setUploadProgress(zone, 0, false);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) pptxNote.classList.toggle('is-hidden', (typeSelect.value || '') !== 'pptx');
            };

            typeSelect.addEventListener('change', syncType);
            fileInput.addEventListener('change', () => {
                applyDetectedType(fileInput.files?.[0] || null);
                const pptxNote = form.querySelector('[data-sub-pptx-note]');
                if (pptxNote) {
                    pptxNote.classList.toggle('is-hidden', (typeSelect.value || '').toLowerCase() !== 'pptx');
                }
            });
            syncType();
        }

        if (textArea) {
            textArea.addEventListener('paste', e => {
                pasteEvents += 1;
                pastedChars += (e.clipboardData?.getData('text') || '').length;
                if (pasteField) pasteField.value = String(pasteEvents);
                if (pastedCharsField) pastedCharsField.value = String(pastedChars);
            });

            const counterEl = form.querySelector('[data-sub-word-count]');
            const minWords = parseInt(textArea.dataset.minWords || '0', 10);
            if (counterEl && minWords > 0) {
                const updateCounter = () => {
                    const words = (textArea.value.match(/[a-z0-9]+(?:'[a-z0-9]+)?/gi) || []).length;
                    counterEl.textContent = words + ' / ' + minWords + ' word' + (minWords === 1 ? '' : 's') + ' minimum';
                    counterEl.classList.toggle('submit-word-count--ok', words >= minWords);
                    counterEl.classList.toggle('submit-word-count--low', words > 0 && words < minWords);
                };
                textArea.addEventListener('input', updateCounter);
                updateCounter();
            }
        }

        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (editField) {
                editField.value = String(Math.max(0, Math.round((Date.now() - startedAt) / 1000)));
            }
            if (pasteField) pasteField.value = String(pasteEvents);
            if (pastedCharsField) pastedCharsField.value = String(pastedChars);

            const hasPaste = !!(textArea && textArea.value.trim());
            const hasFile = !!(fileInput && fileInput.files && fileInput.files.length);
            if (hasFile && typeSelect && !typeSelect.value) {
                // Last-chance auto-detect if the type select was cleared.
                const name = String(fileInput.files[0].name || '');
                const dot = name.lastIndexOf('.');
                const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
                const match = Array.from(typeSelect.options).some(o => o.value === ext);
                if (match) {
                    typeSelect.value = ext;
                } else {
                    const errEl = form.querySelector('[data-sub-error]');
                    if (errEl) {
                        errEl.textContent = 'Unsupported file type. Use PDF, Word, PowerPoint, text, or an image.';
                        errEl.classList.add('is-visible');
                    }
                    return;
                }
            }
            if (!hasFile && !hasPaste) {
                const errEl = form.querySelector('[data-sub-error]');
                if (errEl) {
                    errEl.textContent = 'Upload a document or paste your submission text before submitting.';
                    errEl.classList.add('is-visible');
                }
                return;
            }

            const btn = form.querySelector('[data-sub-submit-btn]') || form.querySelector('button[type="submit"]');
            const origLabel = btn ? btn.textContent : '';
            const errEl = form.querySelector('[data-sub-error]');
            const zone = form.querySelector('[data-upload-dropzone]');
            if (errEl) {
                errEl.textContent = '';
                errEl.classList.remove('is-visible');
            }
            if (btn) {
                btn.disabled = true;
                btn.textContent = hasFile ? 'Uploading…' : 'Submitting…';
            }
            if (hasFile) setUploadProgress(zone, 0, true);

            try {
                // Disabled file inputs are omitted from FormData — re-enable briefly if needed.
                const wasDisabled = fileInput && fileInput.disabled;
                if (wasDisabled) fileInput.disabled = false;
                const body = new FormData(form);
                if (wasDisabled) fileInput.disabled = true;

                let data;
                if (hasFile) {
                    const result = await xhrFormUpload(
                        window.location.pathname + window.location.search,
                        body,
                        pct => setUploadProgress(zone, pct, true)
                    );
                    data = result.data;
                } else {
                    const res = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body,
                        headers: { 'X-Requested-With': 'fetch' }
                    });
                    data = await res.json();
                }
                if (!data.ok) {
                    setUploadProgress(zone, 0, false);
                    const msg = data.error || 'Submission failed.';
                    if (errEl) {
                        errEl.textContent = msg;
                        errEl.classList.add('is-visible');
                    } else {
                        alert(msg);
                    }
                    return;
                }
                if (hasFile) setUploadProgress(zone, 100, true);
                handleSubmitSuccess(form, data);
                setUploadProgress(zone, 0, false);
                setUploadFilename(zone, '');
            } catch (_) {
                setUploadProgress(zone, 0, false);
                const msg = 'Could not submit. Please try again.';
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.classList.add('is-visible');
                } else {
                    alert(msg);
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                }
            }
        });
    });

    function escapeHtmlText(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function handleSubmitSuccess(form, data) {
        const itemId = data.item_id;
        const card = document.querySelector('.sub-slot-card[data-item-id="' + itemId + '"]');
        const modal = form.closest('.sub-slot-overlay')
            || document.querySelector('.sub-slot-overlay[data-item-id="' + itemId + '"]');

        if (card) {
            const row = card.querySelector('[data-sub-card-row]');
            if (row) {
                row.innerHTML = '<span class="sub-slot-file" data-sub-card-file><span>'
                    + escapeHtmlText(data.filename) + '</span></span>'
                    + '<span class="sub-slot-status sub-slot-status--pending" data-sub-card-status>Not graded</span>';
            }
            const meta = card.querySelector('[data-sub-card-meta]');
            if (meta) {
                meta.textContent = 'Submitted ' + (data.submitted_at_label || '');
                meta.classList.remove('is-hidden');
                meta.classList.add('is-visible');
            }
            card.classList.add('sub-slot-card--flash');
            window.setTimeout(() => card.classList.remove('sub-slot-card--flash'), 1200);
        }

        document.querySelectorAll('[data-sub-attempts-note][data-max-attempts]').forEach(el => {
            const cardOrModal = el.closest('.sub-slot-card, .sub-slot-overlay');
            const belongsToItem = cardOrModal && cardOrModal.dataset.itemId === String(itemId);
            if (belongsToItem && data.max_attempts) {
                el.textContent = 'Attempt ' + Math.min(data.attempts_used, data.max_attempts) + ' of ' + data.max_attempts + ' used';
            }
        });

        if (modal) {
            const mine = modal.querySelector('[data-sub-mine]');
            if (mine) {
                mine.classList.add('is-visible');
                const fnSpan = mine.querySelector('[data-sub-filename] span');
                if (fnSpan) fnSpan.textContent = data.filename || '';
                const dateEl = mine.querySelector('[data-sub-date]');
                if (dateEl) dateEl.textContent = 'Submitted ' + (data.submitted_at_label || '');
                const badge = mine.querySelector('[data-sub-grade-badge]');
                if (badge) {
                    badge.className = 'sub-modal-grade sub-modal-grade--pending';
                    badge.textContent = 'Not graded';
                }
                const reviewBtn = mine.querySelector('[data-sub-review-btn]');
                if (reviewBtn && data.submission_id) {
                    reviewBtn.dataset.reviewOpen = 'rvw-' + data.submission_id;
                    reviewBtn.dataset.reviewRefresh = '1';
                    reviewBtn.classList.remove('is-hidden');
                }
            }

            const success = modal.querySelector('[data-sub-success]');
            if (success) {
                success.textContent = '';
                const icon = document.createElement('span');
                icon.className = 'sub-submit-success-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = '✓';
                const msg = document.createElement('span');
                msg.textContent = data.message || 'Submission received.';
                success.appendChild(icon);
                success.appendChild(msg);
                success.classList.add('is-visible');
                window.setTimeout(() => success.classList.remove('is-visible'), 6000);
            }

            const receiptCard = modal.querySelector('[data-sub-receipt-card]') || form.querySelector('[data-sub-receipt-card]');
            if (receiptCard && data.receipt_number) {
                const setText = (sel, value) => {
                    const el = receiptCard.querySelector(sel);
                    if (el) el.textContent = value || '—';
                };
                setText('[data-sub-receipt-number]', data.receipt_number);
                setText('[data-sub-receipt-student]', (data.student_name || '') + (data.student_username ? ' (' + data.student_username + ')' : ''));
                setText('[data-sub-receipt-course]', data.course_title || '');
                setText('[data-sub-receipt-assignment]', data.assignment_title || '');
                setText('[data-sub-receipt-file]', data.filename || '');
                setText('[data-sub-receipt-type]', data.declared_type || '');
                setText('[data-sub-receipt-when]', data.submitted_at_label || '');
                setText('[data-sub-receipt-hash]', data.file_sha256_prefix ? (data.file_sha256_prefix + '…') : '—');
                receiptCard.hidden = false;
                receiptCard.classList.remove('is-hidden');

                const copyBtn = receiptCard.querySelector('[data-sub-receipt-copy]');
                if (copyBtn && !copyBtn.dataset.bound) {
                    copyBtn.dataset.bound = '1';
                    copyBtn.addEventListener('click', () => {
                        const num = receiptCard.querySelector('[data-sub-receipt-number]')?.textContent || '';
                        if (num && navigator.clipboard) {
                            navigator.clipboard.writeText(num).catch(() => {});
                        }
                    });
                }
                const printBtn = receiptCard.querySelector('[data-sub-receipt-print]');
                if (printBtn && !printBtn.dataset.bound) {
                    printBtn.dataset.bound = '1';
                    printBtn.addEventListener('click', () => {
                        document.body.classList.add('printing-sub-receipt');
                        receiptCard.classList.add('is-print-target');
                        window.print();
                        window.setTimeout(() => {
                            document.body.classList.remove('printing-sub-receipt');
                            receiptCard.classList.remove('is-print-target');
                        }, 300);
                    });
                }
            }

            const title = modal.querySelector('[data-sub-submit-title]');
            if (title) title.textContent = 'Re-submit work';
            const submitBtn = form.querySelector('[data-sub-submit-btn]');
            if (submitBtn) submitBtn.textContent = 'Re-submit';
            const keptType = form.querySelector('[data-sub-type-select]')?.value || '';
            form.reset();
            const typeSelect = form.querySelector('[data-sub-type-select]');
            const fileInput = form.querySelector('[data-sub-file-input]');
            if (typeSelect) {
                typeSelect.value = '';
                typeSelect.dispatchEvent(new Event('change'));
            }
            if (fileInput) {
                fileInput.value = '';
                fileInput.disabled = false;
                const zone = form.querySelector('[data-upload-dropzone]');
                syncUploadDropzoneState(zone);
            }
            void keptType;
            const section = form.closest('[data-sub-submit-section]');
            if (section) {
                section.classList.add('sub-modal-section--done');
                window.setTimeout(() => section.classList.remove('sub-modal-section--done'), 1200);

                if (data.attempts_reached) {
                    const fields = section.querySelector('[data-sub-submit-fields]');
                    const closedMsg = section.querySelector('[data-sub-attempts-closed-msg]');
                    if (fields) fields.classList.add('is-hidden');
                    if (closedMsg) closedMsg.classList.remove('is-hidden');
                }
            }
        }

        const shell = document.querySelector('.rvw-shell[data-submission-id="' + data.submission_id + '"]');
        if (shell) {
            const staleOverlay = shell.closest('.rvw-overlay');
            if (staleOverlay) {
                staleOverlay.remove();
            }
            delete shell.dataset.previewLoaded;
            const mount = shell.querySelector('[data-preview-mount]');
            if (mount) {
                mount.innerHTML = '<p class="rvw-doc-loading">Loading document…</p>';
            }
            clearAnnotateBaseIfExists(shell);
        }
    }

    function clearAnnotateBaseIfExists(shell) {
        const surface = shell.querySelector('[data-preview-mount]') || shell.querySelector('[data-annotate-surface]');
        if (!surface) return;
        delete surface.dataset.annotateBaseStored;
        delete surface.dataset.baseHtml;
        delete surface.dataset.baseText;
    }

    // ── Turnitin-style submission review (all users) ────────────────────────
    (function initReview() {
        const tokenInput = document.querySelector('input[name="_token"]');
        const csrf = tokenInput ? tokenInput.value : '';
        const courseParam = new URLSearchParams(location.search).get('course') || '';
        let openReview = null;

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // Badge digits live in the DOM but must not affect char offsets used for
        // annotation ranges (otherwise later highlights shift and clip letters).
        function isAnnotateMetaText(node) {
            return !!(node && node.parentElement && node.parentElement.closest('sup.rvw-hl-badge'));
        }

        function offsetInNode(root, node, offset) {
            let total = 0;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
            let cur;
            while ((cur = walker.nextNode())) {
                if (isAnnotateMetaText(cur)) continue;
                if (cur === node) return total + offset;
                total += cur.textContent.length;
            }
            if (node === root) {
                let n = 0, sum = 0;
                for (const child of root.childNodes) {
                    if (n >= offset) break;
                    sum += child.textContent.length;
                    n++;
                }
                return sum;
            }
            return total;
        }

        function closeCommentOverlay() {
            document.querySelectorAll('.rvw-comment-overlay').forEach(el => el.remove());
            if (window._rvwCommentOverlayTimer) {
                clearTimeout(window._rvwCommentOverlayTimer);
                window._rvwCommentOverlayTimer = null;
            }
        }

        function positionCommentOverlay(pop, anchorEl) {
            if (!pop) return;
            const margin = 12;
            const popW = pop.offsetWidth || 300;
            const popH = pop.offsetHeight || 160;
            let x;
            let y;
            const rect = anchorEl && anchorEl.getBoundingClientRect
                ? anchorEl.getBoundingClientRect()
                : null;
            const anchorVisible = rect
                && rect.bottom > margin
                && rect.top < window.innerHeight - margin
                && rect.right > margin
                && rect.left < window.innerWidth - margin;

            if (anchorVisible) {
                // Prefer centered under the highlight; flip above if it would overflow.
                x = rect.left + (rect.width / 2) - (popW / 2);
                y = rect.bottom + 10;
                if (y + popH > window.innerHeight - margin) {
                    y = rect.top - popH - 10;
                }
            } else {
                // Anchor off-screen / missing: settle in a stable viewport spot.
                x = (window.innerWidth - popW) / 2;
                y = Math.max(margin, Math.round(window.innerHeight * 0.18));
            }

            x = Math.min(Math.max(margin, x), window.innerWidth - popW - margin);
            y = Math.min(Math.max(margin, y), window.innerHeight - popH - margin);
            pop.style.left = Math.round(x) + 'px';
            pop.style.top = Math.round(y) + 'px';
        }

        function showCommentOverlay(ann, anchorEl, opts) {
            closeCommentOverlay();
            if (!ann) return;
            const delay = opts && opts.delay > 0 ? opts.delay : 0;

            const mount = () => {
                const pop = document.createElement('div');
                pop.className = 'rvw-comment-overlay';
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-label', 'Comment');
                const quote = (ann.quote || '').trim();
                const quoteHtml = quote
                    ? '<p class="rvw-comment-overlay-quote">\u201C' + escapeHtml(quote.length > 140 ? quote.slice(0, 137) + '\u2026' : quote) + '\u201D</p>'
                    : '';
                const author = (ann.author || '').trim();
                pop.innerHTML = '<button type="button" class="rvw-comment-overlay-close" aria-label="Close comment" title="Close">'
                    + '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">'
                    + '<path d="M6.7 6.7l10.6 10.6M17.3 6.7L6.7 17.3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>'
                    + '</svg></button>'
                    + quoteHtml
                    + '<p class="rvw-comment-overlay-body">' + escapeHtml(ann.comment || '') + '</p>'
                    + (author ? '<p class="rvw-comment-overlay-author">' + escapeHtml(author) + '</p>' : '');
                document.body.appendChild(pop);

                positionCommentOverlay(pop, anchorEl);
                // Re-measure after layout so height-based flip is accurate.
                requestAnimationFrame(() => {
                    positionCommentOverlay(pop, anchorEl);
                    pop.classList.add('is-open');
                });
                pop.querySelector('.rvw-comment-overlay-close').addEventListener('click', e => {
                    e.stopPropagation();
                    closeCommentOverlay();
                });
            };

            if (delay) {
                window._rvwCommentOverlayTimer = setTimeout(() => {
                    window._rvwCommentOverlayTimer = null;
                    mount();
                }, delay);
            } else {
                mount();
            }
        }

        function openReviewOverlay(overlay) {
            if (!overlay) return;
            overlay.hidden = false;
            document.body.classList.add('sub-slot-body-lock');
            openReview = overlay;
            requestAnimationFrame(() => overlay.classList.add('rvw-overlay--in'));
            const shell = overlay.querySelector('.rvw-shell');
            if (shell) setReviewMobilePanel(shell, shell.getAttribute('data-mobile-panel') || 'results');
            if (shell && shell.dataset.previewLoaded === '1') wireReviewPageNav(shell);
        }

        function setReviewMobilePanel(shell, panel) {
            if (!shell) return;
            const next = panel === 'results' ? 'results' : 'doc';
            shell.setAttribute('data-mobile-panel', next);
            shell.querySelectorAll('.rvw-mobile-tab').forEach(tab => {
                const active = tab.getAttribute('data-rvw-mobile-panel') === next;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        document.addEventListener('click', e => {
            const tab = e.target.closest('[data-rvw-mobile-panel]');
            if (!tab) return;
            const shell = tab.closest('.rvw-shell');
            if (!shell) return;
            e.preventDefault();
            setReviewMobilePanel(shell, tab.getAttribute('data-rvw-mobile-panel'));
        });
        function reloadAndOpenReview(reviewId) {
            if (!reviewId) return;
            const url = new URL(window.location.href);
            url.searchParams.set('open_review', reviewId);
            if (!url.searchParams.has('section')) {
                url.searchParams.set('section', 'content');
            }
            window.location.href = url.toString();
        }
        function closeReviewOverlay(overlay) {
            if (!overlay) return;
            closeComposer();
            closeCommentOverlay();
            overlay.classList.remove('rvw-overlay--in');
            setTimeout(() => {
                overlay.hidden = true;
                if (openReview === overlay) openReview = null;
                if (!anyPortalOverlayOpen()) {
                    document.body.classList.remove('sub-slot-body-lock');
                }
            }, 200);
        }

        document.addEventListener('keydown', e => {
            if (!openReview || openReview.hidden) return;
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
            const shell = openReview.querySelector('.rvw-shell');
            const navApi = shell && shell._rvwPageNav;
            if (!navApi) return;
            e.preventDefault();
            if (e.key === 'ArrowLeft') navApi.scrollToPage(navApi.current - 1);
            else navApi.scrollToPage(navApi.current + 1);
        });

        document.addEventListener('click', e => {
            const btn = e.target.closest('[data-review-open]');
            if (!btn || !btn.dataset.reviewOpen) return;
            e.stopPropagation();
            if (btn.dataset.reviewRefresh === '1') {
                reloadAndOpenReview(btn.dataset.reviewOpen);
                return;
            }
            const overlay = document.getElementById(btn.dataset.reviewOpen);
            if (!overlay) {
                reloadAndOpenReview(btn.dataset.reviewOpen);
                return;
            }
            openReviewOverlay(overlay);
            const shell = overlay?.querySelector('.rvw-shell');
            if (shell) loadSubmissionPreview(shell);
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            const card = e.target.closest('.sub-slot-card[data-review-open]');
            if (!card || e.target.closest('button, a, input, textarea, select')) return;
            e.preventDefault();
            card.click();
        });
        document.querySelectorAll('.rvw-overlay .rvw-close').forEach(btn => {
            btn.addEventListener('click', () => closeReviewOverlay(btn.closest('.rvw-overlay')));
        });
        document.querySelectorAll('.rvw-overlay').forEach(ov => {
            ov.addEventListener('click', e => { if (e.target === ov) closeReviewOverlay(ov); });
        });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const pop = document.querySelector('.rvw-popover');
            if (pop) {
                closeComposer();
                return;
            }
            if (document.querySelector('.rvw-comment-overlay')) {
                closeCommentOverlay();
                return;
            }
            if (openReview) closeReviewOverlay(openReview);
        });
        document.addEventListener('pointerdown', e => {
            const overlay = document.querySelector('.rvw-comment-overlay');
            if (!overlay) return;
            if (overlay.contains(e.target)) return;
            if (e.target.closest('mark.rvw-hl, .rvw-comment, .rvw-pin, .rvw-popover')) return;
            closeCommentOverlay();
        });

        const requestedReview = new URLSearchParams(location.search).get('open_review') || '';
        if (requestedReview) {
            const overlay = document.getElementById(requestedReview);
            if (overlay) {
                openReviewOverlay(overlay);
                const shell = overlay.querySelector('.rvw-shell');
                if (shell) loadSubmissionPreview(shell);
                const clean = new URL(window.location.href);
                clean.searchParams.delete('open_review');
                history.replaceState(null, '', clean.toString());
            }
        }

        function showGradeView(block) {
            const view = block.querySelector('.rvw-grade-view');
            const form = block.querySelector('[data-grade-form]');
            if (view) view.classList.add('is-visible');
            if (form) form.classList.remove('is-visible');
        }

        function showGradeEdit(block) {
            const view = block.querySelector('.rvw-grade-view');
            const form = block.querySelector('[data-grade-form]');
            if (view) view.classList.remove('is-visible');
            if (form) form.classList.add('is-visible');
        }

        function applyGradePosted(block, score, feedback, released) {
            const scoreEl = block.querySelector('[data-grade-score-display]');
            if (scoreEl) scoreEl.innerHTML = String(score) + '<small>/100</small>';
            const label = block.querySelector('[data-grade-saved-label]');
            if (label) label.textContent = released ? 'Grade released' : 'Grade saved — not released';
            const releaseForm = block.querySelector('[data-grade-release-form]');
            if (releaseForm) releaseForm.classList.toggle('is-hidden', !!released);
            const fbView = block.querySelector('[data-grade-feedback-view]');
            const fbText = block.querySelector('[data-grade-feedback-text]');
            const noFb = block.querySelector('[data-grade-no-feedback]');
            if (feedback) {
                if (fbText) fbText.textContent = feedback;
                fbView?.classList.add('is-visible');
                noFb?.classList.remove('is-visible');
            } else {
                fbView?.classList.remove('is-visible');
                noFb?.classList.add('is-visible');
            }
            const posted = block.querySelector('.rvw-grade-posted');
            posted?.classList.add('is-posted-flash');
            window.setTimeout(() => posted?.classList.remove('is-posted-flash'), 1200);
            showGradeView(block);
            block.querySelector('.rvw-grade-cancel')?.classList.add('is-visible');
        }

        function updateGradeBadges(subId, score, released) {
            const held = !released;
            const statusEls = document.querySelectorAll(
                '.sub-slot-card[data-submission-id="' + subId + '"] [data-grade-status],'
                + '.sub-slot-card[data-review-open="rvw-' + subId + '"] .sub-slot-status,'
                + '.sub-modal-entry[data-submission-id="' + subId + '"] [data-grade-status],'
                + '.sub-modal-entry[data-submission-id="' + subId + '"] .sub-modal-grade'
            );
            statusEls.forEach((el) => {
                if (el.classList.contains('sub-modal-grade')) {
                    el.className = held
                        ? 'sub-modal-grade sub-modal-grade--held'
                        : 'sub-modal-grade sub-modal-grade--marked';
                    el.innerHTML = held
                        ? String(score) + '<small>/100 held</small>'
                        : String(score) + '<small>/100</small>';
                    return;
                }
                el.className = held
                    ? 'sub-slot-status sub-slot-status--held'
                    : 'sub-slot-status sub-slot-status--graded';
                el.setAttribute('data-grade-status', '');
                el.textContent = held ? (String(score) + '% held') : (String(score) + '%');
            });

            const card = document.querySelector('.sub-slot-card[data-submission-id="' + subId + '"], .sub-slot-card[data-review-open="rvw-' + subId + '"]');
            const releaseForm = card?.closest('[data-gb-slot]')?.querySelector('[data-gb-release-form]');
            if (held && releaseForm) {
                releaseForm.classList.remove('is-hidden');
            }

            const entry = document.querySelector('.sub-modal-entry[data-submission-id="' + subId + '"]');
            const grade = entry?.querySelector('.sub-modal-grade');
            if (grade && statusEls.length === 0) {
                grade.className = held ? 'sub-modal-grade sub-modal-grade--held' : 'sub-modal-grade sub-modal-grade--marked';
                grade.innerHTML = held ? String(score) + '<small>/100 held</small>' : String(score) + '<small>/100</small>';
            }

            const shell = document.querySelector('.rvw-shell[data-submission-id="' + subId + '"]');
            if (shell && shell.dataset.canAnnotate !== '1') {
                const display = shell.querySelector('.rvw-grade-display');
                const big = shell.querySelector('.rvw-grade-big');
                const pending = shell.querySelector('.rvw-grade-pending');
                if (display && !big) {
                    display.innerHTML = '<span class="rvw-grade-big">' + String(score) + '<small>/100</small></span>';
                } else if (big) {
                    big.innerHTML = String(score) + '<small>/100</small>';
                }
                if (pending) pending.remove();
            }
        }

        document.querySelectorAll('[data-grade-block]').forEach(block => {
            const form = block.querySelector('[data-grade-form]');
            block.querySelector('.rvw-grade-edit')?.addEventListener('click', () => showGradeEdit(block));
            block.querySelector('.rvw-grade-cancel')?.addEventListener('click', () => showGradeView(block));
            if (!form) return;
            form.addEventListener('submit', async e => {
                e.preventDefault();
                const btn = form.querySelector('[data-grade-submit]');
                const orig = btn ? btn.textContent : '';
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Saving…';
                }
                try {
                    const res = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'X-Requested-With': 'fetch' }
                    });
                    const data = await res.json();
                    if (data.ok) {
                        applyGradePosted(block, data.score, data.feedback, data.released);
                        const shell = block.closest('.rvw-shell');
                        if (shell?.dataset.submissionId) {
                            updateGradeBadges(shell.dataset.submissionId, data.score, data.released);
                        }
                    } else {
                        alert(data.error || 'Could not save grade.');
                    }
                } catch (_) {
                    alert('Could not save grade.');
                } finally {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = orig;
                    }
                }
            });
        });

        function postAction(params) {
            const body = new URLSearchParams(params);
            body.set('_token', csrf);
            return fetch('course.php?course=' + encodeURIComponent(courseParam), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
                body: body.toString()
            }).then(r => r.json());
        }

        function normalizeAnn(a) {
            return {
                id: parseInt(a.id, 10),
                anchor_type: a.anchor_type,
                range_start: a.range_start != null ? parseInt(a.range_start, 10) : null,
                range_end: a.range_end != null ? parseInt(a.range_end, 10) : null,
                quote: a.quote || '',
                pos_x: a.pos_x != null ? parseFloat(a.pos_x) : null,
                pos_y: a.pos_y != null ? parseFloat(a.pos_y) : null,
                comment: a.comment || '',
                author: a.author_name || a.author || ''
            };
        }

        function closeComposer() {
            document.querySelectorAll('.rvw-popover').forEach(p => p.remove());
            closeCommentOverlay();
            document.querySelectorAll('[data-preview-mount], [data-annotate-surface]').forEach(surface => {
                clearPendingHighlight(surface);
            });
        }

        function clearPendingHighlight(surface) {
            if (!surface) return;
            surface.querySelectorAll('mark.rvw-hl--pending').forEach(mark => {
                const parent = mark.parentNode;
                if (!parent) return;
                while (mark.firstChild) parent.insertBefore(mark.firstChild, mark);
                parent.removeChild(mark);
                parent.normalize();
            });
        }

        function applyPendingHighlight(surface, start, end) {
            if (!surface) return;
            clearPendingHighlight(surface);
            const startPos = findTextPosition(surface, start);
            const endPos = findTextPosition(surface, end);
            if (!startPos || !endPos) return;

            const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
            const spanned = [];
            let node;
            let collecting = false;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                if (node === startPos.node) collecting = true;
                if (collecting) spanned.push(node);
                if (node === endPos.node) break;
            }

            spanned.forEach(n => {
                const from = (n === startPos.node) ? startPos.offset : 0;
                const to = (n === endPos.node) ? endPos.offset : n.textContent.length;
                if (to <= from) return;
                const range = document.createRange();
                range.setStart(n, from);
                range.setEnd(n, to);
                const mark = document.createElement('mark');
                mark.className = 'rvw-hl rvw-hl--pending';
                mark.dataset.pending = '1';
                try {
                    range.surroundContents(mark);
                } catch (_) { /* skip unclean wraps */ }
            });
        }

        function clearAnnotateBase(surface) {
            if (!surface) return;
            delete surface.dataset.annotateBaseStored;
            delete surface.dataset.baseHtml;
            delete surface.dataset.baseText;
        }

        function getAnnotateSurface(shell) {
            const mode = shell.dataset.previewMode || '';
            const mount = shell.querySelector('[data-preview-mount]');
            if (mount && (mode === 'docx' || mode === 'txt')) {
                if (mount.querySelector('.rvw-doc-loading')) return null;
                if (mount.textContent.trim()) return mount;
            }
            const plain = shell.querySelector('.rvw-text[data-annotate-surface]');
            if (plain) return plain;
            if (mode === 'pdf' || mode === 'office') {
                const layer = shell.querySelector('.rvw-text-layer[data-annotate-surface]');
                if (layer) return layer;
            }
            return null;
        }

        function findTextPosition(root, charIndex) {
            let offset = 0;
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                const len = node.textContent.length;
                if (offset + len >= charIndex) {
                    return { node, offset: charIndex - offset };
                }
                offset += len;
            }
            return null;
        }

        function wrapRangeInSurface(root, start, end, annId, badgeNum, onClick) {
            const startPos = findTextPosition(root, start);
            const endPos = findTextPosition(root, end);
            if (!startPos || !endPos) return;

            // Collect every text node spanned by the range first (read-only pass)
            // so a highlight that crosses paragraph/block boundaries never ends up
            // nesting a block element (e.g. <p>) inside the inline <mark>, which
            // corrupts the layout. Each text node gets its own <mark> instead.
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const spanned = [];
            let node;
            let collecting = false;
            while ((node = walker.nextNode())) {
                if (isAnnotateMetaText(node)) continue;
                if (node === startPos.node) collecting = true;
                if (collecting) spanned.push(node);
                if (node === endPos.node) break;
            }
            if (!spanned.length) return;

            const marks = [];
            spanned.forEach(n => {
                const from = (n === startPos.node) ? startPos.offset : 0;
                const to = (n === endPos.node) ? endPos.offset : n.textContent.length;
                if (to <= from) return;
                const range = document.createRange();
                range.setStart(n, from);
                range.setEnd(n, to);
                const mark = document.createElement('mark');
                mark.className = 'rvw-hl';
                mark.dataset.ann = annId;
                try {
                    range.surroundContents(mark);
                    marks.push(mark);
                } catch (_) { /* skip nodes that can't be wrapped cleanly */ }
            });

            if (!marks.length) return;

            // Start/mid/end classes keep multi-node wraps (e.g. bold run splits)
            // looking like one continuous highlight instead of separate boxes.
            marks.forEach((mark, i) => {
                if (i === 0) mark.classList.add('rvw-hl--start');
                if (i === marks.length - 1) mark.classList.add('rvw-hl--end');
                if (i > 0 && i < marks.length - 1) mark.classList.add('rvw-hl--mid');
            });

            // Keep the badge outside the <mark> so outlines/backgrounds don't
            // fragment around the superscript.
            if (badgeNum) {
                const badge = document.createElement('sup');
                badge.className = 'rvw-hl-badge';
                badge.textContent = String(badgeNum);
                badge.setAttribute('aria-hidden', 'true');
                const last = marks[marks.length - 1];
                if (last.parentNode) {
                    last.parentNode.insertBefore(badge, last.nextSibling);
                }
            }
            marks.forEach(mark => mark.addEventListener('click', onClick));
        }

        function storeAnnotateBase(surface) {
            if (!surface || surface.dataset.annotateBaseStored === '1') return;
            if (surface.querySelector('p, h1, h2, h3, pre, table, li')) {
                surface.dataset.baseHtml = surface.innerHTML;
            } else {
                surface.dataset.baseText = surface.textContent;
            }
            surface.dataset.annotateBaseStored = '1';
        }

        function restoreAnnotateBase(surface) {
            if (!surface) return;
            if (surface.dataset.baseHtml != null) {
                surface.innerHTML = surface.dataset.baseHtml;
            } else if (surface.dataset.baseText != null) {
                surface.textContent = surface.dataset.baseText;
            }
        }

        async function loadSubmissionPreview(shell) {
            if (!shell) return;
            if (shell.dataset.previewLoaded === '1') {
                wireReviewPageNav(shell);
                return;
            }

            const mode = shell.dataset.previewMode || '';
            const subId = shell.dataset.submissionId || '';
            const mount = shell.querySelector('[data-preview-mount]');
            const docEl = shell.querySelector('.rvw-doc');

            if (docEl && docEl.querySelector('.rvw-iframe')) {
                docEl.classList.add('rvw-doc--iframe');
            }

            if (!mount || !subId) {
                if (mode === 'pdf' || mode === 'office' || mode === 'viewer' || mode === 'pptx') {
                    shell.dataset.previewLoaded = '1';
                }
                return;
            }

            const url = 'download.php?sub=' + encodeURIComponent(subId) + '&view=1';
            const showErr = msg => {
                mount.innerHTML = '<p class="rvw-doc-error">' + escapeHtml(msg) + '</p>';
            };

            try {
                if (mode === 'docx') {
                    if (typeof mammoth === 'undefined') {
                        showErr('Document preview library failed to load. Please refresh the page.');
                        return;
                    }
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load document.');
                        return;
                    }
                    const buf = await resp.arrayBuffer();
                    const result = await mammoth.convertToHtml({ arrayBuffer: buf });
                    const safeHtml = sanitizeDocHtml(result.value || '');
                    paginateReviewDocx(mount, safeHtml);
                } else if (mode === 'xlsx') {
                    if (typeof XLSX === 'undefined') {
                        showErr('Spreadsheet preview library failed to load. Please refresh the page.');
                        return;
                    }
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load spreadsheet.');
                        return;
                    }
                    const buf = await resp.arrayBuffer();
                    const wb = XLSX.read(buf, { type: 'array' });
                    const sheetName = wb.SheetNames[0];
                    const sheet = sheetName ? wb.Sheets[sheetName] : null;
                    if (!sheet) {
                        showErr('Could not read this workbook.');
                        return;
                    }
                    mount.innerHTML = '<div class="viewer-sheet-head">Sheet: ' + escapeHtml(sheetName) + '</div>'
                        + renderSheetToTable(sheet);
                } else if (mode === 'pptx') {
                    // Download-only: no in-browser slide render.
                    shell.dataset.previewLoaded = '1';
                    return;
                } else if (mode === 'txt') {
                    const resp = await fetch(url);
                    if (!resp.ok) {
                        showErr('Could not load file.');
                        return;
                    }
                    const content = await resp.text();
                    mount.innerHTML = '<pre class="rvw-txt-pre">' + escapeHtml(content) + '</pre>';
                } else {
                    return;
                }
                const surface = getAnnotateSurface(shell);
                if (surface) {
                    clearAnnotateBase(surface);
                    storeAnnotateBase(surface);
                }
                shell.dispatchEvent(new CustomEvent('rvw-preview-loaded'));
                // Also call render directly in case the event listener isn't ready yet
                if (typeof shell._rvwRender === 'function') shell._rvwRender();
                // Wire page nav AFTER annotation render — render restores innerHTML and
                // would otherwise detach the page nodes the nav was holding.
                if (mode === 'docx') wireReviewPageNav(shell);
                shell.dataset.previewLoaded = '1';
            } catch (_) {
                showErr('Could not load preview.');
            }
        }

        document.querySelectorAll('.rvw-shell').forEach(shell => {
            const subId = shell.dataset.submissionId;
            const canAnnotate = shell.dataset.canAnnotate === '1';
            const dataEl = shell.querySelector('.rvw-annotations-data');
            let annotations = [];
            try { annotations = JSON.parse((dataEl && dataEl.textContent) || '[]'); } catch (_) { annotations = []; }

            const commentsEl = shell.querySelector('[data-comments]');
            const imageWrap = shell.querySelector('[data-image-layer]');
            const pinsEl = shell.querySelector('[data-pins]');

            const docEl = shell.querySelector('.rvw-doc');
            if (docEl && docEl.querySelector('.rvw-iframe')) {
                docEl.classList.add('rvw-doc--iframe');
            }

            const plainSurface = shell.querySelector('.rvw-text[data-annotate-surface]');
            if (plainSurface) storeAnnotateBase(plainSurface);

            function render() {
                renderComments();
                renderHighlights();
                if (pinsEl) renderPins();
                if (shell.dataset.previewMode === 'docx') wireReviewPageNav(shell, { preservePage: true });
            }

            function renderHighlights() {
                const surface = getAnnotateSurface(shell);
                if (!surface) return;
                storeAnnotateBase(surface);
                restoreAnnotateBase(surface);
                // Sort by position to assign sequential badge numbers
                const textAnns = annotations
                    .filter(a => a.anchor_type === 'text' && a.range_start != null && a.range_end != null && a.range_end > a.range_start)
                    .sort((a, b) => a.range_start - b.range_start);
                // Apply in reverse order so earlier wraps don't shift later offsets
                const reversed = textAnns.slice().sort((a, b) => b.range_start - a.range_start);
                reversed.forEach(a => {
                    const idx = textAnns.indexOf(a); // sequential index in doc order
                    const range = resolveAnnotationRange(surface, a);
                    wrapRangeInSurface(surface, range.start, range.end, String(a.id), idx + 1, () => focusComment(a.id));
                });
            }

            function annotatePlainText(surface) {
                let out = '';
                const walker = document.createTreeWalker(surface, NodeFilter.SHOW_TEXT);
                let node;
                while ((node = walker.nextNode())) {
                    if (isAnnotateMetaText(node)) continue;
                    out += node.textContent;
                }
                return out;
            }

            // Repair ranges saved while badge digits were wrongly counted in offsets.
            function resolveAnnotationRange(surface, ann) {
                let start = ann.range_start;
                let end = ann.range_end;
                const quote = String(ann.quote || '').replace(/\s+/g, ' ').trim();
                if (!quote || start == null || end == null) return { start, end };
                const text = annotatePlainText(surface);
                const sliced = text.slice(start, end).replace(/\s+/g, ' ').trim();
                if (sliced === quote) return { start, end };
                // Prefer a match near the stored start (typical off-by-N badge drift).
                const windowFrom = Math.max(0, start - 30);
                const windowTo = Math.min(text.length, end + 30);
                const windowText = text.slice(windowFrom, windowTo);
                const localIdx = windowText.indexOf(quote);
                if (localIdx >= 0) {
                    const fixed = windowFrom + localIdx;
                    return { start: fixed, end: fixed + quote.length };
                }
                const globalIdx = text.indexOf(quote);
                if (globalIdx >= 0) {
                    return { start: globalIdx, end: globalIdx + quote.length };
                }
                return { start, end };
            }

            function renderPins() {
                pinsEl.innerHTML = '';
                let n = 0;
                annotations.filter(a => a.anchor_type === 'image' && a.pos_x != null && a.pos_y != null).forEach(a => {
                    n++;
                    const pin = document.createElement('button');
                    pin.type = 'button';
                    pin.className = 'rvw-pin';
                    pin.dataset.ann = a.id;
                    pin.style.left = a.pos_x + '%';
                    pin.style.top = a.pos_y + '%';
                    pin.textContent = String(n);
                    pin.addEventListener('click', () => focusComment(a.id));
                    pinsEl.appendChild(pin);
                });
            }

            function renderComments() {
                commentsEl.innerHTML = '';
                if (!annotations.length) {
                    const p = document.createElement('p');
                    p.className = 'rvw-comments-empty';
                    p.textContent = canAnnotate
                        ? 'No comments yet. Select text or click the image to add one.'
                        : 'No comments from your teacher yet.';
                    commentsEl.appendChild(p);
                    return;
                }
                // Sequential badge numbers match highlight badges in the document
                const textAnnsOrdered = annotations
                    .filter(a => a.anchor_type === 'text' && a.range_start != null)
                    .sort((a, b) => a.range_start - b.range_start);
                annotations.forEach(a => {
                    const card = document.createElement('div');
                    card.className = 'rvw-comment';
                    card.dataset.ann = a.id;
                    let inner = '';
                    const badgeIdx = textAnnsOrdered.indexOf(a);
                    if (badgeIdx >= 0) {
                        inner += '<span class="rvw-comment-num" aria-hidden="true">' + (badgeIdx + 1) + '</span>';
                    }
                    if (a.quote) inner += '<p class="rvw-comment-quote">\u201C' + escapeHtml(a.quote) + '\u201D</p>';
                    inner += '<p class="rvw-comment-body">' + escapeHtml(a.comment) + '</p>';
                    inner += '<div class="rvw-comment-meta"><span class="rvw-comment-author">' + escapeHtml(a.author || '') + '</span>';
                    if (canAnnotate) {
                        inner += '<span class="rvw-comment-actions">'
                            + '<button type="button" class="rvw-comment-btn" data-edit="' + a.id + '">Edit</button>'
                            + '<button type="button" class="rvw-comment-btn rvw-comment-btn--danger" data-del="' + a.id + '">Delete</button>'
                            + '</span>';
                    }
                    inner += '</div>';
                    card.innerHTML = inner;
                    card.addEventListener('click', e => {
                        if (e.target.closest('[data-edit],[data-del]')) return;
                        focusComment(a.id);
                    });
                    commentsEl.appendChild(card);
                });
                if (canAnnotate) {
                    commentsEl.querySelectorAll('[data-del]').forEach(b =>
                        b.addEventListener('click', () => deleteAnnotation(parseInt(b.dataset.del, 10))));
                    commentsEl.querySelectorAll('[data-edit]').forEach(b =>
                        b.addEventListener('click', () => {
                            const a = annotations.find(x => x.id === parseInt(b.dataset.edit, 10));
                            if (a) openComposer({ existing: a });
                        }));
                }
            }

            function focusComment(id) {
                shell.querySelectorAll('.rvw-comment--active').forEach(el => el.classList.remove('rvw-comment--active'));
                shell.querySelectorAll('mark.rvw-hl--active').forEach(el => {
                    el.classList.remove('rvw-hl--active');
                    el.classList.remove('rvw-hl--pulse');
                });
                shell.querySelectorAll('.rvw-pin--active').forEach(el => el.classList.remove('rvw-pin--active'));

                const ann = annotations.find(a => String(a.id) === String(id));
                const card = commentsEl.querySelector('.rvw-comment[data-ann="' + id + '"]');
                if (card) {
                    card.classList.add('rvw-comment--active');
                    card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }

                const marks = shell.querySelectorAll('mark.rvw-hl[data-ann="' + id + '"]');
                let anchorEl = null;
                let willScroll = false;
                if (marks.length) {
                    const mark = marks[0];
                    anchorEl = marks[marks.length - 1];
                    marks.forEach(m => m.classList.add('rvw-hl--active'));
                    // Scroll the document pane to the highlighted text
                    const scrollContainer = shell.querySelector('.rvw-docx-scroll') || shell.querySelector('.rvw-doc');
                    if (scrollContainer) {
                        const markRect = mark.getBoundingClientRect();
                        const containerRect = scrollContainer.getBoundingClientRect();
                        const offset = (markRect.top - containerRect.top) + scrollContainer.scrollTop
                                     - (scrollContainer.clientHeight / 2) + (markRect.height / 2);
                        const delta = Math.abs(offset - scrollContainer.scrollTop);
                        if (delta > 8) {
                            willScroll = true;
                            scrollContainer.scrollTo({ top: offset, behavior: 'smooth' });
                        }
                    } else {
                        mark.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        willScroll = true;
                    }
                    // Pulse animation to draw the eye
                    marks.forEach(m => {
                        m.classList.add('rvw-hl--pulse');
                        m.addEventListener('animationend', () => m.classList.remove('rvw-hl--pulse'), { once: true });
                    });
                }

                const pin = shell.querySelector('.rvw-pin[data-ann="' + id + '"]');
                if (pin) {
                    pin.classList.add('rvw-pin--active');
                    if (!anchorEl) anchorEl = pin;
                }

                // Wait for smooth scroll so the overlay anchors to the final highlight position.
                if (ann) {
                    showCommentOverlay(ann, anchorEl || card, { delay: willScroll ? 340 : 0 });
                }
            }

            function saveAnnotation(payload) {
                return postAction(Object.assign({ action: 'save_annotation', submission_id: subId }, payload)).then(res => {
                    if (res && res.ok && res.annotation) {
                        const norm = normalizeAnn(res.annotation);
                        const idx = annotations.findIndex(a => a.id === norm.id);
                        if (idx >= 0) annotations[idx] = norm; else annotations.push(norm);
                        render();
                        focusComment(norm.id);
                    } else if (res && res.error) {
                        alert(res.error);
                    }
                }).catch(() => alert('Could not save the comment.'));
            }

            function deleteAnnotation(id) {
                if (!confirm('Delete this comment?')) return;
                postAction({ action: 'delete_annotation', annotation_id: id }).then(res => {
                    if (res && res.ok) {
                        annotations = annotations.filter(a => a.id !== id);
                        render();
                    }
                });
            }

            function openComposer(opts) {
                closeComposer();
                if (opts.anchor_type === 'text' && opts.start != null && opts.end != null) {
                    const surface = getAnnotateSurface(shell);
                    if (surface) applyPendingHighlight(surface, opts.start, opts.end);
                }
                const pop = document.createElement('div');
                pop.className = 'rvw-popover';
                const quoteHtml = opts.quote
                    ? '<p class="rvw-popover-quote">“' + escapeHtml(opts.quote.length > 120 ? opts.quote.slice(0, 117) + '…' : opts.quote) + '”</p>'
                    : '';
                pop.innerHTML = (opts.existing ? '' : quoteHtml)
                    + '<textarea placeholder="Write a comment\u2026" rows="3"></textarea>'
                    + '<div class="rvw-popover-actions">'
                    + '<button type="button" class="button button--sm button--ghost" data-cancel>Cancel</button>'
                    + '<button type="button" class="button button--sm" data-save>Save</button></div>';
                document.body.appendChild(pop);
                // Position near selection, flip above if near bottom of viewport.
                const popW = 280;
                const popH = 180;
                let x = opts.x != null ? opts.x : window.innerWidth / 2;
                let y = opts.y != null ? opts.y : window.innerHeight / 2;
                if (y + popH > window.innerHeight - 12) {
                    y = Math.max(12, (opts.y != null ? opts.y : y) - popH - 24);
                }
                x = Math.min(Math.max(12, x), window.innerWidth - popW - 12);
                y = Math.min(Math.max(12, y), window.innerHeight - popH - 12);
                pop.style.left = x + 'px';
                pop.style.top = y + 'px';
                requestAnimationFrame(() => pop.classList.add('is-open'));
                const ta = pop.querySelector('textarea');
                if (opts.existing) ta.value = opts.existing.comment;
                // Defer focus so the pending highlight paints before the caret moves.
                window.setTimeout(() => ta.focus(), 30);
                pop.querySelector('[data-cancel]').addEventListener('click', () => closeComposer());
                pop.querySelector('[data-save]').addEventListener('click', () => {
                    const val = ta.value.trim();
                    if (!val) { ta.focus(); return; }
                    const payload = { comment: val };
                    if (opts.existing) {
                        payload.annotation_id = opts.existing.id;
                    } else {
                        payload.anchor_type = opts.anchor_type;
                        if (opts.anchor_type === 'text') {
                            payload.range_start = opts.start;
                            payload.range_end = opts.end;
                            payload.quote = opts.quote;
                        } else if (opts.anchor_type === 'image') {
                            payload.pos_x = opts.pos_x;
                            payload.pos_y = opts.pos_y;
                        }
                    }
                    const saveBtn = pop.querySelector('[data-save]');
                    if (saveBtn) {
                        saveBtn.disabled = true;
                        saveBtn.textContent = 'Saving…';
                    }
                    saveAnnotation(payload).then(res => {
                        closeComposer();
                        if (!res || !res.ok) {
                            // Re-show pending if save failed so the teacher doesn't lose place.
                            if (opts.anchor_type === 'text') {
                                openComposer(opts);
                            }
                        }
                    });
                });
            }

            if (canAnnotate && docEl) {
                docEl.addEventListener('mouseup', () => {
                    window.setTimeout(() => {
                        if (document.querySelector('.rvw-popover')) return;
                        const surface = getAnnotateSurface(shell);
                        if (!surface) return;
                        const sel = window.getSelection();
                        if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;
                        const range = sel.getRangeAt(0);
                        if (!surface.contains(range.startContainer) || !surface.contains(range.endContainer)) return;
                        // Ignore tiny / accidental clicks with no real drag.
                        const quote = sel.toString().replace(/\s+/g, ' ').trim();
                        if (quote.length < 2) return;
                        const start = offsetInNode(surface, range.startContainer, range.startOffset);
                        const end = offsetInNode(surface, range.endContainer, range.endOffset);
                        if (end <= start) return;
                        const rect = range.getBoundingClientRect();
                        // Keep visual selection via pending highlight; clear native selection
                        // so focus can move to the composer without losing the cue.
                        sel.removeAllRanges();
                        openComposer({
                            anchor_type: 'text',
                            start,
                            end,
                            quote,
                            x: rect.left,
                            y: rect.bottom + 10,
                        });
                    }, 10);
                });
            }

            shell.addEventListener('rvw-preview-loaded', () => render());
            shell._rvwRender = render;

            if (canAnnotate && imageWrap) {
                const img = imageWrap.querySelector('img');
                if (img) {
                    img.addEventListener('click', e => {
                        const rect = img.getBoundingClientRect();
                        const px = ((e.clientX - rect.left) / rect.width) * 100;
                        const py = ((e.clientY - rect.top) / rect.height) * 100;
                        openComposer({ anchor_type: 'image', pos_x: px.toFixed(2), pos_y: py.toFixed(2), x: e.clientX, y: e.clientY + 8 });
                    });
                }
            }

            render();
        });
    })();

    // ── Common setup ──────────────────────────────────────────────────────────
    const pageData = document.getElementById('portal-page-data');
    if (!pageData || pageData.dataset.canManage !== '1') return;

    // ── Folder lock toggle (AJAX, no page reload) ─────────────────────────────
    document.querySelectorAll('.folder-lock-toggle[data-folder-id]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation(); // don't open/close the <details>
            if (btn.classList.contains('is-animating')) return;

            const folderId   = btn.dataset.folderId;
            const folderRow  = btn.closest('.folder-row');
            const folderCard = folderRow?.querySelector('details.folder-card');
            const folderInfo = folderRow?.querySelector('.folder-info');
            const settingsCb = folderRow?.querySelector('input[name="locked"]');
            const willLock   = !btn.classList.contains('is-locked');

            // Animate button
            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            // Optimistic UI update
            btn.classList.toggle('is-locked', willLock);
            btn.title = willLock ? 'Unlock folder' : 'Lock folder';
            btn.setAttribute('aria-label', btn.title);
            if (folderCard) folderCard.classList.toggle('folder-card--locked', willLock);
            if (settingsCb) settingsCb.checked = willLock;

            // Animate the lock badge
            const existingBadge = folderInfo?.querySelector('.folder-lock-badge');
            if (willLock && !existingBadge && folderInfo) {
                const badge = document.createElement('span');
                badge.className = 'folder-lock-badge';
                badge.textContent = 'Locked';
                badge.style.animation = 'badge-pop-in 250ms ease forwards';
                folderInfo.appendChild(badge);
            } else if (!willLock && existingBadge) {
                existingBadge.style.animation = 'badge-pop-out 180ms ease forwards';
                existingBadge.addEventListener('animationend', () => existingBadge.remove(), { once: true });
            }

            // AJAX
            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_folder_lock', folder_id: folderId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    // Revert on failure
                    btn.classList.toggle('is-locked', !willLock);
                    btn.title = !willLock ? 'Unlock folder' : 'Lock folder';
                    btn.setAttribute('aria-label', btn.title);
                    if (folderCard) folderCard.classList.toggle('folder-card--locked', !willLock);
                    if (settingsCb) settingsCb.checked = !willLock;
                }
            }).catch(() => {
                btn.classList.toggle('is-locked', !willLock);
                btn.title = !willLock ? 'Unlock folder' : 'Lock folder';
                btn.setAttribute('aria-label', btn.title);
                if (folderCard) folderCard.classList.toggle('folder-card--locked', !willLock);
                if (settingsCb) settingsCb.checked = !willLock;
            });
        });
    });

    // ── Item lock toggle ──────────────────────────────────────────────────────
    document.querySelectorAll('.folder-lock-toggle[data-item-id]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('is-animating')) return;

            const itemId   = btn.dataset.itemId;
            const itemRow  = btn.closest('.folder-item');
            const willLock = !btn.classList.contains('is-locked');

            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            btn.classList.toggle('is-locked', willLock);
            btn.title = willLock ? 'Unlock item' : 'Lock item';
            btn.setAttribute('aria-label', btn.title);
            if (itemRow) itemRow.classList.toggle('folder-item--locked', willLock);

            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_item_lock', item_id: itemId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.classList.toggle('is-locked', !willLock);
                    btn.title = !willLock ? 'Unlock item' : 'Lock item';
                    btn.setAttribute('aria-label', btn.title);
                    if (itemRow) itemRow.classList.toggle('folder-item--locked', !willLock);
                }
            }).catch(() => {
                btn.classList.toggle('is-locked', !willLock);
                btn.title = !willLock ? 'Unlock item' : 'Lock item';
                btn.setAttribute('aria-label', btn.title);
                if (itemRow) itemRow.classList.toggle('folder-item--locked', !willLock);
            });
        });
    });

    // ── Download permission toggle ────────────────────────────────────────────
    document.querySelectorAll('.btn-dl-toggle').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('is-animating')) return;

            const itemId    = btn.dataset.itemId;
            const willEnable = !btn.classList.contains('is-enabled');

            btn.classList.add('is-animating');
            btn.addEventListener('animationend', () => btn.classList.remove('is-animating'), { once: true });

            btn.classList.toggle('is-enabled', willEnable);
            btn.title = willEnable
                ? 'Students can download — click to disable'
                : 'Students cannot download — click to enable';

            const settingsCb = btn.closest('.folder-item')?.querySelector('input[name="allow_download"]');
            if (settingsCb) settingsCb.checked = willEnable;

            const slug  = pageData.dataset.slug;
            const token = pageData.dataset.csrf;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'toggle_download', item_id: itemId }),
            }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.classList.toggle('is-enabled', !willEnable);
                    btn.title = !willEnable
                        ? 'Students can download — click to disable'
                        : 'Students cannot download — click to enable';
                    if (settingsCb) settingsCb.checked = !willEnable;
                }
            }).catch(() => {
                btn.classList.toggle('is-enabled', !willEnable);
                btn.title = !willEnable
                    ? 'Students can download — click to disable'
                    : 'Students cannot download — click to enable';
                if (settingsCb) settingsCb.checked = !willEnable;
            });
        });
    });

    // ── Pointer-drag to reorder folders and items (not HTML5 DnD) ───────────
    const stack     = document.getElementById('folder-stack');
    const modeBadge = document.getElementById('reorder-mode-badge');
    const modeDone  = document.getElementById('reorder-mode-done');
    if (stack) {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let reorderMode = false;
    let activePointerId = null;

    function isArrowReorderViewport() {
        return window.matchMedia('(max-width: 900px)').matches;
    }

    function enterReorderMode() {
        if (isArrowReorderViewport()) return;
        reorderMode = true;
        stack.classList.add('folder-stack--reordering');
        if (modeBadge) modeBadge.hidden = false;
    }

    function exitReorderMode() {
        reorderMode = false;
        activePointerId = null;
        stack.classList.remove('folder-stack--reordering');
        const slot = document.querySelector('.folder-reorder-slot, .folder-item-reorder-slot');
        document.querySelectorAll('.folder-row.is-dragging, .folder-item.is-dragging').forEach((el) => {
            if (slot && slot.parentNode) {
                slot.parentNode.insertBefore(el, slot);
            }
            el.classList.remove('is-dragging');
            el.style.cssText = '';
        });
        document.querySelectorAll('.folder-reorder-slot, .folder-item-reorder-slot').forEach((el) => el.remove());
        if (modeBadge) modeBadge.hidden = true;
    }

    function flipSiblings(parent, apply) {
        if (reduceMotion) {
            apply();
            return;
        }
        const nodes = Array.from(parent.children);
        const first = new Map(nodes.map((n) => [n, n.getBoundingClientRect()]));
        apply();
        nodes.forEach((n) => {
            const prev = first.get(n);
            if (!prev || !n.isConnected) return;
            const last = n.getBoundingClientRect();
            const dy = prev.top - last.top;
            if (Math.abs(dy) < 1) return;
            n.animate(
                [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'none' }],
                { duration: 220, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
            );
        });
    }

    function flipMove(el, apply) {
        flipSiblings(el.parentNode, apply);
    }

    function bindHandle(handle, kind) {
        handle.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
        handle.addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || isArrowReorderViewport()) return;
            const row = handle.closest('.folder-row');
            const item = handle.closest('.folder-item');
            const moving = kind === 'folder' ? row : item;
            const parent = kind === 'folder' ? stack : item?.closest('.folder-items');
            if (!moving || !parent) return;
            e.preventDefault();
            e.stopPropagation();
            enterReorderMode();
            activePointerId = e.pointerId;

            const rect = moving.getBoundingClientRect();
            const offsetX = e.clientX - rect.left;
            const offsetY = e.clientY - rect.top;
            const slot = document.createElement('div');
            slot.className = kind === 'folder' ? 'folder-reorder-slot' : 'folder-item-reorder-slot';
            slot.style.height = Math.round(rect.height) + 'px';
            parent.insertBefore(slot, moving.nextSibling);

            document.body.appendChild(moving);
            moving.classList.add('is-dragging');
            moving.style.width = rect.width + 'px';
            moving.style.left = rect.left + 'px';
            moving.style.top = rect.top + 'px';
            try { handle.setPointerCapture(e.pointerId); } catch (_) { /* ignore */ }

            const follow = (ev) => {
                moving.style.left = (ev.clientX - offsetX) + 'px';
                moving.style.top = (ev.clientY - offsetY) + 'px';
            };
            const moveSlotTo = (list, refNode) => {
                if (!list) return;
                if (refNode === slot) refNode = slot.nextElementSibling;
                if (refNode == null) {
                    if (slot.parentNode === list && list.lastElementChild === slot) return;
                    list.appendChild(slot);
                    return;
                }
                if (slot.parentNode === list && slot.nextElementSibling === refNode) return;
                list.insertBefore(slot, refNode);
            };
            const listAtPoint = (clientX, clientY) => {
                const lists = Array.from(stack.querySelectorAll('.folder-items'));
                for (const list of lists) {
                    const r = list.getBoundingClientRect();
                    if (clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom) {
                        return list;
                    }
                }
                return null;
            };
            const refFromY = (list, clientY) => {
                const items = Array.from(list.children).filter(
                    (n) => n !== slot && n !== moving && n.classList.contains('folder-item')
                );
                const slack = 8;
                for (const item of items) {
                    const r = item.getBoundingClientRect();
                    if (clientY < r.top + r.height / 2 - slack) return item;
                }
                return null;
            };
            const placeSlot = (ev) => {
                if (kind === 'folder') {
                    const others = Array.from(stack.children).filter(
                        (n) => n !== moving && n !== slot && n.classList.contains('folder-row')
                    );
                    for (const other of others) {
                        const r = other.getBoundingClientRect();
                        if (ev.clientY < r.top + r.height / 2) {
                            moveSlotTo(stack, other);
                            return;
                        }
                    }
                    moveSlotTo(stack, null);
                    return;
                }
                const list = listAtPoint(ev.clientX, ev.clientY) || slot.parentNode;
                if (!list || !list.classList || !list.classList.contains('folder-items')) return;
                moveSlotTo(list, refFromY(list, ev.clientY));
            };

            const onMove = (ev) => {
                if (ev.pointerId !== activePointerId) return;
                follow(ev);
                placeSlot(ev);
            };
            const onUp = (ev) => {
                if (ev.pointerId !== activePointerId) return;
                activePointerId = null;
                follow(ev);
                const from = moving.getBoundingClientRect();
                const dest = slot.parentNode || parent;
                dest.insertBefore(moving, slot);
                slot.remove();
                moving.classList.remove('is-dragging');
                moving.style.cssText = '';
                if (!reduceMotion) {
                    const to = moving.getBoundingClientRect();
                    const dx = from.left - to.left;
                    const dy = from.top - to.top;
                    if (Math.abs(dx) > 1 || Math.abs(dy) > 1) {
                        moving.animate(
                            [{ transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(1.02)' }, { transform: 'none' }],
                            { duration: 260, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
                        );
                    }
                }
                try { handle.releasePointerCapture(ev.pointerId); } catch (_) { /* ignore */ }
                handle.removeEventListener('pointermove', onMove);
                handle.removeEventListener('pointerup', onUp);
                handle.removeEventListener('pointercancel', onUp);
                if (kind === 'folder') saveFolderOrder();
                else saveItemPosition(moving);
                syncMoveButtons();
            };
            handle.addEventListener('pointermove', onMove);
            handle.addEventListener('pointerup', onUp);
            handle.addEventListener('pointercancel', onUp);
        });
    }

    stack.querySelectorAll('.folder-drag-handle').forEach((h) => bindHandle(h, 'folder'));
    stack.querySelectorAll('.item-drag-handle').forEach((h) => bindHandle(h, 'item'));

    modeDone?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        exitReorderMode();
    });

    document.addEventListener('click', (e) => {
        if (!reorderMode || activePointerId !== null) return;
        if (stack.contains(e.target) || modeBadge?.contains(e.target)) return;
        exitReorderMode();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && reorderMode) exitReorderMode();
    });

    function saveFolderOrder() {
        const ids   = Array.from(stack.querySelectorAll(':scope > .folder-row')).map(r => r.dataset.folderId);
        const slug  = pageData.dataset.slug;
        const token = pageData.dataset.csrf;
        fetch('course.php?course=' + encodeURIComponent(slug), {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({ _token: token, action: 'reorder_folders', order: JSON.stringify(ids) }),
        }).catch(() => {});
    }

    function saveItemPosition(itemEl) {
        const newFolderRow    = itemEl.closest('.folder-row');
        if (!newFolderRow) return;
        const newFolderId      = newFolderRow.dataset.folderId;
        const originalFolderId = itemEl.dataset.folderId;
        const itemId           = itemEl.dataset.itemId;
        const slug             = pageData.dataset.slug;
        const token            = pageData.dataset.csrf;

        if (newFolderId !== originalFolderId) {
            // Moved to a different folder
            itemEl.dataset.folderId = newFolderId;
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'move_item', item_id: itemId, folder_id: newFolderId }),
            }).catch(() => {});
        } else {
            // Reordered within the same folder
            const folderItems = itemEl.closest('.folder-items');
            if (!folderItems) return;
            const ids = Array.from(folderItems.querySelectorAll('.folder-item')).map(i => i.dataset.itemId);
            fetch('course.php?course=' + encodeURIComponent(slug), {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ _token: token, action: 'reorder_items', folder_id: newFolderId, order: JSON.stringify(ids) }),
            }).catch(() => {});
        }
    }

    function syncMoveButtons() {
        const rows = Array.from(stack.querySelectorAll(':scope > .folder-row'));
        rows.forEach((row, i) => {
            const up = row.querySelector('[data-course-move="folder"] [data-course-move-dir="up"]');
            const down = row.querySelector('[data-course-move="folder"] [data-course-move-dir="down"]');
            if (up) up.disabled = i === 0;
            if (down) down.disabled = i === rows.length - 1;
        });
        stack.querySelectorAll('.folder-items').forEach((list) => {
            const items = Array.from(list.querySelectorAll(':scope > .folder-item'));
            items.forEach((item, i) => {
                const up = item.querySelector('[data-course-move="item"] [data-course-move-dir="up"]');
                const down = item.querySelector('[data-course-move="item"] [data-course-move-dir="down"]');
                if (up) up.disabled = i === 0;
                if (down) down.disabled = i === items.length - 1;
            });
        });
    }

    stack.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-course-move-dir]');
        if (!btn || !stack.contains(btn) || btn.disabled) return;
        e.preventDefault();
        e.stopPropagation();

        const dir = btn.getAttribute('data-course-move-dir');
        const folderRow = btn.closest('.folder-row');
        const itemEl = btn.closest('.folder-item');
        const moveKind = btn.closest('[data-course-move]')?.getAttribute('data-course-move');

        if (moveKind === 'folder' && folderRow) {
            const rows = Array.from(stack.querySelectorAll(':scope > .folder-row'));
            const idx = rows.indexOf(folderRow);
            const swap = dir === 'up' ? idx - 1 : idx + 1;
            if (idx < 0 || swap < 0 || swap >= rows.length) return;
            flipMove(folderRow, () => {
                if (dir === 'up') {
                    stack.insertBefore(folderRow, rows[swap]);
                } else {
                    stack.insertBefore(rows[swap], folderRow);
                }
            });
            saveFolderOrder();
            syncMoveButtons();
            btn.blur();
            return;
        }

        if (moveKind === 'item' && itemEl) {
            const list = itemEl.closest('.folder-items');
            if (!list) return;
            const items = Array.from(list.querySelectorAll(':scope > .folder-item'));
            const idx = items.indexOf(itemEl);
            const swap = dir === 'up' ? idx - 1 : idx + 1;
            if (idx < 0 || swap < 0 || swap >= items.length) return;
            flipMove(itemEl, () => {
                if (dir === 'up') {
                    list.insertBefore(itemEl, items[swap]);
                } else {
                    list.insertBefore(items[swap], itemEl);
                }
            });
            saveItemPosition(itemEl);
            syncMoveButtons();
            btn.blur();
        }
    }, true);

    syncMoveButtons();
    }
})();

// Guard discussion reply/topic forms against double-submit (double-click spam).
(function () {
    const guarded = new Set(['post_reply', 'create_topic']);
    document.querySelectorAll('form').forEach((form) => {
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || !guarded.has(actionInput.value)) return;

        form.addEventListener('submit', (e) => {
            if (form.dataset.submitting === '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            form.dataset.submitting = '1';
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                // Tiny delay keeps the disabled state from flashing if the
                // browser cancels navigation for any reason.
                window.setTimeout(() => {
                    if (document.body.contains(form) && form.dataset.submitting === '1') {
                        btn.disabled = false;
                        delete form.dataset.submitting;
                    }
                }, 4000);
            }
        }, true);
    });
})();
