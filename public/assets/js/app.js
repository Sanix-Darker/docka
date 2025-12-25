/**
 * Docka - Docker Sandbox Runner
 * Frontend application
 */

'use strict';

// ─────────────────────────────────────────────────────────────────────────────
// CONFIGURATION
// ─────────────────────────────────────────────────────────────────────────────

const CONFIG = Object.freeze({
    LOG_POLL_MS: 1500,
    STATS_POLL_MS: 3000,
    MAX_LOG_LINES: 500,
    MAX_CHART_POINTS: 30,
    STORAGE_KEY_PREFIX: 'docka_',
    ENDPOINTS: {
        build: '/build.php',
        stop: '/stop.php',
        stats: '/stats.php',
        tail: '/tail.php',
    },
});

// ─────────────────────────────────────────────────────────────────────────────
// UTILITIES
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Get the current PHP session ID from cookies
 * @returns {string}
 */
const getSessionId = () => {
    const match = document.cookie.match(/PHPSESSID=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : 'anon';
};

/**
 * Generate storage key for current session
 * @returns {string}
 */
const getStorageKey = () => `${CONFIG.STORAGE_KEY_PREFIX}${getSessionId()}`;

/**
 * Format bytes to human readable string
 * @param {number} bytes
 * @returns {string}
 */
const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return 'N/A';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return `${bytes.toFixed(1)} ${units[i]}`;
};

/**
 * Escape HTML to prevent XSS
 * @param {string} str
 * @returns {string}
 */
const escapeHtml = (str) => {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
};

/**
 * Debounce function calls
 * @param {Function} fn
 * @param {number} delay
 * @returns {Function}
 */
const debounce = (fn, delay) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

// ─────────────────────────────────────────────────────────────────────────────
// STORAGE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @typedef {Object} StoredBuild
 * @property {string} sid - Sandbox ID
 * @property {string} repo - Repository URL
 * @property {Array<{hostPort: number, service: string, containerPort: number}>} ports
 * @property {string[]} containerIds
 * @property {number} created - Timestamp
 */

const Storage = {
    /**
     * Load all stored builds for current session
     * @returns {StoredBuild[]}
     */
    load() {
        try {
            const data = localStorage.getItem(getStorageKey());
            return data ? JSON.parse(data) : [];
        } catch (e) {
            console.error('[Storage] Load failed:', e);
            return [];
        }
    },

    /**
     * Save a build to storage
     * @param {StoredBuild} build
     */
    save(build) {
        try {
            const items = this.load().filter(b => b.sid !== build.sid);
            items.push(build);
            localStorage.setItem(getStorageKey(), JSON.stringify(items));
        } catch (e) {
            console.error('[Storage] Save failed:', e);
        }
    },

    /**
     * Remove a build from storage
     * @param {string} sid
     */
    remove(sid) {
        try {
            const items = this.load().filter(b => b.sid !== sid);
            localStorage.setItem(getStorageKey(), JSON.stringify(items));
        } catch (e) {
            console.error('[Storage] Remove failed:', e);
        }
    },

    /**
     * Check if a sandbox is already stored
     * @param {string} sid
     * @returns {boolean}
     */
    has(sid) {
        return this.load().some(b => b.sid === sid);
    },

    /**
     * Clean up old entries (older than 2 hours)
     */
    cleanup() {
        try {
            const maxAge = 2 * 60 * 60 * 1000; // 2 hours
            const now = Date.now();
            const items = this.load().filter(b => {
                return b.created && (now - b.created) < maxAge;
            });
            localStorage.setItem(getStorageKey(), JSON.stringify(items));
        } catch (e) {
            console.error('[Storage] Cleanup failed:', e);
        }
    },
};

// ─────────────────────────────────────────────────────────────────────────────
// API
// ─────────────────────────────────────────────────────────────────────────────

