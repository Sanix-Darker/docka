document.addEventListener('DOMContentLoaded', () => {
  const els = {
    form: document.getElementById('build-form'),
    repoInput: document.getElementById('repo-url'),
    submitBtn: document.getElementById('submit-btn'),
    log: document.getElementById('log-viewer'),
    status: document.getElementById('status'),
    serviceWrap: document.getElementById('service-container'),
    serviceList: document.getElementById('service-links'),
    cards: document.getElementById('sandboxes')
  };

  let buildSeq = 0;

  const blink = () => '<span class="blinking-cursor"></span>';

  const scrollLog = () => { els.log.scrollTop = els.log.scrollHeight; };

  const clearLog = () => { els.log.innerHTML = blink(); };

  const appendLog = (text, error = false) => {
    const cls = error ? 'error-text' : '';
    els.log.querySelector('.blinking-cursor')?.remove();
    els.log.insertAdjacentHTML('beforeend', `<div class="${cls}">${text}</div>${blink()}`);
    scrollLog();
  };

  const setLoading = flag => {
    const html = flag ? '<span class="spinner"></span> Building...' : 'Build and Test';
    els.submitBtn.disabled = flag;
    els.submitBtn.innerHTML = html;
    els.status.innerHTML = flag ? html : 'Ready';
  };

  const showServices = ports => {
    if (!ports?.length) {
      els.serviceWrap.classList.add('hidden');
      return;
    }
    els.serviceList.innerHTML = ports.map(p =>
      `<li><a class="service-link" target="_blank" rel="noopener noreferrer"
        href="http://${location.hostname}:${p.hostPort}">
        ${p.service} (${p.containerPort} → ${p.hostPort})
      </a></li>`).join('');
    els.serviceWrap.classList.remove('hidden');
  };

  const createCard = () => {
    const id = ++buildSeq;
    els.cards.insertAdjacentHTML('afterbegin', `
      <div class="card">
        <h2>Build #${id}</h2>
        <details class="log-box" open>
          <summary>
            Logs <span class="status-text">⏳ Building…</span>
          </summary>
          <pre class="log">Click to expand/close…\n</pre>
        </details>
        <canvas width="400" height="160" class="chart"></canvas>
        <button class="close">✖ Close</button>
      </div>`);
    const card = els.cards.firstElementChild;
    card.querySelector('.close').onclick = () => card.remove();
    return {
      logEl: card.querySelector('.log'),
      ctx: card.querySelector('.chart').getContext('2d')
    };
  };

  const buildChart = ctx => new Chart(ctx, {
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
        y2: { type: 'linear', position: 'right', grid: { drawOnChartArea: false } }
      }
    }
  });

  const pollStats = (cid, chart) => setInterval(async () => {
    try {
      const s = await fetch(`/stats.php?cid=${cid}`).then(r => r.json());
      const t = new Date().toLocaleTimeString();
      chart.data.labels.push(t);
      chart.data.datasets[0].data.push(+s.cpu);
      chart.data.datasets[1].data.push((+s.mem / 1_048_576).toFixed(1));
      if (chart.data.labels.length > 30) {
        chart.data.labels.shift();
        chart.data.datasets.forEach(d => d.data.shift());
      }
      chart.update();
    } catch {}
  }, 2000);

  const build = async repo => {
    clearLog();
    setLoading(true);
    els.serviceWrap.classList.add('hidden');

    const { logEl, ctx } = createCard();
    const fd = new FormData();
    fd.append('repo', repo);

    try {
      const res = await fetch('/build.php', { method: 'POST', body: fd }).then(r => r.json());
      if (!res.ok) throw new Error(res.error || 'Build failed');

      logEl.textContent = `${res.log}\n\n`;
      if (res.ports?.length) {
        logEl.textContent += '🚀 Open services:\n';
        res.ports.forEach(p => {
          const url = `${location.protocol}//${location.hostname}:${p.hostPort}`;
          logEl.textContent += `  • ${p.service} → ${url}\n`;
        });
      }

      const chart = buildChart(ctx);
      console.log("res: ");
      console.log(res);
      pollStats(res.containerIds[0], chart);

      appendLog(`> Building from repository: ${repo}`);
      appendLog('> Build completed successfully!');
      res.log && appendLog(`\nBuild Log:\n${res.log}`);
      res.ports?.length
        ? appendLog('\n> Services are now available:')
        : appendLog('\n> No services exposed.');
      showServices(res.ports);
    } catch (e) {
      logEl.textContent = `❌ ${e.message}`;
      appendLog('> Build failed!', true);
      appendLog(`Error: ${e.message}`, true);
    } finally {
      setLoading(false);
    }
  };

  els.form.addEventListener('submit', e => {
    e.preventDefault();
    const repo = els.repoInput.value.trim();
    if (!repo) {
      appendLog('Error: Repository URL is required', true);
      return;
    }
    build(repo);
  });

  clearLog();
  appendLog('> Docka ready...');
  appendLog('> Enter a Git repository URL and click "Build and Test".');
});
