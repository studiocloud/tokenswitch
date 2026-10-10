pub const INDEX_HTML: &str = r#"<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TokenSwitch — LLM Multi-Account Switcher & Streaming Proxy</title>
  <style>
    :root {
      --bg: #0b0d10;
      --card-bg: #13171d;
      --card-header: #171c23;
      --border: #21262d;
      --border-focus: #388bfd;
      --orange: #ff5500;
      --orange-hover: #e04b00;
      --text: #f0f6fc;
      --text-muted: #8b949e;
      --text-dim: #6e7681;
      --green: #238636;
      --red: #da3633;
      --yellow: #9e6a03;
      --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-sans);
      font-size: 14px;
      line-height: 1.5;
      min-height: 100vh;
      padding-bottom: 40px;
    }

    .nav {
      background: var(--card-bg);
      border-bottom: 1px solid var(--border);
      padding: 12px 24px;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .nav-inner {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: var(--text);
      font-weight: 700;
      font-size: 16px;
    }
    .brand-badge {
      background: var(--orange);
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      padding: 2px 7px;
      border-radius: 4px;
      letter-spacing: 0.5px;
    }
    .nav-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .btn {
      font-family: var(--font-sans);
      font-size: 13px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--card-bg);
      color: var(--text);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: background 0.15s, border-color 0.15s;
    }
    .btn:hover {
      background: #1f242c;
      border-color: #30363d;
    }
    .btn-primary {
      background: var(--orange);
      border-color: var(--orange);
      color: #ffffff;
    }
    .btn-primary:hover {
      background: var(--orange-hover);
      border-color: var(--orange-hover);
    }
    .btn-sm {
      padding: 4px 8px;
      font-size: 12px;
      border-radius: 4px;
    }
    .btn-danger {
      color: #f85149;
      border-color: rgba(248, 81, 73, 0.3);
      background: rgba(248, 81, 73, 0.08);
    }
    .btn-danger:hover {
      background: rgba(248, 81, 73, 0.16);
      border-color: #f85149;
    }

    select, input, textarea {
      font-family: var(--font-sans);
      font-size: 13px;
      background: #0b0d10;
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 6px 10px;
    }
    select:focus, input:focus, textarea:focus {
      outline: none;
      border-color: var(--orange);
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 16px;
    }

    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
      flex-wrap: wrap;
      gap: 12px;
    }
    .page-header h1 {
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
    }
    .page-header p {
      color: var(--text-muted);
      font-size: 13px;
      margin-top: 2px;
    }

    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-bottom: 20px;
      overflow: hidden;
    }
    .card-head {
      background: var(--card-header);
      padding: 12px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .card-title {
      font-size: 14px;
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-body {
      padding: 16px;
    }

    .route-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }
    .route-box {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 12px;
    }
    .route-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-dim);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .route-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
    }
    .route-val {
      font-family: var(--font-mono);
      font-size: 12px;
      color: #ff9d66;
      word-break: break-all;
    }

    .tabs {
      display: flex;
      gap: 4px;
      margin-bottom: 10px;
    }
    .tab {
      background: none;
      border: 1px solid transparent;
      color: var(--text-muted);
      font-size: 12px;
      font-weight: 600;
      padding: 4px 10px;
      border-radius: 4px;
      cursor: pointer;
    }
    .tab.active {
      background: #1f242c;
      color: var(--text);
      border-color: var(--border);
    }
    pre.code {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 12px;
      font-family: var(--font-mono);
      font-size: 12px;
      color: #e6edf3;
      white-space: pre-wrap;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      text-align: left;
    }
    th {
      color: var(--text-muted);
      font-weight: 600;
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      background: #171c23;
    }
    td {
      padding: 12px;
      border-bottom: 1px solid #1a1e24;
      vertical-align: middle;
    }
    tr:hover td {
      background: #161b22;
    }

    .pill {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 4px;
      line-height: 1.4;
    }
    .pill-active {
      background: #23201e;
      color: #ff8547;
      border: 1px solid #4d2612;
    }
    .pill-cooldown {
      background: #2a2211;
      color: #e3b341;
      border: 1px solid #4d380b;
    }
    .pill-standby {
      background: #1a1e24;
      color: var(--text-muted);
      border: 1px solid var(--border);
    }
    .pill-disabled {
      background: #161b22;
      color: var(--text-dim);
      border: 1px solid #21262d;
    }
    .pill-oauth {
      background: #11281a;
      color: #3fb950;
      border: 1px solid #194827;
    }
    .pill-key {
      background: #161b22;
      color: var(--text-muted);
      border: 1px solid var(--border);
    }

    .stream-box {
      background: #0b0d10;
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 14px;
      font-family: var(--font-mono);
      font-size: 13px;
      line-height: 1.6;
      color: #e6edf3;
      min-height: 160px;
      max-height: 380px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .modal-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .modal {
      background: var(--card-bg);
      border: 1px solid #30363d;
      border-radius: 8px;
      width: 100%;
      max-width: 500px;
      padding: 20px;
    }
    .modal-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 14px;
    }
    .form-group {
      margin-bottom: 12px;
    }
    .form-group label {
      display: block;
      font-size: 11px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%;
    }

    .toast {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: #1f242c;
      color: var(--text);
      border: 1px solid var(--border);
      padding: 10px 16px;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      display: none;
      z-index: 2000;
    }
  </style>