const API = {
    /**
     * Submit a build request
     * @param {FormData} formData
     * @returns {Promise<{ok: boolean, error?: string, sandboxId?: string, ports?: Array, containerIds?: string[]}>}
     */
    async build(formData) {
        const response = await fetch(CONFIG.ENDPOINTS.build, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });
        return response.json();
    },

    /**
     * Stop a sandbox
     * @param {string} sid
     * @returns {Promise<{ok: boolean}>}
     */
    async stop(sid) {
        const response = await fetch(`${CONFIG.ENDPOINTS.stop}?sid=${encodeURIComponent(sid)}`, {
            credentials: 'same-origin',
        });
        return response.json();
    },

    /**
     * Get container stats
     * @param {string} cid
     * @returns {Promise<{cpu?: number, mem?: number, running?: boolean}>}
     */
    async stats(cid) {
        const response = await fetch(`${CONFIG.ENDPOINTS.stats}?cid=${encodeURIComponent(cid)}`, {
            cache: 'no-store',
            credentials: 'same-origin',
        });
        return response.json();
    },

    /**
     * Tail log file
     * @param {string} sid
     * @param {number} pos
     * @returns {Promise<{pos: number, lines: string[]} | null>}
     */
    async tail(sid, pos) {
        const response = await fetch(
            `${CONFIG.ENDPOINTS.tail}?sid=${encodeURIComponent(sid)}&pos=${pos}`,
            { cache: 'no-store', credentials: 'same-origin' }
        );
        if (response.status === 204) return null;
        if (!response.ok) return null;
        return response.json();
    },
};

// ─────────────────────────────────────────────────────────────────────────────
// SANDBOX CARD
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Manages a single sandbox card
 */
class SandboxCard {
    static counter = 0;

    /**
     * @param {string} sid - Sandbox ID
     * @param {HTMLElement} container - Container element
     */
    constructor(sid, container) {
        this.sid = sid;
        this.buildNum = ++SandboxCard.counter;
        this.logPos = 0;
        this.logLines = 0;
        this.chart = null;
        this.logTimer = null;
        this.statsTimer = null;
        this.destroyed = false;
        this.containerIds = [];

        this.createElement(container);
        this.setupChart();
    }

    /**
     * Create DOM elements from template
     * @param {HTMLElement} container
     */
    createElement(container) {
        const template = document.getElementById('sandbox-card-template');
        const clone = template.content.cloneNode(true);

        this.element = clone.querySelector('.card');
        this.element.dataset.sid = this.sid;
        this.element.querySelector('.build-num').textContent = this.buildNum;

        this.statusEl = this.element.querySelector('.card-status');
        this.servicesEl = this.element.querySelector('.card-services');
        this.logEl = this.element.querySelector('.log-output');
        this.chartCanvas = this.element.querySelector('.chart');
        this.stopBtn = this.element.querySelector('.btn--stop');
        this.closeBtn = this.element.querySelector('.btn--close');

        // Event listeners
        this.stopBtn.addEventListener('click', () => this.stop());
        this.closeBtn.addEventListener('click', () => this.close());

        container.prepend(this.element);
    }

