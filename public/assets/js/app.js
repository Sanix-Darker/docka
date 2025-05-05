/**
 * Return the current PHP session id (or "anon" if none found).
 * @returns {string}
 */
const getSessionId = () => {
  const match = document.cookie.match(/PHPSESSID=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : 'anon';
};

// common const
const LS_KEY = `docka_${getSessionId()}`;
const LOG_POLL   = 2000;
const STAT_POLL  = 3500;


/**
 * @typedef {Object} BuildInfo
 * @property {string} sid      – sandbox id
 * @property {Array<{hostPort:number,service:string}>} [ports]
 * @property {string[]} containerIds
 */

/**
 * Load every stored build for the current session.
 * @returns {BuildInfo[]}
 */
const loadStored = () =>
  JSON.parse(localStorage.getItem(LS_KEY) || '[]');

/**
 * Persist (or replace) a build.
 * @param {BuildInfo} build
 */
const saveBuild = build => {
  const items = loadStored().filter(b => b.sid !== build.sid);
  items.push(build);
  localStorage.setItem(LS_KEY, JSON.stringify(items));
};

/**
 * Drop a build from storage.
 * @param {string} sid
 */
const dropBuild = sid => {
  const items = loadStored().filter(b => b.sid !== sid);
  localStorage.setItem(LS_KEY, JSON.stringify(items));
};

/**
 * POST /build.php
 * @param {FormData} fd
 * @returns {Promise<{
 *   ok:boolean,
 *   error?:string,
 *   sandboxId:string,
 *   ports:Array<{hostPort:number,service:string}>,
 *   containerIds:string[]
 * }>}
 */
const requestBuild = fd =>
  fetch('/build.php', { method: 'POST', body: fd }).then(r => r.json());

/**
 * GET /stop.php
 * @param {string} sid
 * @returns {Promise<void>}
 */
const stopSandbox = sid => fetch(`/stop.php?sid=${sid}`);

/**
 * GET /stats.php
 * @param {string} cid
 * @returns {Promise<{cpu:number,mem:number}>}
 */
const fetchStats = cid =>
  fetch(`/stats.php?cid=${cid}`, { cache: 'no-store' }).then(r => r.json());

/**
 * GET /tail.php
 * @param {string} sid
 * @param {number} pos
 * @returns {Promise<{pos:number,lines:string[]} | null>} – null when 204
 */
const tailLogs = async (sid, pos) => {
  const r = await fetch(
    `/tail.php?sid=${encodeURIComponent(sid)}&pos=${pos}`,
    { cache: 'no-store' }
  );
  return r.status === 204 ? null : r.json();
};

/**
 * DOM handles used everywhere.
 * @type {{form:HTMLFormElement,repo:HTMLInputElement,submit:HTMLButtonElement,cards:HTMLElement}}
 */
const els = {
  form:   /** @type {HTMLFormElement}   */ (document.getElementById('build-form')),
  repo:   /** @type {HTMLInputElement}  */ (document.getElementById('repo-url')),
  submit: /** @type {HTMLButtonElement} */ (document.getElementById('submit-btn')),
  cards:  /** @type {HTMLElement}       */ (document.getElementById('sandboxes'))
};

/**
 * Toggle submit‑button loading state.
 * @param {boolean} busy
 */
const setLoading = busy => {
  els.submit.disabled = busy;
  els.submit.innerHTML = busy
    ? '<span class="spinner"></span>&nbsp;Building…'
    : 'Build and Run';
};

let seq = 0;

/**
 * Create and insert a fresh card into the DOM.
 * @returns {{
 *   card:HTMLElement, status:HTMLElement, log:HTMLElement,
 *   services:HTMLElement, ctx:CanvasRenderingContext2D,
 *   stopBt:HTMLButtonElement, closeBt:HTMLButtonElement
 * }}
 */
const createCard = () => {
  const id = ++seq;
  els.cards.insertAdjacentHTML(
    'afterbegin',
    `<div class="card">
      <h2>Build #${id}</h2>
      <nav class="service"></nav>
      <hr/>
      <details class="log-box" open>
        <summary>Logs <span class="status-text">⏳ Building… click to expand/close…</span></summary>
        <pre class="log"></pre>
      </details>
      <canvas class="chart" width="400" height="150"></canvas>
      <div class="actions">
        <button class="stop">-Stop</button>
        <button class="close">✖Close</button>
      </div>
    </div>`
  );

  const card     = /** @type {HTMLElement} */ (els.cards.firstElementChild);
  const stopBt   = /** @type {HTMLButtonElement} */ (card.querySelector('.stop'));
  const closeBt  = /** @type {HTMLButtonElement} */ (card.querySelector('.close'));

  closeBt.onclick = () => card.remove();

  return {
    card,
    status:   /** @type {HTMLElement} */ (card.querySelector('.status-text')),
    log:      /** @type {HTMLElement} */ (card.querySelector('.log')),
    services: /** @type {HTMLElement} */ (card.querySelector('.service')),
    ctx:      /** @type {HTMLCanvasElement}*/ (
      card.querySelector('.chart')
    ).getContext('2d'),
    stopBt,
    closeBt
  };
};

/**
 * Inject clickable service links into a card.
 * @param {HTMLElement} root
 * @param {Array<{hostPort:number,service:string}>} ports
 */
const renderServiceLinks = (root, ports) => {
  if (!ports?.length) return;
  root.textContent = '— services —';
  ports.forEach(p => {
    if (!p.hostPort) return;
    root.insertAdjacentHTML(
      'beforeend',
      `<br/> → <small><a href="http://${location.hostname}:${p.hostPort}" target="_blank">http://${p.service.slice(0,5)}:${p.hostPort}</a></small><br/>`
    );
  });
};

/**
 * Build an empty chart for live stats.
 * @param {CanvasRenderingContext2D} ctx
 */
const buildChart = ctx =>
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        { label: 'CPU %', data: [], yAxisID: 'y1' },
        { label: 'Mem MB', data: [], yAxisID: 'y2' }
      ]
    },
    options: {
      animation: false,
      scales: {
        y1: { type: 'linear', position: 'left', suggestedMax: 100 },
        y2: {
          type: 'linear',
          position: 'right',
          grid: { drawOnChartArea: false }
        }
      }
    }
  });

