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
      <h1>DOCKA -- <small>Test any public repository with a dockerfile/docker-compose.yml in it</small></h1>
    </header>

    <form id="build-form">
      <div class="form-group">
        <div class="input-wrapper">
          <input type="url" id="repo-url" name="repo" required
                placeholder="https://github.com/sanix-darker/"
                aria-describedby="url-hint">
          <input type="text" name="ref" placeholder="master OR v1.2.3">
        </div>
      </div>
      <div style="display: flex; justify-content: flex-end;">
        <button type="submit" id="submit-btn" class="btn">
          Build and Test
        </button>
      </div>
    </form>

    <!-- container for many sandboxes -->
    <div id="sandboxes"></div>

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
  </div>

  <script src="assets/js/app.js"></script>
</body>
</html>
