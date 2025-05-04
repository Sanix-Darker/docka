document.addEventListener('DOMContentLoaded', () => {
    const els = {
        form: document.getElementById('build-form'),
        repoInput: document.getElementById('repo-url'),
        submitBtn: document.getElementById('submit-btn'),
        cards: document.getElementById('sandboxes')
    };

    let buildSeq = 0;
    const setLoading = (flag) => {
        els.submitBtn.disabled = flag;
        els.submitBtn.innerHTML = flag ?
            '<span class="spinner"></span> Building…' :
            'Build and Run';
    };

    const createCard = () => {
        const id = ++buildSeq;
        els.cards.insertAdjacentHTML('afterbegin', `
      <div class="card">
        <h2>Build #${id}</h2>
        <nav class="service"></nav>
        <hr/>
        <details class="log-box" open>
          <summary>
            Logs <span class="status-text">⏳ Building… click to expand/close…</span>
          </summary>
          <pre class="log"></pre>
        </details>

        <canvas class="chart" width="400" height="150"></canvas>

        <div class="actions">
          <button class="stop">-Stop</button>
          <button class="close">✖Close</button>
        </div>
      </div>`);
        const card = els.cards.firstElementChild;
        // this is also handled aferwards but we should always be able to remove an ongoing card
        card.querySelector('.close').onclick = () => card.remove();
        return {
            card,
            status: card.querySelector('.status-text'),
            logEl: card.querySelector('.log'),
            serviceEl: card.querySelector('.service'),
            ctx: card.querySelector('.chart').getContext('2d'),
            stopBt: card.querySelector('.stop'),
            clsBt: card.querySelector('.close')
        };
    };


    const POLL_INTERVAL = 1000;
    let logPos = 0;
    function startLogPolling(logEl, sandboxId) {
      async function poll() {
        try {
          const r = await fetch(
            `/tail.php?sid=${encodeURIComponent(sandboxId)}&pos=${logPos}`,
            { cache: 'no-store' }          // never allow cached answers
          );

          if (r.status === 204) {
            /* nothing new – just wait for the next round */
          } else if (r.ok) {
            const { pos, lines } = await r.json();
            logPos = pos;
            for (const line of lines) {
              logEl.textContent += line + '\n';
            }
            logEl.scrollTop = logEl.scrollHeight;
          } else {
            console.error('[log‑poll] HTTP', r.status, await r.text());
          }
        } catch (err) {
          console.error('[log‑poll] network error', err);
        } finally {
          /* schedule the next request */
          setTimeout(poll, POLL_INTERVAL);
        }
      }
      poll();
    }

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
        setLoading(true);
        const {
            card,
            status,
            logEl,
            serviceEl,
            ctx,
            stopBt,
            clsBt
        } = createCard();

        console.log({
            card,
            status,
            logEl,
            serviceEl,
            ctx,
            stopBt,
            clsBt
        });
        const fd = new FormData(els.form);
        console.log(fd);

        try {
            const res = await fetch('/build.php', {
                    method: 'POST',
                    body: fd
                })
                .then((r) => r.json());
            console.log("response: ", res);
            if (!res.ok) throw new Error(res.error || 'Build failed');

            startLogPolling(logEl, res.sandboxId);

            const chart = buildChart(ctx);
            const pollId = pollStats(res.containerIds[0], chart);

            stopBt.onclick = async () => {
                stopBt.disabled = true;
                await fetch(`/stop.php?sid=${res.sandboxId}`);
            };
            clsBt.onclick = () => {
                //es.close();
                clearInterval(pollId);
                card.remove();
            };

            status.textContent = '✔ Done';
            if (res.ports?.length) {
                serviceEl.textContent += '— services —\n';
                res.ports.forEach((p) =>
                    // we post the service only if the port is available
                    p.hostPort && (serviceEl.innerHTML += `<br/> → <small><a href="http://${location.hostname}:${p.hostPort}" target="_blank">${p.service.substring(0,5)}:${p.hostPort}</a></small>\n`)
                );
            }
        } catch (e) {
            serviceEl.textContent += `❌ ${e.message}`;
            console.log(`Error: ${e.message}`, true);
        } finally {
            setLoading(false);
        }
    };

    els.form.onsubmit = (e) => {
        e.preventDefault();
        if (!els.repoInput.value.trim()) {
            alert('Repository URL required !!!');
        }
        build();
    };

    // For cards enhances
    const box   = document.getElementById('sandboxes');
    let dragged = null;

    /* Make every card draggable (in case the attribute is missing) */
    box.querySelectorAll('.card').forEach(c => c.setAttribute('draggable', true));
    box.addEventListener('dragstart', e=>{
      if (e.target.classList.contains('card')){
        dragged = e.target;
        e.target.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
      }
    });
    box.addEventListener('dragend', e=>{
      if (e.target.classList.contains('card')){
        e.target.classList.remove('dragging');
        dragged = null;
      }
    });
    /* reorder while hovering other cards */
    box.addEventListener('dragover', e=>{
      e.preventDefault();                                         // allow drop
      const cards = [...box.querySelectorAll('.card:not(.dragging)')];
      const next  = cards.find(card=>{
        const r = card.getBoundingClientRect();
        return e.clientY < r.top + r.height/2;                    // above centre?
      });
      box.insertBefore(dragged, next || null);                    // null → append
    });
});