/**
 * Begin log polling for a sandbox.
 * @param {HTMLElement} logEl
 * @param {string} sid
 */
const startLogPolling = (logEl, sid) => {
  let pos = 0;
  const go = async () => {
    try {
      const res = await tailLogs(sid, pos);
      if (res) {
        pos = res.pos;
        res.lines.forEach(l => (logEl.textContent += `${l}\n`));
        logEl.scrollTop = logEl.scrollHeight;
      }
    } catch (err) {
      console.error('[log‑poll]', err);
    } finally {
      setTimeout(go, LOG_POLL);
    }
  };
  go();
};

/**
 * Schedule periodic stats fetch + chart update.
 * @param {string} cid
 * @returns {number} – interval id
 */
const pollStats = (cid, chart) =>
  setInterval(async () => {
    try {
      const s = await fetchStats(cid);
      const t = new Date().toLocaleTimeString();
      chart.data.labels.push(t);
      chart.data.datasets[0].data.push(+s.cpu);
      chart.data.datasets[1].data.push((+s.mem / 1048576).toFixed(1));
      if (chart.data.labels.length > 30) {
        chart.data.labels.shift();
        chart.data.datasets.forEach(d => d.data.shift());
      }
      chart.update();
    } catch (error) { console.log(error); }
  }, STAT_POLL);

/** Handle a new build request. */
const handleBuild = async () => {
  setLoading(true);
  const {
    card, status, log, services, ctx, stopBt, closeBt
  } = createCard();

  try {
    const fd  = new FormData(els.form);
    const res = await requestBuild(fd);
    if (!res.ok) throw new Error(res.error || 'Build failed');

    startLogPolling(log, res.sandboxId);

    const chart  = buildChart(ctx);
    const intId  = pollStats(res.containerIds[0], chart);

    saveBuild({
      sid:          res.sandboxId,
      ports:        res.ports,
      containerIds: res.containerIds
    });

    stopBt.onclick = async () => {
      stopBt.disabled = true;
      await stopSandbox(res.sandboxId);
    };
    closeBt.onclick = () => {
      clearInterval(intId);
      card.remove();
    };

    status.textContent = '✔ Done (click to close/expand)';
    renderServiceLinks(services, res.ports);
  } catch (e) {
    status.textContent = `❌ ${e.message}`;
  } finally {
    setLoading(false);
  }
};

// Restore previously running builds from localStorage.
const bootstrap = () => {
  loadStored().forEach(b => {
    const {
      card, log, services, ctx, closeBt
    } = createCard();
    closeBt.disabled = false; // it's always stoppable

    startLogPolling(log, b.sid);
    const chart  = buildChart(ctx);
    const intId  = pollStats(b.containerIds[0], chart);

    closeBt.onclick = () => {
      clearInterval(intId);
      dropBuild(b.sid);
      card.remove();
    };

    renderServiceLinks(services, b.ports);
    card.querySelector('.status-text').textContent = '✔ Running';
  });
};

document.addEventListener('DOMContentLoaded', () => {
  bootstrap();
  els.form.onsubmit = e => {
    e.preventDefault();
    if (!els.repo.value.trim()) return alert('Repository URL required!');
    handleBuild();
  };
});
