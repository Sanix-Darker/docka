const form = document.getElementById('testerForm');
const log  = document.getElementById('log');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    log.textContent = '⏳ Building, please wait…\n';


    try {
        const fd = new FormData(form);
        const res = await fetch('/build.php', { method: 'POST', body: fd });
        const json = await res.json();

        if (!json.ok) {
            log.textContent = '❌ ' + json.error;
            if (!json.ok) throw new Error(json.error);
        }
        log.textContent = json.log + '\n\n';

        if (json.ports.length) {
            log.textContent += '🚀 Open services:\n';
            json.ports.forEach(p => {
                const url = `${location.protocol}//${location.hostname}:${p.hostPort}`;
                log.textContent += `  • ${p.service} → ${url}\n`;
            });
        } else {
            log.textContent += 'No ports published.';
        }
        // … (render log & ports exactly as before)
    } catch (err) {
        log.textContent = '❌ ' + err.message;
    }
});
