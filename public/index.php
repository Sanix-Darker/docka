<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/../vendor/autoload.php';

use App\Utils;

$config = require __DIR__ . '/../config/config.php';

// Generate CSRF token
$csrfToken = Utils::generateCsrfToken();

// Get rate limit info for display
$rateLimiter = new \App\RateLimiter($config);
$clientIp = Utils::getClientIp();
$remaining = $rateLimiter->getRemaining($clientIp, 'build_hour', $config['rate_limit']['builds_per_ip_per_hour'] ?? 10, 3600);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Build and test any public git repository with Docker">
    <meta name="robots" content="noindex, nofollow">
    <title>Docka - Docker Sandbox Runner</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>
                <span class="logo-icon">▸</span>
                DOCKA
            </h1>
            <p class="tagline">Build &amp; test any public git repository with a Dockerfile or docker-compose.yml</p>
        </header>

        <main>
            <form id="build-form" class="build-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="form-row">
                    <div class="form-group form-group--repo">
                        <label for="repo-url">Repository URL</label>
                        <input
                            type="url"
                            id="repo-url"
                            name="repo"
                            required
                            placeholder="https://github.com/user/repo"
                            pattern="https://.*"
                            title="Must be an HTTPS URL"
                        >
                    </div>

                    <div class="form-group form-group--ref">
                        <label for="ref">Branch / Tag</label>
                        <input
                            type="text"
                            id="ref"
                            name="ref"
                            value="main"
                            placeholder="main, master, v1.0.0"
                            pattern="[a-zA-Z0-9._/-]+"
                        >
                    </div>
                </div>

                <details class="env-section">
                    <summary>Environment Variables (optional)</summary>
                    <div class="env-content">
                        <textarea
                            id="env-text"
                            name="env"
                            rows="5"
                            placeholder="KEY=value&#10;DATABASE_URL=postgres://..."
                            spellcheck="false"
                        ></textarea>
                        <p class="hint">One variable per line. Overrides .env file from repository.</p>
                    </div>
                </details>

                <div class="form-actions">
                    <span class="rate-limit-info" title="Builds remaining this hour">
                        <?= $remaining ?> builds left
                    </span>
                    <button type="submit" id="submit-btn" class="btn btn--primary">
                        <span class="btn-text">Build &amp; Run</span>
                        <span class="btn-loading hidden">
                            <span class="spinner"></span>
                            Building...
                        </span>
                    </button>
                </div>
            </form>

            <section id="sandboxes" class="sandboxes" aria-label="Running sandboxes"></section>
        </main>

        <footer>
            <p>
                Made by <a href="https://github.com/sanix-darker" target="_blank" rel="noopener">sanixdk</a>
                · <a href="https://github.com/sanix-darker/docka" target="_blank" rel="noopener">Source</a>
            </p>
            <p class="footer-note">
                Containers auto-terminate after <?= round(($config['container_ttl_seconds'] ?? 3600) / 60) ?> minutes.
                Max <?= $config['limits']['memory'] ?? '512m' ?> RAM, <?= $config['limits']['cpus'] ?? '0.5' ?> CPU per container.
            </p>
        </footer>
    </div>

    <template id="sandbox-card-template">
        <article class="card">
            <header class="card-header">
                <h2 class="card-title">Build #<span class="build-num"></span></h2>
                <span class="card-status status--building">Building...</span>
            </header>

            <nav class="card-services"></nav>

            <details class="card-logs" open>
                <summary>Logs</summary>
                <pre class="log-output"></pre>
            </details>

            <div class="card-chart">
                <canvas class="chart" width="380" height="120"></canvas>
            </div>

            <footer class="card-actions">
                <button type="button" class="btn btn--stop" title="Stop container">Stop</button>
                <button type="button" class="btn btn--close" title="Close this card">Close</button>
            </footer>
        </article>
    </template>

    <script src="assets/js/app.js"></script>
</body>
</html>
