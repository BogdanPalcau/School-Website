/**
 * Course upload dropzone — OS file drag & drop.
 * Loaded as its own file so a later script error cannot block it.
 */
(function () {
  if (window.__portalUploadDnd) return;
  window.__portalUploadDnd = true;
  document.documentElement.setAttribute('data-upload-dnd', '1');

  function assignFile(input, file) {
    if (!input || !file) return false;
    try {
      var dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      if (!input.files || !input.files.length) return false;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    } catch (e) {
      return false;
    }
  }

  function openModalZone() {
    return document.querySelector('.sub-slot-overlay--in [data-upload-dropzone]');
  }

  function zoneFromEvent(e) {
    var x = e.clientX;
    var y = e.clientY;
    var zones = document.querySelectorAll('[data-upload-dropzone]');
    for (var i = 0; i < zones.length; i++) {
      var zone = zones[i];
      var overlay = zone.closest('.sub-slot-overlay');
      if (overlay) {
        if (overlay.hasAttribute('hidden')) continue;
        if (!overlay.classList.contains('sub-slot-overlay--in')) continue;
      }
      var r = zone.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) continue;
      var pad = 32;
      if (x >= r.left - pad && x <= r.right + pad && y >= r.top - pad && y <= r.bottom + pad) {
        return zone;
      }
    }
    return openModalZone();
  }

  var active = null;

  function setActive(zone) {
    if (active && active !== zone) active.classList.remove('is-dragover');
    active = zone || null;
    if (active) active.classList.add('is-dragover');
  }

  function onOver(e) {
    // Always cancel default — this is what removes Chrome's X cursor.
    e.preventDefault();
    try {
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
    } catch (err) { /* ignore */ }
    var zone = zoneFromEvent(e);
    setActive(zone);
  }

  function onDrop(e) {
    e.preventDefault();
    var zone = zoneFromEvent(e) || active || openModalZone();
    setActive(null);
    if (!zone) return;
    e.stopPropagation();

    var input = zone.querySelector('[data-upload-input]');
    var files = e.dataTransfer && e.dataTransfer.files;
    var file = files && files[0];
    if (!file || !input) return;

    input.removeAttribute('accept');
    if (!assignFile(input, file) && files) {
      try {
        input.files = files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (err2) { /* ignore */ }
    }
  }

  function onEnd() {
    setActive(null);
  }

  // Capture phase on both document and window.
  document.addEventListener('dragenter', onOver, true);
  document.addEventListener('dragover', onOver, true);
  document.addEventListener('drop', onDrop, true);
  window.addEventListener('dragenter', onOver, true);
  window.addEventListener('dragover', onOver, true);
  window.addEventListener('drop', onDrop, true);
  window.addEventListener('dragend', onEnd, true);
  window.addEventListener('blur', onEnd);

  // Public hook for inline HTML handlers if needed.
  window.portalUploadDrop = onDrop;
  window.portalUploadDragOver = onOver;
})();
