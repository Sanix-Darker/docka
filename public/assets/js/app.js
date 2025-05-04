document.addEventListener('DOMContentLoaded', () => {
    const els = {
        form: document.getElementById('build-form'),
        repoInput: document.getElementById('repo-url'),
        submitBtn: document.getElementById('submit-btn'),
        log: document.getElementById('log-viewer'),
        // status: document.getElementById('status'),
        //serviceWrap: document.getElementById('service-container'),
        //serviceList: document.getElementById('service-links'),
        cards: document.getElementById('sandboxes')
    };

    let buildSeq = 0;

    const blink = () => '<span class="blinking-cursor"></span>';

    const scrollLog = () => {
        els.log.scrollTop = els.log.scrollHeight;
    };

    const clearLog = () => {
        els.log.innerHTML = blink();
    };

    const appendLog = (text, error = false) => {
        const cls = error ? 'error-text' : '';
        els.log.querySelector('.blinking-cursor')?.remove();
        els.log.insertAdjacentHTML('beforeend', `<div class="${cls}">${text}</div>${blink()}`);
        scrollLog();
    };
    const setLoading = (flag) => {
        els.submitBtn.disabled = flag;
        els.submitBtn.innerHTML = flag ?
            '<span class="spinner"></span> Building…' :
            'Build and Run';
        // els.status.textContent = flag ? 'Building…' : 'Ready';
    };
    //const showServices = (ports = []) => {
    //    if (!ports.length) {
    //        els.serviceWrap.classList.add('hidden');
    //        return;
    //    }
    //    els.serviceList.innerHTML = ports.map(
    //        (p) => p.hostPort && `
    //    <li>
    //      <a class="service-link" target="_blank"
    //         href="http://${location.hostname}:${p.hostPort}">
    //        ${p.service} (${p.containerPort} → ${p.hostPort})
    //      </a>
    //    </li>`
    //    ).join('');
    //    els.serviceWrap.classList.remove('hidden');
    //};

    const createCard = () => {
        const id = ++buildSeq;
        els.cards.insertAdjacentHTML('afterbegin', `
      <div class="card">
        <h2>Build #${id}</h2>
        <details class="log-box" open>
          <summary>
            Logs <span class="status-text">⏳ Building… click to expand/close…</span>
          </summary>
          <pre class="log"></pre>
        </details>

        <canvas class="chart" width="400" height="150"></canvas>

        <div class="actions">
          <button class="stop">⏹ Stop</button>
          <button class="close">✖</button>
        </div>
      </div>`);
        const card = els.cards.firstElementChild;
        // this is also handled aferwards but we should always be able to remove an ongoing card
        card.querySelector('.close').onclick = () => card.remove();
        return {
            card,
            status: card.querySelector('.status-text'),
            logEl: card.querySelector('.log'),
            ctx: card.querySelector('.chart').getContext('2d'),
            stopBt: card.querySelector('.stop'),
            clsBt: card.querySelector('.close')
        };
    };

    const buildChart = ctx => new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                    label: 'CPU %',
                    data: [],
                    yAxisID: 'y1'
                },
                {
                    label: 'Mem MB',
                    data: [],
                    yAxisID: 'y2'
                }
            ]
        },
        options: {
            animation: false,
            scales: {
                y1: {
                    type: 'linear',
                    position: 'left',
                    suggestedMax: 100
                },
                y2: {
                    type: 'linear',
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

    const pollStats = (cid, chart) => setInterval(async () => {
        try {
            const s = await fetch(`/stats.php?cid=${cid}`).then((r) => r.json());
            // if (s.cpu === undefined) return; // container may be gone
            const t = new Date().toLocaleTimeString();
            chart.data.labels.push(t);
            chart.data.datasets[0].data.push(+s.cpu);
            chart.data.datasets[1].data.push((+s.mem / 1048576).toFixed(1)); // bytes → MB
            if (chart.data.labels.length > 30) {
                chart.data.labels.shift();
                chart.data.datasets.forEach(d => d.data.shift());
            }
            chart.update();
        } catch {}
    }, 2000);

    const build = async () => {
        clearLog();
        setLoading(true);
        // els.serviceWrap.classList.add('hidden');

        const {
            card,
            status,
            logEl,
            ctx,
            stopBt,
            clsBt
        } = createCard();

        console.log({
            card,
            status,
            logEl,
            ctx,
            stopBt,
            clsBt
        });
        const fd = new FormData(els.form);
        console.log("build formdata:");
        console.log(fd);

        try {
            const res = await fetch('/build.php', {
                    method: 'POST',
                    body: fd
                })
                .then((r) => r.json());
            console.log("response: ", res);
            if (!res.ok) throw new Error(res.error || 'Build failed');

            const es = new EventSource(`/stream.php?sid=${encodeURIComponent(res.sandboxId)}`);
            es.onmessage = (e) => {
              logEl.textContent += e.data + '\n';
              logEl.scrollTop = logEl.scrollHeight;
            };
            es.onerror = (e) => {
              console.error('[SSE] error', e);
              if (es.readyState === EventSource.CLOSED)
                console.warn('[SSE] stream closed');
            };

            const chart = buildChart(ctx);
            const pollId = pollStats(res.containerIds[0], chart);

            stopBt.onclick = async () => {
                stopBt.disabled = true;
                await fetch(`/stop.php?sid=${res.sandboxId}`);
            };
            clsBt.onclick = () => {
                es.close();
                clearInterval(pollId);
                card.remove();
            };

            status.textContent = '✔ Done';
            if (res.ports?.length) {
                logEl.textContent += '— services —\n';
                res.ports.forEach((p) =>
                    // we post the service only if the port is available
                    p.hostPort && (logEl.textContent += `${p.service}\n → http://${location.hostname}:${p.hostPort}\n`)
                );
            }
            //showServices(res.ports);
        } catch (e) {
            logEl.textContent += `❌ ${e.message}`;
            appendLog(`Error: ${e.message}`, true);
        } finally {
            setLoading(false);
        }
    };

    /* ───────── form wiring ───────── */
    els.form.onsubmit = (e) => {
        e.preventDefault();
        if (!els.repoInput.value.trim()) {
            return appendLog('Repository URL required', true);
        }
        build();
    };

    /* ───────── global session log stream ───────── */
    // try {
    //     const ges = new EventSource('/stream_session.php');
    //     ges.onmessage = (e) => {
    //         try {
    //             const d = JSON.parse(e.data);
    //             appendLog(`[${d.sid}] ${d.msg}`);
    //         } catch {
    //             appendLog(e.data);
    //         }
    //     };
    //     ges.onerror = () => {
    //         appendLog('⚠️ Global log stream disconnected', true);
    //         ges.close();
    //     };
    // } catch (err) {
    //     console.error('Unable to open global log stream', err);
    // }

    /* banner */
    clearLog();
    appendLog('> Docka ready…');
    appendLog('> Enter a Git repository URL, then click “Build and Run”.');
});