    /**
     * Set up Chart.js instance
     */
    setupChart() {
        const ctx = this.chartCanvas.getContext('2d');
        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'CPU %',
                        data: [],
                        borderColor: '#4d8edb',
                        backgroundColor: 'rgba(77, 142, 219, 0.1)',
                        yAxisID: 'y1',
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: 'Memory MB',
                        data: [],
                        borderColor: '#5cff5c',
                        backgroundColor: 'rgba(92, 255, 92, 0.1)',
                        yAxisID: 'y2',
                        tension: 0.3,
                        fill: true,
                    },
                ],
            },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                plugins: {
                    legend: {
                        labels: { color: '#9da5b4', font: { size: 10 } },
                    },
                },
                scales: {
                    x: {
                        display: false,
                    },
                    y1: {
                        type: 'linear',
                        position: 'left',
                        suggestedMax: 100,
                        ticks: { color: '#4d8edb', font: { size: 10 } },
                        grid: { color: 'rgba(255,255,255,0.05)' },
                    },
                    y2: {
                        type: 'linear',
                        position: 'right',
                        ticks: { color: '#5cff5c', font: { size: 10 } },
                        grid: { drawOnChartArea: false },
                    },
                },
            },
        });
    }

    /**
     * Set status text and class
     * @param {string} text
     * @param {'building' | 'running' | 'stopped' | 'error'} state
     */
    setStatus(text, state) {
        this.statusEl.textContent = text;
        this.statusEl.className = `card-status status--${state}`;
    }

    /**
     * Render service links
     * @param {Array<{hostPort: number, service: string, containerPort: number}>} ports
     */
    renderServices(ports) {
        if (!ports || ports.length === 0) {
            this.servicesEl.innerHTML = '<span class="no-services">No exposed ports</span>';
            return;
        }

        const links = ports.map(p => {
            if (!p.hostPort) return '';
            const url = `http://${location.hostname}:${p.hostPort}`;
            const label = p.service ? p.service.substring(0, 15) : `Port ${p.containerPort}`;
            return `<a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="service-link">
                ${escapeHtml(label)} → :${p.hostPort}
            </a>`;
        }).filter(Boolean);

        this.servicesEl.innerHTML = links.join('');
    }

    /**
     * Append log lines
     * @param {string[]} lines
     */
    appendLog(lines) {
        if (!lines.length) return;

        for (const line of lines) {
            this.logLines++;
            // Trim old lines if too many
            if (this.logLines > CONFIG.MAX_LOG_LINES) {
                const firstNewline = this.logEl.textContent.indexOf('\n');
                if (firstNewline > 0) {
                    this.logEl.textContent = this.logEl.textContent.substring(firstNewline + 1);
                }
            }
            this.logEl.textContent += line + '\n';
        }

        // Auto-scroll to bottom
        this.logEl.scrollTop = this.logEl.scrollHeight;
    }

    /**
     * Update stats chart
     * @param {{cpu?: number, mem?: number}} stats
     */
    updateChart(stats) {
        if (!this.chart || this.destroyed) return;

        const time = new Date().toLocaleTimeString();
        const { labels, datasets } = this.chart.data;

        labels.push(time);
        datasets[0].data.push(stats.cpu ?? 0);
        datasets[1].data.push(stats.mem ? (stats.mem / (1024 * 1024)).toFixed(1) : 0);

        // Keep chart data bounded
        if (labels.length > CONFIG.MAX_CHART_POINTS) {
            labels.shift();
            datasets.forEach(d => d.data.shift());
        }

        this.chart.update('none'); // No animation for performance
    }

    /**
     * Start log polling
     */
    startLogPolling() {
        if (this.destroyed) return;

        const poll = async () => {
            if (this.destroyed) return;

            try {
                const result = await API.tail(this.sid, this.logPos);
                if (result && result.lines) {
                    this.logPos = result.pos;
                    this.appendLog(result.lines);
                }
            } catch (e) {
                console.error('[Log Poll]', e);
            }

            if (!this.destroyed) {
                this.logTimer = setTimeout(poll, CONFIG.LOG_POLL_MS);
            }
        };

        poll();
    }

    /**
     * Start stats polling
     * @param {string} containerId
     */
    startStatsPolling(containerId) {
        if (this.destroyed || !containerId) return;

        const poll = async () => {
            if (this.destroyed) return;

            try {
                const stats = await API.stats(containerId);
                if (stats.running !== false) {
                    this.updateChart(stats);
                } else {
                    // Container stopped
                    this.setStatus('Stopped', 'stopped');
                    this.stopPolling();
                }
            } catch (e) {
                console.error('[Stats Poll]', e);
            }

            if (!this.destroyed) {
                this.statsTimer = setTimeout(poll, CONFIG.STATS_POLL_MS);
            }
        };

        poll();
    }

    /**
     * Stop all polling
     */
    stopPolling() {
        if (this.logTimer) {
            clearTimeout(this.logTimer);
            this.logTimer = null;
        }
        if (this.statsTimer) {
            clearTimeout(this.statsTimer);
            this.statsTimer = null;
        }
    }

    /**
     * Stop the sandbox
     */
    async stop() {
        this.stopBtn.disabled = true;
        this.stopBtn.textContent = 'Stopping...';

        try {
            await API.stop(this.sid);
            this.setStatus('Stopped', 'stopped');
            this.stopPolling();
            Storage.remove(this.sid);
        } catch (e) {
            console.error('[Stop]', e);
            this.stopBtn.disabled = false;
            this.stopBtn.textContent = 'Stop';
        }
    }

    /**
     * Close and cleanup the card
     */
    close() {
        this.destroy();
        this.element.remove();
        Storage.remove(this.sid);
    }

    /**
     * Cleanup resources
     */
    destroy() {
        this.destroyed = true;
        this.stopPolling();

        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    /**
     * Start monitoring an active build
     * @param {Array} ports
     * @param {string[]} containerIds
     */
    startMonitoring(ports, containerIds) {
        this.containerIds = containerIds || [];
        this.setStatus('Running', 'running');
        this.renderServices(ports);
        this.startLogPolling();

        if (this.containerIds.length > 0) {
            this.startStatsPolling(this.containerIds[0]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// APPLICATION
// ─────────────────────────────────────────────────────────────────────────────

class App {
    constructor() {
        this.form = document.getElementById('build-form');
        this.repoInput = document.getElementById('repo-url');
        this.submitBtn = document.getElementById('submit-btn');
        this.sandboxesContainer = document.getElementById('sandboxes');

        this.activeCards = new Map(); // sid -> SandboxCard
        this.isBuilding = false;

        this.init();
    }

    /**
     * Initialize the application
     */
    init() {
        // Clean up old storage entries
        Storage.cleanup();

        // Restore previous builds
        this.restoreBuilds();

        // Form submission
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleBuild();
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            this.activeCards.forEach(card => card.destroy());
        });
    }

    /**
     * Set loading state
     * @param {boolean} loading
     */
    setLoading(loading) {
        this.isBuilding = loading;
        this.submitBtn.disabled = loading;

        const textEl = this.submitBtn.querySelector('.btn-text');
        const loadingEl = this.submitBtn.querySelector('.btn-loading');

        textEl.classList.toggle('hidden', loading);
        loadingEl.classList.toggle('hidden', !loading);
    }

    /**
     * Handle build form submission
     */
    async handleBuild() {
        if (this.isBuilding) return;

        const repo = this.repoInput.value.trim();
        if (!repo) {
            alert('Please enter a repository URL');
            this.repoInput.focus();
            return;
        }

        // Validate URL format
        try {
            const url = new URL(repo);
            if (url.protocol !== 'https:') {
                alert('Only HTTPS URLs are allowed');
                return;
            }
        } catch {
            alert('Invalid URL format');
            return;
        }

        this.setLoading(true);

        // Create card immediately for visual feedback
        const tempId = `temp_${Date.now()}`;
        const card = new SandboxCard(tempId, this.sandboxesContainer);
        card.setStatus('Building...', 'building');
        card.appendLog(['Starting build...']);

        try {
            const formData = new FormData(this.form);
            const result = await API.build(formData);

            if (!result.ok) {
                throw new Error(result.error || 'Build failed');
            }

            // Update card with real sandbox ID
            card.sid = result.sandboxId;
            card.element.dataset.sid = result.sandboxId;

            // Save to storage
            Storage.save({
                sid: result.sandboxId,
                repo: repo,
                ports: result.ports,
                containerIds: result.containerIds,
                created: Date.now(),
            });

            // Track active card
            this.activeCards.set(result.sandboxId, card);

            // Start monitoring
            card.startMonitoring(result.ports, result.containerIds);
            card.appendLog(['Build completed successfully']);

        } catch (e) {
            console.error('[Build]', e);
            card.setStatus(`Error: ${e.message}`, 'error');
            card.appendLog([`ERROR: ${e.message}`]);
            card.stopBtn.disabled = true;
        } finally {
            this.setLoading(false);
        }
    }

    /**
     * Restore builds from storage
     */
    restoreBuilds() {
        const builds = Storage.load();

        for (const build of builds) {
            // Skip if already showing
            if (this.activeCards.has(build.sid)) continue;

            const card = new SandboxCard(build.sid, this.sandboxesContainer);
            card.setStatus('Running', 'running');
            card.renderServices(build.ports);

            this.activeCards.set(build.sid, card);

            // Start monitoring
            card.startLogPolling();
            if (build.containerIds && build.containerIds.length > 0) {
                card.startStatsPolling(build.containerIds[0]);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// INITIALIZATION
// ─────────────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    window.dockaApp = new App();
});
