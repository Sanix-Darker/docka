<?php

$config = require __DIR__ . '/../config/config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Docka</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
  <div class="container">
    <header>
      <h1><span class="blinking-cursor"></span> DOCKA</h1>
      <i><b><small>Test any public git repository with a dockerfile/docker-compose.yml in it.(github/gitlab/bitucket)</small></b></i>
    </header>

    <form id="build-form">
      <div class="form-group">
        <div class="input-wrapper">
          <input type="url" id="repo-url" name="repo" required
                value="https://github.com/Sanix-Darker/test-docker-project"
                placeholder="https://github.com/sanix-darker/"
                aria-describedby="url-hint">
          <input type="text" name="ref" value="master" placeholder="master OR v1.2.3">
        </div>
      </div>

      <details>
        <summary style="cursor: pointer">.env overrides (optional) [click to expand/close]</summary>
        <textarea id="env-text" name="env" rows="6" style="width:100%" spellcheck="false"></textarea>
      </details>

      <div style="display: flex; justify-content: flex-end;">
        <button type="submit" id="submit-btn" class="btn">
          Build and Run
        </button>
      </div>
    </form>

    <!-- container for many sandboxes -->
    <div id="sandboxes"></div>

<!--
    <div class="terminal">
      <div class="terminal-header">
        <span>General Output</span>
        <span id="status">Ready</span>
      </div>
      <div id="log-viewer" class="terminal-body" aria-live="polite">
        <span class="blinking-cursor"></span>
      </div>
      <div id="service-container" class="service-links hidden">
        <h2>Available Services:</h2>
        <ul id="service-links"></ul>
      </div>
    </div>
-->
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
