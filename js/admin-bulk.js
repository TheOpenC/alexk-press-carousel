/* js/admin-bulk.js (RESET + AMEND)
 * AlexK Carousel — minimal, readable bulk UI
 *
 * Keeps the "green dot = in press carousel" indicator and adds minimal bulk Add/Remove logic:
 * - Green dot is visible in normal state AND bulk select state (only for included items)
 * - Add/Remove buttons are ONLY visible in Bulk Select mode
 * - Buttons only show when the action is actually possible for the current selection
 * - Bulk actions update BOTH:
 *     - server via AJAX (existing PHP handlers)
 *     - client model so the checkbox UI reflects state immediately
 * - After action, auto-exit Bulk Select (returns to normal library)
 *
 * Assumes PHP adds: response['alexk_in_press'] via wp_prepare_attachment_for_js (already in your plugin).
 */

(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ---------------------------
  // Page refresh when exiting single media view so the dot updates.
  // ---------------------------
  function isInSingleMediaView() {
    // Attachment details view renders this panel inside the media modal
    return !!document.querySelector(".media-modal .attachment-details");
  }

  function bindReloadOnExitSingleViewOnce() {
    if (document.__alexkPressReloadBound) return;
    document.__alexkPressReloadBound = true;

    // Click the X (close modal)
    document.addEventListener(
      "click",
      (e) => {
        const el = e.target instanceof HTMLElement ? e.target : null;
        if (!el) return;

        const closeBtn = el.closest(".media-modal-close");
        if (!closeBtn) return;

        if (isInSingleMediaView()) {
          // Let WP close the modal, then refresh to force grid state to reflect reality
          setTimeout(() => window.location.reload(), 50);
        }
      },
      true
    );

    // Escape key closes the modal too
    document.addEventListener(
      "keydown",
      (e) => {
        if (e.key !== "Escape") return;
        if (isInSingleMediaView()) {
          window.location.reload();
        }
      },
      true
    );
  }
    
  
  // ---------------------------
  // Persist notice across reload (sessionStorage)
  // ---------------------------
  const ALEXK_NOTICE_KEY = "alexk_press_notice_after_reload";

  function queueNoticeForAfterReload(message, type = "success") {
    try {
      sessionStorage.setItem(ALEXK_NOTICE_KEY, JSON.stringify({ message, type }));
    } catch {}
  }

  function showQueuedNoticeIfAny() {
    try {
      const raw = sessionStorage.getItem(ALEXK_NOTICE_KEY);
      if (!raw) return;
      sessionStorage.removeItem(ALEXK_NOTICE_KEY);

      const parsed = JSON.parse(raw);
      if (parsed?.message) showWpNotice(parsed.message, parsed.type || "success");
    } catch {}
  }


    // ---------------------------
  // WP-style admin notice banner (like list view)
  // ---------------------------
 function showWpNotice(message, type = "success", ttlMs = 0) {
  // type: "success" | "error" | "warning" | "info"
  const wrap = document.querySelector(".wrap");
  if (!wrap) return;

  // Remove existing AlexK notice if present
  const existing = wrap.querySelector(".notice.alexk-press-notice");
  if (existing) existing.remove();

  const notice = document.createElement("div");
  notice.className = `notice notice-${type} is-dismissible alexk-press-notice`;
  notice.innerHTML = `<p>${message}</p>`;

  // Insert right under the H1 / header area
  const h1 = wrap.querySelector("h1");
  if (h1 && h1.parentNode) {
    h1.parentNode.insertBefore(notice, h1.nextSibling);
  } else {
    wrap.insertBefore(notice, wrap.firstChild);
  }

  // Dismiss button
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "notice-dismiss";
  btn.innerHTML = `<span class="screen-reader-text">Dismiss this notice.</span>`;
  btn.addEventListener("click", () => notice.remove());
  notice.appendChild(btn);

  // Optional auto-dismiss (used for “Queued…”)
  if (Number.isFinite(ttlMs) && ttlMs > 0) {
    window.setTimeout(() => {
      if (notice && notice.isConnected) notice.remove();
    }, ttlMs);
  }
}

// Combined press status notice (last-added + count in one magenta banner)
function ensurePressStatusNotice() {
  const wrap = document.querySelector(".wrap");
  if (!wrap) return null;
  let el = wrap.querySelector(".alexk-press-status-notice");
  if (el) return el;
  el = document.createElement("div");
  el.className = "notice is-dismissible alexk-press-status-notice";
  el.style.cssText = "border-left-color:#c2185b;";
  el.innerHTML = `<p>` +
    `<strong class="alexk-press-lastdone-label" style="display:none"></strong>` +
    `<span class="alexk-press-lastdone-text" style="display:none"></span>` +
    `<span class="alexk-press-sep" style="display:none"><br></span>` +
    `<strong>Total press items:</strong> <span class="alexk-press-count-text"></span>` +
    `</p>`;
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "notice-dismiss";
  btn.innerHTML = `<span class="screen-reader-text">Dismiss this notice.</span>`;
  btn.addEventListener("click", () => {
    try { localStorage.removeItem(LAST_DONE_KEY); } catch {}
    el.remove();
  });
  el.appendChild(btn);
  const progress = wrap.querySelector(".notice.alexk-press-progress");
  if (progress && progress.parentNode) progress.parentNode.insertBefore(el, progress);
  else {
    const h1 = wrap.querySelector("h1");
    if (h1 && h1.parentNode) h1.parentNode.insertBefore(el, h1.nextSibling);
    else wrap.insertBefore(el, wrap.firstChild);
  }
  return el;
}

function showPressIncludedCountNotice() {
  const n = Number(window?.ALEXK_PRESS_BULK?.included_count ?? NaN);
  if (!Number.isFinite(n)) return;
  const el = ensurePressStatusNotice();
  if (!el) return;
  const countEl = el.querySelector(".alexk-press-count-text");
  if (countEl) countEl.textContent = `${n} ${n === 1 ? "item" : "items"}`;
}


  // UI progress element
  function ensureProgressNoticeEl() {
  const wrap = document.querySelector(".wrap");
  if (!wrap) return null;

  let el = wrap.querySelector(".notice.alexk-press-progress");
  if (el) return el;

  el = document.createElement("div");
  el.className = "notice notice-info alexk-press-progress";
  el.innerHTML = `<p><strong>Press carousel job:</strong> <span class="alexk-progress-text">Starting…</span></p>`;

  const h1 = wrap.querySelector("h1");
  if (h1 && h1.parentNode) h1.parentNode.insertBefore(el, h1.nextSibling);
  else wrap.insertBefore(el, wrap.firstChild);

  return el;
}

function ensureCornerHudEl() {
  let el = document.querySelector("#alexk-press-corner-hud");
  if (el) return el;

  el = document.createElement("div");
  el.id = "alexk-corner-hud";
  el.style.position = "fixed";
  el.style.right = "16px";
  el.style.bottom = "16px";
  el.style.zIndex = "999999";
  el.style.background = "#fff";
  el.style.border = "1px solid rgba(0,0,0,0.15)";
  el.style.borderRadius = "8px";
  el.style.boxShadow = "0 8px 24px rgba(0,0,0,0.12)";
  el.style.padding = "10px 12px";
  el.style.fontSize = "13px";
  el.style.lineHeight = "1.3";
  el.style.maxWidth = "360px";
  el.style.display = "none";

  el.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:10px;">
      <div style="flex:1;">
        <div style="font-weight:600;margin-bottom:2px;">Press carousel job</div>
        <div class="alexk-press-hud-text">Starting…</div>
      </div>
      <button type="button" class="alexk-press-hud-x" aria-label="Dismiss" style="border:0;background:transparent;cursor:pointer;font-size:16px;line-height:1;">×</button>
    </div>
  `;

  el.querySelector(".alexk-press-hud-x")?.addEventListener("click", () => {
    el.style.display = "none";
  });

  document.body.appendChild(el);
  return el;
}

function renderBulkProgress(data) {
  // ✅ Corner HUD ONLY (no top bar progress)
  const mode = (data?.mode || "").toString();
  const done = Number(data?.done ?? 0);
  const total = Number(data?.total ?? 0);
  const pending = Number(data?.pending ?? 0);

  // If a prior top progress notice exists from older code, remove it once.
  const oldTop = document.querySelector(".notice.alexk-press-progress");
  if (oldTop) oldTop.remove();

  const hud = ensureCornerHudEl();

  // Hide when no active job
  const noJob = (!Number.isFinite(total) || total <= 0 || (!pending && !done));
  if (noJob) {
    if (hud) hud.style.display = "none";
    return;
  }

  const file = (data?.current_filename || "").toString();
  const fileDone = Number(data?.file_done ?? 0);
  const filePending = Number(data?.file_pending ?? 0);

  const modeLabel = mode === "remove" ? "Removing" : (mode === "add" ? "Adding" : "Working");

  const filePart =
    file && Number.isFinite(filePending) && filePending > 0
      ? ` — ${fileDone}/${filePending} sizes: ${file}`
      : file
        ? ` — ${file}`
        : "";

  const text = `${modeLabel}: ${done}/${total} done (${pending} left)${filePart}`;

  const hudText = hud.querySelector(".alexk-press-hud-text");
  if (hudText) hudText.textContent = text;
  hud.style.display = "";
}


  // ---------------------------
  // Bulk mode detection (your WP 6.9 toolbar)
  // ---------------------------
    function isBulkModeActive() {
    const toggle = $("button.select-mode-toggle-button");
    if (!toggle) return false;

    const txt = ((toggle.textContent || "") + "").trim().toLowerCase();

    // WP changes this label across versions ("Cancel", "Cancel selection", etc.)
    // Also: selecting mode usually sets aria-pressed="true" or body.selecting.
    const ariaPressed = (toggle.getAttribute("aria-pressed") || "").toLowerCase();
    const bodySelecting = document.body && document.body.classList.contains("selecting");

    return bodySelecting || ariaPressed === "true" || txt.includes("cancel");
  }

  function exitBulkSelectMode() {
    const toggle = $("button.select-mode-toggle-button");
    if (!toggle) return;

    // Only click if we are in bulk/selecting mode
    if (isBulkModeActive()) toggle.click();
  }

  
  // ---------------------------
  // Green dot indicator
  // ---------------------------
  function ensureDotEl(tile) {
    if (!tile) return null;
    let dot = tile.querySelector(".alexk-press-dot");
    if (dot) return dot;
    dot = document.createElement("span");
    dot.className = "alexk-press-dot";
    dot.setAttribute("aria-hidden", "true");
    tile.appendChild(dot);
    return dot;
  }

  function applyIncludedStateToTile(tile, included) {
    if (!tile) return;
    if (included) {
      tile.classList.add("alexk-in-press");
      ensureDotEl(tile);
    } else {
      tile.classList.remove("alexk-in-press");
      const dot = tile.querySelector(".alexk-press-dot");
      if (dot) dot.remove();
    }

    // Orange group dot — present if in press AND has a group
    const hasGroup = !!(window.wp?.media?.attachment?.(
      parseInt(tile.getAttribute("data-id"), 10)
    )?.get?.("alexk_press_group"));

    if (included && hasGroup) {
      tile.classList.add("alexk-in-press-group");
      if (!tile.querySelector(".alexk-press-group-dot")) {
        const gdot = document.createElement("span");
        gdot.className = "alexk-press-group-dot";
        gdot.setAttribute("aria-hidden", "true");
        tile.appendChild(gdot);
      }
    } else {
      tile.classList.remove("alexk-in-press-group");
      tile.querySelector(".alexk-press-group-dot")?.remove();
    }
  }

  // ---------------------------
// Hover metadata (Grid View): show file details on hover
// ---------------------------
function buildHoverTextFromModel(model) {
  try {
    const get = (k) => (model && typeof model.get === "function" ? model.get(k) : undefined);

    const id = get("id");
    const filename = get("filename") || get("name") || get("title") || "";
    const subtype = get("subtype") || get("type") || "";
    const mime = get("mime") || get("mime_type") || "";
    const size = get("filesizeHumanReadable") || get("filesize") || "";
    const width = get("width");
    const height = get("height");
    const dims = (Number(width) > 0 && Number(height) > 0) ? `${width}×${height}` : "";

    const inCarousel = !!get("alexk_in_press");
    const carouselText = inCarousel ? "In press carousel: Yes" : "In press carousel: No";

    // Keep this short and readable in native tooltip
    const parts = [
      filename ? `File: ${filename}` : "",
      dims ? `Size: ${dims}` : "",
      size ? `Filesize: ${size}` : "",
      subtype ? `Type: ${subtype}` : (mime ? `Type: ${mime}` : ""),
      (Number(id) > 0) ? `ID: ${id}` : "",
      carouselText,
    ].filter(Boolean);

    return parts.join("\n");
  } catch {
    return "";
  }
}

function applyHoverMetaToTile(tile, model) {
  if (!tile) return;
  const text = buildHoverTextFromModel(model);
  if (!text) return;
  // Native browser tooltip on hover
  tile.setAttribute("title", text);
}

  function patchWpMediaAttachmentRender() {
    const Attachment = window.wp?.media?.view?.Attachment;
    if (!Attachment) return false;

    const proto = Attachment.prototype;
    if (proto.__alexkPressPatched) return true;
    proto.__alexkPressPatched = true;

    const originalRender = proto.render;
    proto.render = function (...args) {
      const out = originalRender.apply(this, args);
      try {
        const included = !!this.model?.get?.("alexk_in_press");
        applyIncludedStateToTile(this.el, included);
        applyHoverMetaToTile(this.el, this.model);

      } catch {}
      return out;
    };

    return true;
  }

  

  // ---------------------------
  // Selection helpers
  // ---------------------------
  function selectedTiles() {
    return $$(".attachments .attachment.selected");
  }

  function partitionSelection() {
    const inCarousel = [];
    const notInCarousel = [];
    for (const el of selectedTiles()) {
      const id = parseInt(el.getAttribute("data-id"), 10);
      if (!Number.isFinite(id)) continue;
      (el.classList.contains("alexk-in-press") ? inCarousel : notInCarousel).push(id);
    }
    return { inCarousel, notInCarousel };
  }

  // ---------------------------
  // AJAX + model sync 
  // ---------------------------
  async function postAjax(action, ids) {
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", window.ALEXK_PRESS_BULK?.nonce || "");
    body.set("ids", (ids || []).join(","));
    
    
    const ajaxUrl = window.ajaxurl || "/wp-admin/admin-ajax.php";
    const res = await fetch(ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    });
    return res.json();
  }

  



  // NEW CODE
    // ---------------------------
  // Elementor-safe progress pump:
  // If PHP advances one item per "status" call, we must poll status to keep work moving.
  // ---------------------------

  function startBulkProgressPump() {
  // Don't start twice
  if (document.__alexkPressPump) return;
  document.__alexkPressPump = true;

   const LAST_DONE_KEY = "alexk_press_last_completed";

  function modeLabel(mode) {
  const m = (mode || "").toString().toLowerCase();

  // Robust: supports "remove", "remove_from_carousel", "removing", etc.
  const isRemove = m.includes("remove") || m.includes("delete");

  return isRemove ? "Last removed from press carousel:" : "Last added to press carousel:";
}



  function ensureLastDoneNoticeEl() {
    const wrap = document.querySelector(".wrap");
    if (!wrap) return null;

    return ensurePressStatusNotice();
  }

  function setLastDoneText(filename, mode) {
  if (!filename) return;

  // Persist for refresh + stability
  try {
    localStorage.setItem(
      LAST_DONE_KEY,
      JSON.stringify({ filename, mode: (mode || "").toString(), ts: Date.now() })
    );
  } catch {}

  const el = ensureLastDoneNoticeEl();
  if (!el) return;

  const labelEl = el.querySelector(".alexk-press-lastdone-label");
  if (labelEl) { labelEl.textContent = modeLabel(mode); labelEl.style.display = ""; }
  const span = el.querySelector(".alexk-press-lastdone-text");
  if (span) { span.textContent = " " + filename; span.style.display = ""; }
  const sep = el.querySelector(".alexk-press-sep");
  if (sep) sep.style.display = "";
}


  // Show whatever we last knew immediately (survives refresh)
  try {
    const stored = localStorage.getItem(LAST_DONE_KEY);
    if (stored) {
      try {
        const parsed = JSON.parse(stored);
        if (parsed?.filename) setLastDoneText(parsed.filename, parsed.mode || "");
      } catch {
        // Back-compat: if something old is stored as a string
        setLastDoneText(stored, "");
      }
    }
  } catch {}


  let inFlight = false;
  let wasActive = false; // tracks if we ever saw an active job during this pump session

  const pumpId = setInterval(async () => {
    if (inFlight) return; // prevents overlapping calls
    inFlight = true;

    try {
      const json = await postAjax("alexk_press_bulk_job_status");
      const data = json?.data || {};

      // ✅ Update “Last completed” from server truth whenever it changes.
      const lastDone = (data?.last_completed_filename || "").toString().trim();
      const modeRaw = ((data?.mode || "") + "").toLowerCase().trim();
      // If server doesn't provide a mode (common when job ends / no active job),
      // do NOT overwrite the previously stored mode.
      let safeMode = "";
      if (modeRaw) {
        safeMode = (modeRaw.includes("remove") || modeRaw.includes("delete")) ? "remove" : "add";
      } else {
        // Fall back to whatever we last persisted
        try {
          const stored = localStorage.getItem(LAST_DONE_KEY);
          if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed?.mode) safeMode = (parsed.mode + "").toLowerCase();
          }
        } catch {}
      }

      // Only update from server if server data is newer than a local per-item update
      if (lastDone) {
        let localTs = 0;
        try { const s = localStorage.getItem('alexk_press_last_completed'); if (s) localTs = JSON.parse(s)?.ts || 0; } catch {}
        // Server has no timestamp — only update if nothing newer is in localStorage
        if (localTs === 0) setLastDoneText(lastDone, safeMode);
      }

      const total = Number(data?.total ?? 0);
      const pending = Number(data?.pending ?? 0);

      // No active job → hide progress UI, but keep “Last completed” visible.
      if (!Number.isFinite(total) || total <= 0) {
        renderBulkProgress({ total: 0, pending: 0, done: 0 });
       
        if (wasActive) {
          clearInterval(pumpId);
          document.__alexkPressPump = false;

          // Refresh so PHP-reported counts (like included_count) update immediately
          if (!window.__alexkPressReloadAfterJob) {
            window.__alexkPressReloadAfterJob = true;
            queueNoticeForAfterReload("Press carousel update complete. Count refreshed.", "success");
            setTimeout(() => window.location.reload(), 150);
          }
        }

        return;
      
      }

      //Job is active 
      wasActive = true;
      renderBulkProgress(data);

      // If finished, stop polling and remove progress notice (keep last-done notice)
      // 
      if (Number.isFinite(pending) && pending <= 0) {
        clearInterval(pumpId);
        document.__alexkPressPump = false;
        renderBulkProgress({ total: 0, pending: 0, done: 0 });

        if (!window.__alexkPressReloadAfterJob) {
            window.__alexkPressReloadAfterJob = true;
            queueNoticeForAfterReload("Press carousel update complete. Count refreshed.", "success");
            setTimeout(() => window.location.reload(), 150);
          }  

        return;
      }
    } catch {
      // swallow errors
    } finally {
      inFlight = false;
    }
  }, 800);
}



  function setWpMediaModelFlag(id, included) {
    try {
      const att = window.wp?.media?.attachment?.(id);
      if (!att || typeof att.set !== "function") return;

      // Our UI flag
      att.set("alexk_in_press", !!included);

      // The actual meta key used by the checkbox UI
      att.set("alexk_include_in_press", included ? "1" : "0");

      if (typeof att.trigger === "function") att.trigger("change");
    } catch {}
  }


  // If the compat panel is open for this attachment, update the checkbox immediately
  // without waiting for a server round-trip (stale on cached hosts).
  function syncOpenCompatCheckbox(id, included) {
    try {
      const frame = window.wp?.media?.frame;
      if (!frame) return;
      const sel = frame.state?.()?.get?.("selection");
      if (!sel) return;
      const first = sel.first?.();
      if (!first || first.get("id") !== id) return;
      const cb = document.querySelector(".attachment-compat .alexk-press-main-checkbox");
      if (!cb) return;
      cb.checked = !!included;
      cb.dispatchEvent(new Event("change"));
    } catch {}
  }

  function updateUiForIds(ids, included) {
    for (const id of ids) {
      const tile = document.querySelector(`.attachments .attachment[data-id="${id}"]`);
      if (tile) {
        applyIncludedStateToTile(tile, included);
        try {
          const att = window.wp?.media?.attachment?.(id);
          if (att) applyHoverMetaToTile(tile, att);
        } catch {}
      }
      setWpMediaModelFlag(id, included);
      syncOpenCompatCheckbox(id, included);
    }
  }

  // ---------------------------
  // Toolbar buttons (minimal)
  // ---------------------------
  function findToolbarContainer() {
    const toolbar = $(".media-toolbar.wp-filter") || $(".media-toolbar");
    if (!toolbar) return null;
    return $(".media-toolbar-secondary", toolbar) || toolbar;
  }

  function ensureButtons() {
    const container = findToolbarContainer();
    if (!container) return false;

    if (!$("#alexk-add-to-press")) {
      const add = document.createElement("button");
      add.id = "alexk-add-to-press";
      add.type = "button";
      add.className = "button button-primary";
      add.textContent = "Add to press carousel";
      container.append(add);
      add.addEventListener("click", onAdd);
    }

    if (!$("#alexk-remove-from-press")) {
      const rm = document.createElement("button");
      rm.id = "alexk-remove-from-press";
      rm.type = "button";
      rm.className = "button";
      rm.textContent = "Remove from press carousel";
      container.append(rm);
      rm.addEventListener("click", onRemove);
    }

    return true;
  }

  function updateButtonsVisibility() {
    const add = $("#alexk-add-to-press");
    const rm = $("#alexk-remove-from-press");
    if (!add || !rm) return;

    // Never show outside Bulk Select mode
    if (!isBulkModeActive()) {
      add.style.display = "none";
      rm.style.display = "none";
      return;
    }

    const { inCarousel, notInCarousel } = partitionSelection();
    add.style.display = notInCarousel.length ? "" : "none";
    rm.style.display = inCarousel.length ? "" : "none";
  }

  // ---------------------------
  // Click handlers
  // ---------------------------

    async function onAdd() {
    const { inCarousel, notInCarousel } = partitionSelection();
    const toAdd = notInCarousel;
    const skipped = inCarousel;

    if (!toAdd.length) {
      alert("All selected items are already in the press carousel.");
      return;
    }

    const msg = skipped.length
      ? `Add ${toAdd.length} item(s) to the press carousel?\n\nSkipping ${skipped.length} already included.`
      : `Add ${toAdd.length} item(s) to the press carousel?`;

    if (!confirm(msg)) return;

    let json;
    try {
      json = await postAjax("alexk_press_bulk_add", toAdd);
    } catch {
      showWpNotice("Bulk add failed (network).", "error");
      return;
    }

    if (!json?.success) {
      showWpNotice(json?.data?.message || "Bulk add failed.", "error");
      return;
    }

    // Update UI immediately (don’t wait for generation)
    updateUiForIds(toAdd, true);

    // Exit bulk select immediately so checkmarks clear and UI is responsive
    exitBulkSelectMode();
    updateButtonsVisibility();

    // Start polling so the queued job actually advances + progress notice updates
    startBulkProgressPump();

    // Visible feedback without reload
    showWpNotice(`Queued ${json?.data?.updated ?? toAdd.length} item(s) to add to the press carousel.`, "success", 12000);

  }

  async function onRemove() {
    const { inCarousel, notInCarousel } = partitionSelection();
    const toRemove = inCarousel;
    const skipped = notInCarousel;

    if (!toRemove.length) {
      alert("None of the selected items are currently in the press carousel.");
      return;
    }

    const msg = skipped.length
      ? `Remove ${toRemove.length} item(s) from the press carousel?\n\nSkipping ${skipped.length} not in press carousel.`
      : `Remove ${toRemove.length} item(s) from the press carousel?`;

    if (!confirm(msg)) return;

    let json;
    try {
      json = await postAjax("alexk_press_bulk_remove", toRemove);
    } catch {
      showWpNotice("Bulk remove failed (network).", "error");
      return;
    }

    if (!json?.success) {
      showWpNotice(json?.data?.message || "Bulk remove failed.", "error");
      return;
    }

    // Update UI immediately (don’t wait for deletion)
    updateUiForIds(toRemove, false);

    // Exit bulk select immediately so checkmarks clear and UI is responsive
    exitBulkSelectMode();
    updateButtonsVisibility();

    // Start polling so the queued job actually advances + progress notice updates
    startBulkProgressPump();

    // Visible feedback without reload
    showWpNotice(`Queued ${json?.data?.updated ?? toRemove.length} item(s) to add to the press carousel.`, "success", 12000);

  }


  // NEw Progress UI 
  async function startHudJob(mode, attachmentIds) {
  const form = new FormData();
  form.append("action", "alexk_job_start");
  form.append("mode", mode);
  attachmentIds.forEach((id) => form.append("attachment_ids[]", String(id)));

  const res = await fetch(window.ajaxurl, {
    method: "POST",
    body: form,
    credentials: "same-origin",
  });

  return res.json();
}


  // ---------------------------
  // Observers (small)
  // ---------------------------
  function bindObservers() {
    // Selection changes happen via clicks in the grid
    document.addEventListener(
      "click",
      (e) => {
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;

        if (t.closest(".attachments") || t.closest("button.select-mode-toggle-button")) {
          setTimeout(updateButtonsVisibility, 30);
        }
      },
      true
    );

    // Toggle button text changes (Bulk select <-> Cancel)
    const toggle = $("button.select-mode-toggle-button");
    if (toggle) {
      const mo = new MutationObserver(() => updateButtonsVisibility());
      mo.observe(toggle, { childList: true, subtree: true, attributes: true });
    }

    // WP may re-render toolbar/tiles; re-ensure buttons and state
    const domMo = new MutationObserver(() => {
      ensureButtons();
      updateButtonsVisibility();
    });
    domMo.observe(document.documentElement, { childList: true, subtree: true });
  }

 function boot() {
  // Notices (if you ever re-enable "after reload" messaging)
  try {
    showQueuedNoticeIfAny();
  } catch (e) {
    console.error("ALEXK boot: showQueuedNoticeIfAny failed", e);
  }


  // Carousel count (admin notice)
  showPressIncludedCountNotice();

  // Patch tile rendering so dots + alexk-in-press class reflect REAL model state.
  // Without this, tiles can say "not in press carousel" while folders exist (and vice versa).
  try {
    if (!patchWpMediaAttachmentRender()) {
      const mo = new MutationObserver(() => {
        try {
          if (patchWpMediaAttachmentRender()) mo.disconnect();
        } catch (e) {
          console.error("ALEXK boot: patchWpMediaAttachmentRender retry failed", e);
        }
      });
      mo.observe(document.documentElement, { childList: true, subtree: true });
    }
  } catch (e) {
    console.error("ALEXK boot: patchWpMediaAttachmentRender failed", e);
  }

  // Keep dot state consistent when closing single attachment view
  try {
    bindReloadOnExitSingleViewOnce();
  } catch (e) {
    console.error("ALEXK boot: bindReloadOnExitSingleViewOnce failed", e);
  }

  // Buttons + observers
  try {
    ensureButtons();
  } catch (e) {
    console.error("ALEXK boot: ensureButtons failed", e);
  }

  try {
    updateButtonsVisibility();
  } catch (e) {
    console.error("ALEXK boot: updateButtonsVisibility failed", e);
  }

  try {
    bindObservers();
  } catch (e) {
    console.error("ALEXK boot: bindObservers failed", e);
  }

  // Always start the pump on page load so refresh does NOT "clear" progress.
  // If there is no active job, renderBulkProgress() will hide UI.
  try {
    startBulkProgressPump();
  } catch (e) {
    console.error("ALEXK boot: startBulkProgressPump failed", e);
  }
}



  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();