</head>
<body>

  <nav class="nav">
    <div class="nav-inner">
      <a href="/" class="brand">
        <span class="brand-badge">TS</span>
        <span>TokenSwitch</span>
      </a>

      <div class="nav-right">
        <select id="strategySelect" onchange="updateStrategy()">
          <option value="priority_fallback">Priority Fallback</option>
          <option value="round_robin">Round Robin</option>
          <option value="least_errors">Least Errors</option>
        </select>
        <button class="btn" onclick="forceSwitch()">Force Switch</button>
        <button class="btn btn-primary" onclick="openOpenAiAuthModal()">
          + Connect OpenAI Account
        </button>
        <button class="btn" onclick="openAddModal()">+ Add Route</button>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Multi-Account Gateway & Failover Switcher</h1>
        <p>Direct OpenAI account OAuth, automatic 429 quota rotation, and real-time SSE streaming proxy.</p>
      </div>
    </div>

    <!-- Active Route Telemetry -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">
          <span>Active Route</span>
          <span id="activeAccountPill" class="pill pill-active">Detecting...</span>
        </div>
      </div>
      <div class="card-body">
        <div class="route-grid">
          <div class="route-box">
            <div class="route-label">Base URL</div>
            <div class="route-row">
              <span class="route-val" id="exportBaseUrl">...</span>
              <button class="btn btn-sm" onclick="copyText('exportBaseUrl')">Copy</button>
            </div>
          </div>
          <div class="route-box">
            <div class="route-label">Bearer Token / Key</div>
            <div class="route-row">
              <span class="route-val" id="exportApiKey">...</span>
              <button class="btn btn-sm" onclick="copyText('exportApiKey')">Copy</button>
            </div>
          </div>
          <div class="route-box">
            <div class="route-label">Model Target</div>
            <div class="route-row">
              <span class="route-val" id="exportModel">...</span>
              <button class="btn btn-sm" onclick="copyText('exportModel')">Copy</button>
            </div>
          </div>
        </div>

        <div class="tabs">
          <button class="tab active" onclick="showTab('env', event)">.env</button>
          <button class="tab" onclick="showTab('json', event)">JSON</button>
          <button class="tab" onclick="showTab('bash', event)">Bash</button>
          <button class="tab" onclick="showTab('powershell', event)">PowerShell</button>
        </div>

        <pre class="code" id="exportSnippet">Loading configuration snippet...</pre>
      </div>
    </div>

    <!-- Configured Account Pool Table -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">Configured Account Pool</div>
        <div style="display: flex; gap: 8px;">
          <button class="btn btn-sm" onclick="fetchStatus()">Refresh</button>
        </div>
      </div>
      <div style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th>Status</th>
              <th>Account</th>
              <th>Type</th>
              <th>Model</th>
              <th>Priority</th>
              <th>Cooldown</th>
              <th>Success / Fail</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="accountsTableBody">
            <tr><td colspan="8" style="text-align: center; color: var(--text-dim); padding: 24px;">Loading accounts...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Live SSE Streaming Console -->
    <div class="card">
      <div class="card-head">
        <div class="card-title">Live SSE Streaming Console</div>
        <div style="font-size: 12px; color: var(--text-muted);">
          <span id="streamAccountInfo">Route: -</span> | <span id="streamStats">0 tokens</span>
        </div>
      </div>
      <div class="card-body">
        <div style="display: flex; gap: 8px; margin-bottom: 12px;">
          <input type="text" id="testPrompt" style="flex: 1;" value="Count from 1 to 5 with short cheerful cheer for each number." />
          <button class="btn btn-primary" id="streamBtn" onclick="runStreamingTest()">
            Stream
          </button>
        </div>
        <div class="stream-box" id="streamOutput">Click "Stream" to test real-time SSE token delivery...</div>
      </div>
    </div>
  </div>

  <!-- Sign In with OpenAI Account Modal -->
  <div class="modal-overlay" id="openAiAuthModal">
    <div class="modal">
      <div class="modal-title">Connect OpenAI / ChatGPT Account</div>
      <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">
        Paste your token below, or run <code>tokenswitch-login.exe</code> in terminal to log in via browser and auto-sync.
      </p>

      <div class="form-group">
        <label>Account Label</label>
        <input type="text" id="oaAuthName" value="OpenAI Account" placeholder="e.g. Personal Pro" />
      </div>

      <div class="form-group">
        <label>OAuth Access Token or Refresh Token</label>
        <textarea id="oaAuthToken" rows="3" placeholder="Paste access token (eyJ...) or refresh token" style="font-family: var(--font-mono); font-size: 11px;"></textarea>
      </div>

      <div class="form-group">
        <label>Optional Refresh Token (For Silent Auto-Renewal)</label>
        <input type="password" id="oaAuthRefreshToken" placeholder="OAuth refresh token" />
      </div>

      <div style="display: flex; gap: 8px;">
        <div class="form-group" style="flex: 1;">
          <label>Model</label>
          <select id="oaAuthModel">
            <option value="gpt-4o" selected>gpt-4o</option>
            <option value="gpt-4o-mini">gpt-4o-mini</option>
            <option value="o1">o1</option>
            <option value="o3-mini">o3-mini</option>
          </select>
        </div>
        <div class="form-group" style="flex: 1;">
          <label>Priority</label>
          <input type="number" id="oaAuthPriority" value="1" min="1" max="50" />
        </div>
      </div>

      <div id="oaAuthStatus" style="font-size: 12px; margin-bottom: 12px; display: none;"></div>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
        <button class="btn" onclick="closeOpenAiAuthModal()">Cancel</button>
        <button class="btn btn-primary" onclick="connectOpenAiAuth()">Connect Account</button>
      </div>
    </div>
  </div>

  <!-- Add Custom Route Modal -->
  <div class="modal-overlay" id="accountModal">
    <div class="modal">
      <div class="modal-title">Add Custom Route</div>

      <div class="form-group">
        <label>Account ID (Slug)</label>
        <input type="text" id="accId" placeholder="e.g. groq-backup" />
      </div>

      <div class="form-group">
        <label>Account Name</label>
        <input type="text" id="accName" placeholder="e.g. Groq Llama 3" />
      </div>

      <div class="form-group">
        <label>Base URL</label>
        <input type="text" id="accBaseUrl" value="https://api.openai.com/v1" />
      </div>

      <div class="form-group">
        <label>API Key / Bearer Token</label>
        <input type="password" id="accApiKey" placeholder="API key or token" />
      </div>

      <div class="form-group">
        <label>Model Name</label>
        <input type="text" id="accModel" value="gpt-4o-mini" />
      </div>

      <div style="display: flex; gap: 8px;">
        <div class="form-group" style="flex: 1;">
          <label>Priority</label>
          <input type="number" id="accPriority" value="2" min="1" />
        </div>
        <div class="form-group" style="flex: 1;">
          <label>Cooldown (sec)</label>
          <input type="number" id="accCooldown" value="60" min="5" />
        </div>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
        <button class="btn" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="saveCustomAccount()">Save Route</button>
      </div>
    </div>
  </div>

  <div class="toast" id="toastMsg">Action completed</div>

  <script>
    let currentExportData = null;
    let activeTab = 'env';

    async function fetchStatus() {
      try {
        const res = await fetch('/api/status');
        const data = await res.json();
        renderAccounts(data.accounts);
        document.getElementById('strategySelect').value = data.strategy;
        await fetchExportRoute();
      } catch (err) {
        console.error(err);
      }
    }

    async function fetchExportRoute() {
      try {
        const res = await fetch('/api/export');
        if (!res.ok) {
          document.getElementById('activeAccountPill').innerText = 'No Route Active';
          document.getElementById('activeAccountPill').className = 'pill pill-disabled';
          return;
        }
        const data = await res.json();
        currentExportData = data;

        document.getElementById('exportBaseUrl').innerText = data.route.base_url;
        document.getElementById('exportApiKey').innerText = data.route.api_key;
        document.getElementById('exportModel').innerText = data.route.model;

        document.getElementById('activeAccountPill').innerText = `Active: ${data.route.name}`;
        document.getElementById('activeAccountPill').className = 'pill pill-active';

        renderTabSnippet();
      } catch (err) {
        console.error(err);
      }
    }

    function renderTabSnippet() {
      if (!currentExportData) return;
      const el = document.getElementById('exportSnippet');
      if (activeTab === 'env') el.innerText = currentExportData.env_format;
      else if (activeTab === 'json') el.innerText = currentExportData.json_format;
      else if (activeTab === 'bash') el.innerText = currentExportData.bash_export;
      else if (activeTab === 'powershell') el.innerText = currentExportData.powershell_export;
    }

    function showTab(name, e) {
      activeTab = name;
      document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
      e.target.classList.add('active');
      renderTabSnippet();
    }

    function renderAccounts(accounts) {
      const tbody = document.getElementById('accountsTableBody');
      if (!accounts || accounts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--text-dim); padding: 24px;">No accounts found. Click "+ Connect OpenAI Account" to add one.</td></tr>';
        return;
      }

      tbody.innerHTML = accounts.map(acc => {
        let statusBadge = '';
        if (!acc.enabled) {
          statusBadge = '<span class="pill pill-disabled">Disabled</span>';
        } else if (acc.is_cooling_down) {
          statusBadge = `<span class="pill pill-cooldown">Cooldown (${acc.cooldown_remaining_secs}s)</span>`;
        } else if (acc.is_active_route) {
          statusBadge = '<span class="pill pill-active">🟢 Active</span>';
        } else {
          statusBadge = '<span class="pill pill-standby">Standby</span>';
        }

        const isOAuth = acc.auth_type === 'chatgpt_oauth';
        const authBadge = isOAuth
          ? '<span class="pill pill-oauth">OAuth</span>'
          : '<span class="pill pill-key">API Key</span>';

        return `
          <tr>
            <td>${statusBadge}</td>
            <td>
              <strong style="color:var(--text);">${escapeHtml(acc.name)}</strong><br/>
              <span style="font-size:11px; color:var(--text-dim); font-family:var(--font-mono);">${escapeHtml(acc.id)}</span>
            </td>
            <td>${authBadge}</td>
            <td><code>${escapeHtml(acc.model)}</code></td>
            <td>P${acc.priority}</td>
            <td>${acc.cooldown_secs}s</td>
            <td>
              <span style="color:#3fb950;">${acc.total_successes}</span> / 
              <span style="color:#f85149;">${acc.total_failures}</span>
            </td>
            <td>
              <div style="display:flex; gap:4px;">
                <button class="btn btn-sm" title="Simulate 429 Rate Limit" onclick="simulate429('${acc.id}')">429</button>
                <button class="btn btn-sm" onclick="toggleAccount('${acc.id}', ${!acc.enabled})">${acc.enabled ? 'Pause' : 'Resume'}</button>
                <button class="btn btn-sm btn-danger" onclick="deleteAccount('${acc.id}')">Del</button>
              </div>
            </td>
          </tr>
        `;
      }).join('');
    }

    async function forceSwitch() {
      const res = await fetch('/api/switch', { method: 'POST' });
      const data = await res.json();
      showToast(`Switched active route to: ${data.name || data.id}`);
      fetchStatus();
    }

    async function updateStrategy() {
      const val = document.getElementById('strategySelect').value;
      await fetch('/api/strategy', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ strategy: val })
      });
      showToast(`Strategy: ${val}`);
      fetchStatus();
    }

    async function simulate429(id) {
      await fetch('/api/report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, status_code: 429, error_message: 'Simulated 429 Rate Limit' })
      });
      showToast(`Simulated 429 on ${id}. Switched!`);
      fetchStatus();
    }

    async function toggleAccount(id, enable) {
      await fetch(`/api/accounts/${id}/toggle`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ enabled: enable })
      });
      fetchStatus();
    }

    async function deleteAccount(id) {
      if (!confirm(`Delete account "${id}"?`)) return;
      await fetch(`/api/accounts/${id}`, { method: 'DELETE' });
      showToast(`Deleted ${id}`);
      fetchStatus();
    }

    function openOpenAiAuthModal() {
      document.getElementById('oaAuthToken').value = '';
      document.getElementById('oaAuthRefreshToken').value = '';
      document.getElementById('oaAuthStatus').style.display = 'none';
      document.getElementById('openAiAuthModal').style.display = 'flex';
    }

    function closeOpenAiAuthModal() {
      document.getElementById('openAiAuthModal').style.display = 'none';
    }

    async function connectOpenAiAuth() {
      const token = document.getElementById('oaAuthToken').value.trim();
      const refreshToken = document.getElementById('oaAuthRefreshToken').value.trim();
      const name = document.getElementById('oaAuthName').value.trim();
      const model = document.getElementById('oaAuthModel').value;
      const priority = parseInt(document.getElementById('oaAuthPriority').value, 10) || 1;
      const statusEl = document.getElementById('oaAuthStatus');

      if (!token && !refreshToken) {
        alert('Please provide an Access Token or Refresh Token.');
        return;
      }

      statusEl.style.display = 'block';
      statusEl.style.color = 'var(--text-muted)';
      statusEl.innerText = 'Connecting account...';

      const res = await fetch('/api/accounts/oauth-connect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          token: token,
          refresh_token: refreshToken,
          name: name,
          model: model,
          priority: priority,
          cooldown_secs: 60
        })
      });

      const data = await res.json();
      if (res.ok && data.status === 'ok') {
        closeOpenAiAuthModal();
        showToast(data.message || 'Connected!');
        fetchStatus();
      } else {
        statusEl.style.color = '#f85149';
        statusEl.innerText = data.message || 'Failed to connect.';
      }
    }

    function openAddModal() {
      document.getElementById('accId').value = 'acc-' + Math.floor(Math.random() * 1000);
      document.getElementById('accountModal').style.display = 'flex';
    }

    function closeModal() {
      document.getElementById('accountModal').style.display = 'none';
    }

    async function saveCustomAccount() {
      const acc = {
        id: document.getElementById('accId').value.trim(),
        name: document.getElementById('accName').value.trim(),
        base_url: document.getElementById('accBaseUrl').value.trim(),
        api_key: document.getElementById('accApiKey').value.trim(),
        model: document.getElementById('accModel').value.trim(),
        priority: parseInt(document.getElementById('accPriority').value, 10) || 1,
        cooldown_secs: parseInt(document.getElementById('accCooldown').value, 10) || 60,
        enabled: true
      };

      await fetch('/api/accounts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(acc)
      });
      closeModal();
      showToast('Account saved');
      fetchStatus();
    }

    async function runStreamingTest() {
      const prompt = document.getElementById('testPrompt').value.trim();
      if (!prompt) return;

      const outputEl = document.getElementById('streamOutput');
      const infoEl = document.getElementById('streamAccountInfo');
      const statsEl = document.getElementById('streamStats');
      const btn = document.getElementById('streamBtn');

      outputEl.innerText = '';
      btn.disabled = true;

      const startTime = performance.now();
      let tokenCount = 0;

      try {
        const response = await fetch('/v1/chat/completions', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            messages: [{ role: 'user', content: prompt }],
            stream: true
          })
        });

        const activeName = response.headers.get('X-Routed-Account') || 'Active Account';
        const activeModel = response.headers.get('X-Routed-Model') || 'Model';
        infoEl.innerText = `Routed: ${activeName} (${activeModel})`;

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith(':')) continue;
            if (trimmed === 'data: [DONE]') break;
            if (trimmed.startsWith('data: ')) {
              try {
                const chunk = JSON.parse(trimmed.substring(6));
                const text = chunk.choices?.[0]?.delta?.content || '';
                if (text) {
                  outputEl.innerText += text;
                  tokenCount++;
                  outputEl.scrollTop = outputEl.scrollHeight;
                  const elapsed = ((performance.now() - startTime) / 1000).toFixed(1);
                  statsEl.innerText = `${tokenCount} chunks (${elapsed}s)`;
                }
              } catch (e) { }
            }
          }
        }
      } catch (err) {
        outputEl.innerText += `\n[Stream Error]: ${err.message}`;
      } finally {
        btn.disabled = false;
        fetchStatus();
      }
    }

    function copyText(id) {
      navigator.clipboard.writeText(document.getElementById(id).innerText);
      showToast('Copied to clipboard');
    }

    function showToast(msg) {
      const t = document.getElementById('toastMsg');
      t.innerText = msg;
      t.style.display = 'block';
      setTimeout(() => { t.style.display = 'none'; }, 2200);
    }

    function escapeHtml(s) {
      return String(s || '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[m]);
    }

    setInterval(fetchStatus, 3000);
    fetchStatus();
  </script>
</body>
</html>
"#;
