<?php

$config = require __DIR__ . '/../config/config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Docka</title>
  <style>
    :root {
      --bg-color: #0f1419;
      --text-color: #e0e0e0;
      --terminal-bg: #0a0e14;
      --terminal-text: #5cff5c;
      --error-color: #ff5c5c;
      --primary-color: #4d8edb;
      --button-hover: #5da8ff;
      --button-active: #3a6ca8;
      --border-color: #2a2e35;
      --scrollbar-thumb: #4d5666;
      --scrollbar-track: #1a1f26;
      --link-color: #5cbcff;
      --link-hover: #7dcfff;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
      background-color: var(--bg-color);
      color: var(--text-color);
      line-height: 1.6;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding: 1rem;
    }

    header {
      margin-bottom: 1.5rem;
      padding-bottom: 0.75rem;
      border-bottom: 1px solid var(--border-color);
    }

    h1 {
      font-size: 1.5rem;
      font-weight: 600;
      color: var(--terminal-text);
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    h1::before {
      content: ">";
      font-size: 1.25rem;
      opacity: 0.8;
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      width: 100%;
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    form {
      margin-bottom: 1rem;
      padding: 1rem;
      background-color: rgba(255, 255, 255, 0.03);
      border-radius: 4px;
      border: 1px solid var(--border-color);
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }

    @media (min-width: 640px) {
      .form-group {
        flex-direction: row;
        align-items: flex-start;
      }
    }

    label {
      font-size: 0.9rem;
      font-weight: 500;
      flex-shrink: 0;
      padding-top: 0.5rem;
    }

    @media (min-width: 640px) {
      label {
        width: 100px;
      }
    }

    .input-wrapper {
      position: relative;
      flex: 1;
    }

    input[type="url"] {
      width: 100%;
      padding: 0.5rem 0.75rem;
      background-color: var(--terminal-bg);
      color: var(--text-color);
      border: 1px solid var(--border-color);
      border-radius: 4px;
      font-family: inherit;
      font-size: 0.9rem;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    input[type="url"]:focus {
      outline: none;
      border-color: var(--primary-color);
      box-shadow: 0 0 0 2px rgba(77, 142, 219, 0.25);
    }

    .btn {
      padding: 0.5rem 1rem;
      background-color: var(--primary-color);
      color: white;
      border: none;
      border-radius: 4px;
      font-family: inherit;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: background-color 0.2s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .btn:hover {
      background-color: var(--button-hover);
    }

    .btn:active {
      background-color: var(--button-active);
    }

    .btn:focus {
      outline: none;
      box-shadow: 0 0 0 2px rgba(77, 142, 219, 0.25);
    }

    .btn:disabled {
      background-color: #3a5071;
      cursor: not-allowed;
      opacity: 0.7;
    }

    .terminal {
      flex: 1;
      background-color: var(--terminal-bg);
      border-radius: 4px;
      border: 1px solid var(--border-color);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: 300px;
    }

    .terminal-header {
      background-color: rgba(255, 255, 255, 0.05);
      padding: 0.5rem 1rem;
      border-bottom: 1px solid var(--border-color);
      font-size: 0.85rem;
      color: #9da5b4;
      display: flex;
      justify-content: space-between;
    }

    .terminal-body {
      flex: 1;
      padding: 0.75rem;
      font-family: 'SF Mono', Monaco, Menlo, Consolas, 'Courier New', monospace;
      font-size: 0.85rem;
      line-height: 1.5;
      overflow-y: auto;
      color: var(--terminal-text);
      white-space: pre-wrap;
      word-break: break-word;
      position: relative;
      height: 100%;
      min-height: 250px;
      max-height: calc(100vh - 250px);
    }

    /* Custom scrollbar */
    .terminal-body::-webkit-scrollbar {
      width: 8px;
    }

    .terminal-body::-webkit-scrollbar-track {
      background-color: var(--scrollbar-track);
    }

    .terminal-body::-webkit-scrollbar-thumb {
      background-color: var(--scrollbar-thumb);
      border-radius: 4px;
    }

    .terminal-body::-webkit-scrollbar-thumb:hover {
      background-color: #5d677a;
    }

    .error-text {
      color: var(--error-color);
    }

    .service-links {
      margin-top: 1rem;
      padding: 0.75rem;
      background-color: rgba(255, 255, 255, 0.05);
      border-radius: 4px;
      border-top: 1px solid var(--border-color);
    }

    .service-links h2 {
      font-size: 0.9rem;
      margin-bottom: 0.5rem;
      color: #9da5b4;
    }

    .service-links ul {
      list-style: none;
    }

    .service-links li {
      margin-bottom: 0.5rem;
    }

    .service-link {
      color: var(--link-color);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: color 0.2s;
    }

    .service-link:hover {
      color: var(--link-hover);
      text-decoration: underline;
    }

    .service-link::before {
      content: "→";
      opacity: 0.7;
    }

    /* Spinner animation */
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .spinner {
      display: inline-block;
      width: 1rem;
      height: 1rem;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      border-top-color: white;
      animation: spin 1s linear infinite;
    }

    .blinking-cursor {
      display: inline-block;
      width: 0.6em;
      height: 1.2em;
      margin-left: 2px;
      background-color: var(--terminal-text);
      animation: blink 1s step-end infinite;
      vertical-align: middle;
    }

    @keyframes blink {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }

    .hidden {
      display: none;
    }
  </style>
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
                placeholder="https://github.com/username/repository"
                aria-describedby="url-hint">
        </div>
      </div>
      <div style="display: flex; justify-content: flex-end;">
        <button type="submit" id="submit-btn" class="btn">
          Build and Test
        </button>
      </div>
    </form>

    <div class="terminal">
      <div class="terminal-header">
        <span>Output</span>
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

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // DOM elements
      const form = document.getElementById('build-form');
      const repoInput = document.getElementById('repo-url');
      const submitBtn = document.getElementById('submit-btn');
      const logViewer = document.getElementById('log-viewer');
      const statusText = document.getElementById('status');
      const serviceContainer = document.getElementById('service-container');
      const serviceList = document.getElementById('service-links');

      // Clear log and cursor
      function clearLog() {
        logViewer.innerHTML = '';
        appendToLog(''); // Add blinking cursor
      }

      // Append text to the log viewer
      function appendToLog(text, isError = false) {
        // Remove existing cursor
        const existingCursor = logViewer.querySelector('.blinking-cursor');
        if (existingCursor) {
          existingCursor.remove();
        }

        // Create text element
        const logLine = document.createElement('div');
        logLine.textContent = text;

        if (isError) {
          logLine.classList.add('error-text');
        }

        logViewer.appendChild(logLine);

        // Add blinking cursor to the end
        const cursor = document.createElement('span');
        cursor.classList.add('blinking-cursor');
        logViewer.appendChild(cursor);

        // Auto-scroll to bottom
        logViewer.scrollTop = logViewer.scrollHeight;
      }

      // Show loading state
      function setLoading(isLoading) {
        if (isLoading) {
          statusText.innerHTML = '<span class="spinner"></span> Building...';
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<span class="spinner"></span> Building...';
        } else {
          statusText.textContent = 'Ready';
          submitBtn.disabled = false;
          submitBtn.textContent = 'Build and Test';
        }
      }

      // Display services as links
      function displayServices(services) {
        if (!services || services.length === 0) {
          serviceContainer.classList.add('hidden');
          return;
        }

        // Clear previous services
        serviceList.innerHTML = '';

        // Add each service as a link
        services.forEach(service => {
          const li = document.createElement('li');
          const link = document.createElement('a');

          link.href = `http://${window.location.hostname}:${service.hostPort}`;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.classList.add('service-link');
          link.textContent = `${service.service} (${service.containerPort} → ${service.hostPort})`;

          li.appendChild(link);
          serviceList.appendChild(li);
        });

        // Show the services container
        serviceContainer.classList.remove('hidden');
      }

      // Handle form submission
      form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const repoUrl = repoInput.value.trim();

        if (!repoUrl) {
          appendToLog('Error: Repository URL is required', true);
          return;
        }

        // Clear previous log and set loading state
        clearLog();
        setLoading(true);
        serviceContainer.classList.add('hidden');

        // Create form data
        const formData = new FormData();
        formData.append('repo', repoUrl);

        try {
          appendToLog(`> Building from repository: ${repoUrl}`);
          appendToLog('> Sending build request...');

          // Send request to the build API
          const response = await fetch('/build.php', {
            method: 'POST',
            body: formData
          });

          const data = await response.json();

          if (data.ok) {
            // Success - display log and services
            appendToLog('> Build completed successfully!');

            if (data.log) {
              appendToLog('\nBuild Log:');
              appendToLog(data.log);
            }

            if (data.ports && data.ports.length > 0) {
              appendToLog('\n> Services are now available:');
              displayServices(data.ports);
            } else {
              appendToLog('\n> No services exposed.');
            }
          } else {
            // Error - display error message
            appendToLog('> Build failed!', true);
            if (data.error) {
              appendToLog(`Error: ${data.error}`, true);
            }
          }
        } catch (error) {
          // Network or other error
          appendToLog('> Request failed!', true);
          appendToLog(`Error: ${error.message || 'Unknown error occurred'}`, true);
        } finally {
          // Reset loading state
          setLoading(false);
        }
      });

      // Initial setup
      clearLog();
      appendToLog('> Docka ready...');
      appendToLog('> Enter a Git repository URL and click "Build and Test".');
    });
  </script>
</body>
</html>
