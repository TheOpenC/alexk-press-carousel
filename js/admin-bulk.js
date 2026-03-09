/**
 * js/admin-bulk.js
 * AlexK Press Carousel — bulk UI for Media Library grid view.
 *
 * Magenta dot top-right  = in press carousel (single item)
 * Magenta + orange dot   = in press carousel AND in a group
 * Green dot top-left     = in image carousel (handled by carousel plugin)
 */

(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ---------------------------
  // Reload on exit single media view so dot updates
  // ---------------------------
  function isInSingleMediaView() {
    return !!document.querySelector('.media-modal .attachment-details');
  }

  function bindReloadOnExitSingleViewOnce() {
    if (document.__alexkPressReloadBound) return;
    document.__alexkPressReloadBound = true;

    document.addEventListener('click', (e) => {
      const el = e.target instanceof HTMLElement ? e.target : null;
      if (!el) return;
      if (el.closest('.media-modal-close') && isInSingleMediaView()) {
        setTimeout(() => window.location.reload(), 50);
      }
    }, true);

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      if (isInSingleMediaView()) window.location.reload();
    }, true);
  }

  // ---------------------------
  // Persist notices across reload
  // ---------------------------
  const NOTICE_KEY = 'alexk_press_notice_after_reload';

  function queueNotice(message, type = 'success') {
    try { sessionStorage.setItem(NOTICE_KEY, JSON.stringify({ message, type })); } catch {}
  }

  function showQueuedNotice() {
    try {
      const raw = sessionStorage.getItem(NOTICE_KEY);
      if (!raw) return;
      sessionStorage.removeItem(NOTICE_KEY);
      const parsed = JSON.parse(raw);
      if (parsed?.message) showWpNotice(parsed.message, parsed.type || 'success');
    } catch {}
  }

  function showWpNotice(message, type = 'success', ttlMs = 0) {
    const wrap = document.querySelector('.wrap');
    if (!wrap) return;

    const existing = wrap.querySelector('.notice.alexk-press-notice');
    if (existing) existing.remove();

    const notice = document.createElement('div');
    notice.className = `notice notice-${type} is-dismissible alexk-press-notice`;

    const p = document.createElement('p');
    p.textContent = message;
    notice.appendChild(p);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'notice-dismiss';
    btn.addEventListener('click', () => notice.remove());
    notice.appendChild(btn);

    wrap.insertBefore(notice, wrap.firstChild);

    if (ttlMs > 0) setTimeout(() => notice.remove(), ttlMs);
  }

  // ---------------------------
  // AJAX helpers
  // ---------------------------
  function ajax(action, data) {
    const body = new URLSearchParams({ action, ...data });
    return fetch(window.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(r => r.json());
  }

  function getStatus() {
    return fetch(`${window.ajaxurl}?action=alexk_press_bulk_job_status`, {
      credentials: 'same-origin',
    }).then(r => r.json());
  }

  // ---------------------------
  // Datalist: populate group slugs
  // ---------------------------
  function populateGroupsDatalist() {
    const datalist = document.getElementById('alexk-press-groups-datalist');
    if (!datalist || datalist.dataset.loaded) return;
    datalist.dataset.loaded = '1';

    fetch(`${window.ajaxurl}?action=alexk_press_get_groups&nonce=${window.ALEXK_PRESS_BULK?.groups_nonce || ''}`, {
      credentials: 'same-origin',
    }).then(r => r.json()).then(res => {
      if (!res.success) return;
      (res.data || []).forEach(slug => {
        const opt = document.createElement('option');
        opt.value = slug;
        datalist.appendChild(opt);
      });
    }).catch(() => {});
  }

  // Populate datalist when media modal opens
  document.addEventListener('click', (e) => {
    if (e.target?.closest?.('.attachment')) {
      setTimeout(populateGroupsDatalist, 400);
    }
  });

  // ---------------------------
  // Dot indicator for grid tiles
  // ---------------------------
  function applyPressDotToTile(tile, inPress, hasGroup) {
    if (!tile) return;

    // Magenta dot (top-right) — present if in press
    if (inPress) {
      tile.classList.add('alexk-in-press');
      if (!tile.querySelector('.alexk-press-dot')) {
        const dot = document.createElement('span');
        dot.className = 'alexk-press-dot';
        dot.setAttribute('aria-hidden', 'true');
        tile.appendChild(dot);
      }
    } else {
      tile.classList.remove('alexk-in-press');
      const dot = tile.querySelector('.alexk-press-dot');
      if (dot) dot.remove();
    }

    // Orange dot (bottom-right) — present if in press AND has group
    if (inPress && hasGroup) {
      tile.classList.add('alexk-in-press-group');
      if (!tile.querySelector('.alexk-press-group-dot')) {
        const dot = document.createElement('span');
        dot.className = 'alexk-press-group-dot';
        dot.setAttribute('aria-hidden', 'true');
        tile.appendChild(dot);
      }
    } else {
      tile.classList.remove('alexk-in-press-group');
      const dot = tile.querySelector('.alexk-press-group-dot');
      if (dot) dot.remove();
    }
  }

  function patchWpMediaAttachmentRender() {
    const Attachment = window.wp?.media?.view?.Attachment;
    if (!Attachment) return false;
    const proto = Attachment.prototype;
    if (proto.__alexkPressPatched) return true;
    proto.__alexkPressPatched = true;
    const originalRender = proto.render;
    proto.render = function(...args) {
      const out = originalRender.apply(this, args);
      try {
        const inPress  = !!this.model?.get?.('alexk_in_press');
        const hasGroup = !!(this.model?.get?.('alexk_press_group'));
        applyPressDotToTile(this.el, inPress, hasGroup);
      } catch {}
      return out;
    };
    return true;
  }

  // ---------------------------
  // Bulk buttons
  // ---------------------------
  let bulkToolbar = null;

  function ensureBulkButtons() {
    if (bulkToolbar) return;

    const toolbar = document.querySelector('.media-toolbar-secondary');
    if (!toolbar) return;

    bulkToolbar = document.createElement('span');
    bulkToolbar.className = 'alexk-press-bulk-buttons';
    bulkToolbar.style.display = 'none';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.id = 'alexk-add-to-press';
    addBtn.className = 'button';
    addBtn.textContent = 'Add to press carousel';
    addBtn.addEventListener('click', () => handleBulkAction('add'));

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.id = 'alexk-remove-from-press';
    removeBtn.className = 'button';
    removeBtn.textContent = 'Remove from press carousel';
    removeBtn.addEventListener('click', () => handleBulkAction('remove'));

    bulkToolbar.appendChild(addBtn);
    bulkToolbar.appendChild(removeBtn);
    toolbar.appendChild(bulkToolbar);
  }

  function getSelectedIds() {
    return $$('.attachments .attachment.selected').map(el => {
      return parseInt(el.dataset.id, 10) || 0;
    }).filter(id => id > 0);
  }

  function handleBulkAction(mode) {
    const ids = getSelectedIds();
    if (!ids.length) return;

    const nonce = window.ALEXK_PRESS_BULK?.nonce ?? '';
    const action = mode === 'add' ? 'alexk_press_bulk_add' : 'alexk_press_bulk_remove';

    // Optimistic UI update (no group info available in bulk, so just magenta)
    $$('.attachments .attachment.selected').forEach(el => {
      applyPressDotToTile(el, mode === 'add', false);
    });

    ajax(action, { nonce, ids: ids.join(',') }).then(res => {
      if (!res.success) {
        showWpNotice('Press carousel: action failed. Please try again.', 'error');
        return;
      }
      const count = res.data?.updated ?? ids.length;
      const label = mode === 'add' ? 'Added' : 'Removed';
      queueNotice(`${label} ${count} item(s) ${mode === 'add' ? 'to' : 'from'} the press carousel.`);
      startPolling();
    });
  }

  // ---------------------------
  // Polling / HUD
  // ---------------------------
  let pollTimer = null;

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(poll, 1200);
    poll();
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function poll() {
    getStatus().then(res => {
      if (!res.success) return;
      const data = res.data;
      const pending = parseInt(data.pending, 10) || 0;
      const done    = parseInt(data.done, 10) || 0;

      updateHud(data);

      if (pending <= 0 && done > 0) {
        stopPolling();
        showQueuedNotice();
      }
    });
  }

  function updateHud(data) {
    let hud = document.getElementById('alexk-press-hud');
    if (!hud) {
      hud = document.createElement('div');
      hud.id = 'alexk-press-hud';
      Object.assign(hud.style, {
        position: 'fixed', bottom: '12px', right: '12px',
        zIndex: '999999',
        background: 'rgba(0,0,0,0.85)', color: '#fff',
        font: '12px/1.35 system-ui, sans-serif',
        padding: '10px 14px', borderRadius: '8px',
        maxWidth: '320px', whiteSpace: 'pre-wrap',
        boxShadow: '0 6px 20px rgba(0,0,0,0.25)',
      });
      document.body.appendChild(hud);
    }

    const pending = parseInt(data.pending, 10) || 0;
    const done    = parseInt(data.done, 10)    || 0;
    const total   = parseInt(data.total, 10)   || 0;
    const mode    = data.mode === 'remove' ? 'Removing' : 'Processing';
    const fn      = data.current_filename || data.last_completed_filename || '';

    if (pending <= 0 && done === 0) {
      hud.remove();
      return;
    }

    const lines = [
      `Press carousel — ${mode}`,
      `Progress: ${done} / ${total}`,
    ];
    if (fn) lines.push(`File: ${fn}`);
    if (pending <= 0) lines.push('✓ Done');

    hud.textContent = lines.join('\n');

    if (pending <= 0) {
      setTimeout(() => { if (hud.parentNode) hud.remove(); }, 3000);
    }
  }

  // ---------------------------
  // Wire into wp.media events
  // ---------------------------
  function wireMediaLibrary() {
    if (!patchWpMediaAttachmentRender()) {
      const mo = new MutationObserver(() => {
        try {
          if (patchWpMediaAttachmentRender()) mo.disconnect();
        } catch (e) {}
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    const syncBulkBar = () => {
      if (!bulkToolbar) return;
      const isBulk = !!document.querySelector('.media-toolbar .bulk-select-button.active, .media-toolbar .bulk-select .active');
      bulkToolbar.style.display = isBulk ? '' : 'none';
    };
    if (window.wp?.media?.frame) {
      wp.media.frame.on('content:activate', syncBulkBar);
    }
  }

  // ---------------------------
  // Boot
  // ---------------------------
  function boot() {
    showQueuedNotice();
    bindReloadOnExitSingleViewOnce();
    ensureBulkButtons();
    wireMediaLibrary();

    getStatus().then(res => {
      if (res.success && (parseInt(res.data?.pending, 10) || 0) > 0) {
        startPolling();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
