/**
 * Shared Quill setup for portal rich-text fields (.quill-editor[data-target]).
 * Syncs HTML into the matching hidden textarea on change and form submit.
 */
(function (global) {
    'use strict';

    // Distinct class-based fonts only (default/unset = portal sans).
    // Do not register extra sans lookalikes — they show up as duplicate "Normal"s.
    var FONT_WHITELIST = ['serif', 'monospace'];

    var TOOLBAR_OPTIONS = [
        [{ font: [false].concat(FONT_WHITELIST) }],
        [{ size: ['small', false, 'large', 'huge'] }],
        [{ header: [2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        [{ align: [] }],
        ['link', 'blockquote'],
        ['clean'],
    ];

    function registerFonts() {
        if (typeof Quill === 'undefined' || Quill.__portalFontsReady) {
            return;
        }
        var Font = Quill.import('formats/font');
        Font.whitelist = FONT_WHITELIST.slice();
        Quill.register(Font, true);
        Quill.__portalFontsReady = true;
    }

    function isEmptyHtml(html) {
        return !html || html === '<p><br></p>' || html === '<p></p>';
    }

    function initPortalQuill(root) {
        if (typeof Quill === 'undefined') {
            return;
        }

        registerFonts();

        var scope = root && root.querySelectorAll ? root : document;

        scope.querySelectorAll('.quill-editor[data-target]').forEach(function (container) {
            if (container.dataset.quillReady === '1') {
                return;
            }

            var targetId = container.dataset.target;
            var textarea = document.getElementById(targetId);
            if (!textarea) {
                return;
            }

            var wrap = container.closest('.quill-wrap') || container.parentElement;

            var quill = new Quill(container, {
                theme: 'snow',
                placeholder: container.dataset.placeholder || 'Write something…',
                modules: { toolbar: TOOLBAR_OPTIONS },
            });

            // Keep selection while using toolbar (buttons / pickers).
            // Quill already does this for many controls; reinforce for pickers.
            var toolbarEl = wrap ? wrap.querySelector('.ql-toolbar') : null;
            if (toolbarEl) {
                toolbarEl.addEventListener('mousedown', function (e) {
                    if (e.target.closest('input, textarea')) {
                        return;
                    }
                    e.preventDefault();
                });
            }

            function sync() {
                var html = quill.root.innerHTML;
                textarea.value = isEmptyHtml(html) ? '' : html;
            }

            quill.on('text-change', sync);

            if (textarea.value.trim() !== '') {
                var delta = quill.clipboard.convert({ html: textarea.value });
                quill.setContents(delta, 'silent');
                sync();
            }

            var form = textarea.closest('form');
            if (form) {
                form.addEventListener('submit', sync);
            }

            container.dataset.quillReady = '1';
        });
    }

    global.initPortalQuill = initPortalQuill;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initPortalQuill(document);
        });
    } else {
        initPortalQuill(document);
    }
})(window);
