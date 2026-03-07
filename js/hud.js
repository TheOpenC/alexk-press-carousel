(function () {
  const LS_KEY  = 'alexk_press_active_job_id';
  const POLL_MS = 1200;

  function el(tag, attrs = {}, text = '') {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
    if (text) node.textContent = text;
    return node;
  }

  function ensureHud() {
    let box = document.getElementById('alexk-press-hud');
    if (box) return box;

    box = el('div', { id: 'alexk-press-hud' });
    Object.assign(box.style, {
      position: 'fixed', right: '12px', bottom: '12px', zIndex: '999999',
      background: 'rgba(0,0,0,0.85)', color: '#fff',
      font: '12px/1.35 system-ui, -apple-system, Segoe UI, Roboto, Arial',
      padding: '10px 12px', borderRadius: '8px',
      maxWidth: '340px', whiteSpace: 'pre-wrap',
      boxShadow: '0 6px 20px rgba(0,0,0,0.25)', cursor: 'default',
    });

    const close = el('button', { type: 'button', 'aria-label': 'Dismiss' }, '×');
    Object.assign(close.style, {
      all: 'unset', position: 'absolute', top: '6px', right: '10px',
      cursor: 'pointer', fontSize: '16px', opacity: '0.85',
    });
    close.onclick = () => { localStorage.removeItem(LS_KEY); box.remove(); };

    box.appendChild(close);
    box.appendChild(el('div', { id: 'alexk-press-hud-body' }, 'No active job.'));
    document.body.appendChild(box);
    return box;
  }

  function setBody(text) {
    const box  = ensureHud();
    const body = box.querySelector('#alexk-press-hud-body');
    body.textContent = text;
  }

  async function fetchStatus(jobId) {
    const url = `${window.ajaxurl}?action=alexk_job_status&job_id=${encodeURIComponent(jobId)}`;
    const res = await fetch(url, { credentials: 'same-origin' });
    return res.json();
  }

  function fmtAge(seconds) {
    if (seconds == null) return '?';
    if (seconds < 2)  return 'just now';
    if (seconds < 60) return `${seconds}s ago`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}m ${s}s ago`;
  }

  async function loop() {
    const jobId = localStorage.getItem(LS_KEY);
    if (!jobId) return;

    try {
      const json = await fetchStatus(jobId);
      if (!json || json.success !== true) {
        setBody(`Press build\nJob: ${jobId}\nStatus: unknown / expired`);
        return;
      }

      const job     = json.data.job || {};
      const c       = json.data.computed || {};
      const done    = Number(job.done   || 0);
      const total   = Number(job.total  || 0);
      const pct     = Number(c.percent  || 0);
      const status  = job.status || 'unknown';
      const current = job.current || {};

      const currentLine = current.filename
        ? `Currently: ${String(current.filename).split('/').pop()}${current.variant ? ` (${current.variant})` : ''}`
        : 'Currently: —';

      const lines = [
        'Press carousel build',
        `Job: ${jobId}`,
        `Progress: ${done} / ${total} (${pct}%)`,
        currentLine,
        `Last update: ${fmtAge(c.stall_age)}`,
      ];
      if (job.errors) lines.push(`Errors: ${job.errors}${job.last_error ? ` (last: ${job.last_error})` : ''}`);
      if (status !== 'running') lines.push(`Status: ${status}`);
      if (c.stalled) lines.push('⚠ STALLED — check server/logs');

      setBody(lines.join('\n'));
    } catch {
      setBody(`Press build\nJob: ${jobId}\nStatus: error fetching status`);
    }
  }

  setInterval(loop, POLL_MS);
  loop();

  window.__alexkPressHud = {
    setActiveJob(jobId) { localStorage.setItem(LS_KEY, jobId); loop(); },
    clear() {
      localStorage.removeItem(LS_KEY);
      const box = document.getElementById('alexk-press-hud');
      if (box) box.remove();
    },
  };
})();
