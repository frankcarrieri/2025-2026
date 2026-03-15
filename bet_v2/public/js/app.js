/* ──────────────────────────────────────────────────
   Serie B Draw Betting — Vanilla JS SPA
────────────────────────────────────────────────── */

'use strict';

// ── CONFIGURAZIONE ────────────────────────────────
const API = {
  dashboard: '/api/dashboard',
  giornata:  (n) => `/api/giornata/${n}`,
  bet:       '/api/bet',
  storico:   '/api/storico',
  sync:      '/api/sync',
};

// Colori avatar fallback (uno per slot)
const AVATAR_COLORS = [
  '#6366f1','#0ea5e9','#22c55e','#f59e0b','#ef4444','#a855f7'
];

// ── TOAST ─────────────────────────────────────────
function toast(msg, type = 'info') {
  let tc = document.querySelector('.toast-container');
  if (!tc) {
    tc = document.createElement('div');
    tc.className = 'toast-container';
    document.body.appendChild(tc);
  }
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.textContent = msg;
  tc.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

// ── FETCH HELPERS ─────────────────────────────────
async function api(url, opts = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json' },
    ...opts,
  });
  const json = await res.json();
  if (!res.ok) throw new Error(json.error || `HTTP ${res.status}`);
  return json;
}

// ── VISUAL SQUADRA ────────────────────────────────
// Usa logo_url dal DB se disponibile, altrimenti avatar colorato
function makeTeamVisual(name, slotIndex, size = 48, className = '', logoUrl = null) {
  const initials = name
    .replace(/\b(ac|fc|ssd|asd|ss)\b/gi, '')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map(w => w[0])
    .join('')
    .toUpperCase();
  const color = AVATAR_COLORS[slotIndex % AVATAR_COLORS.length];

  if (logoUrl) {
    return `<img class="team-logo ${className}" src="${escHtml(logoUrl)}" alt="${escHtml(name)}"
      width="${size}" height="${size}"
      onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'team-avatar ${className}',textContent:'${initials}',style:'width:${size}px;height:${size}px;background:${color};font-size:${size*0.35}px;display:flex;align-items:center;justify-content:center;border-radius:50%;color:#fff;font-weight:700;'}))"
      style="border-radius:50%;object-fit:contain;background:var(--surface2);">`;
  }

  return `<div class="team-avatar ${className}"
    style="width:${size}px;height:${size}px;background:${color};font-size:${size*0.35}px;">${initials}</div>`;
}

// ── TABS ──────────────────────────────────────────
const panels = {
  dashboard: document.getElementById('panelDashboard'),
  bet:       document.getElementById('panelBet'),
  storico:   document.getElementById('panelStorico'),
};

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    Object.values(panels).forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    const name = tab.dataset.tab;
    panels[name].classList.add('active');
    if (name === 'storico') loadStorico();
  });
});

// ── SYNC ──────────────────────────────────────────
document.getElementById('btnSync').addEventListener('click', async () => {
  const btn = document.getElementById('btnSync');
  btn.classList.add('syncing');
  btn.disabled = true;
  const logEl = document.getElementById('syncLog');
  logEl.style.display = 'block';
  logEl.textContent = 'Sincronizzazione in corso...';

  try {
    const data = await api(API.sync, { method: 'POST' });
    logEl.innerHTML = data.log.map(l => `→ ${l}`).join('<br>');
    toast('Sincronizzazione completata', 'success');
    await loadDashboard();
    await loadBet();
  } catch (e) {
    logEl.textContent = 'Errore: ' + e.message;
    toast('Errore sync: ' + e.message, 'error');
  } finally {
    btn.classList.remove('syncing');
    btn.disabled = false;
  }
});

// ── DASHBOARD ─────────────────────────────────────
async function loadDashboard() {
  const grid = document.getElementById('slotsGrid');
  const saldoEl = document.getElementById('saldoTotale');
  const prossimaEl = document.getElementById('prossimaGiornata');

  try {
    const data = await api(API.dashboard);

    // Saldo totale
    const saldo = data.saldo_totale;
    saldoEl.textContent = (saldo >= 0 ? '+' : '') + saldo.toFixed(2) + '€';
    saldoEl.className = 'saldo-totale ' + (saldo >= 0 ? 'saldo-pos' : 'saldo-neg');

    // Prossima giornata
    prossimaEl.textContent = data.prossima_giornata;

    // Griglia slot
    if (!data.slots || data.slots.length === 0) {
      grid.innerHTML = '<div class="loading-state">Nessuno slot disponibile.</div>';
      return;
    }

    grid.innerHTML = data.slots.map((s, i) => {
      const nome = s.nome_squadra || '—';
      const saldoSlot = parseFloat(s.saldo);
      const step = parseInt(s.step);
      const posta = parseFloat(s.posta_corrente);

      const stepClass = step === 0 ? '' : step <= 2 ? 'danger' : 'critical';
      const saldoColor = saldoSlot >= 0 ? 'var(--green)' : 'var(--red)';

      return `
        <div class="slot-card">
          <div class="slot-card-header">
            ${makeTeamVisual(nome, i, 48, '', s.logo_url || null)}
            <div>
              <div class="slot-num">Slot ${s.id_slot}</div>
              <div class="team-name">${escHtml(nome)}</div>
            </div>
          </div>
          <div class="slot-card-stats">
            <div class="stat">
              <span class="stat-label">Step</span>
              <span class="stat-value">
                <span class="step-badge ${stepClass}">${step}</span>
              </span>
            </div>
            <div class="stat">
              <span class="stat-label">Posta</span>
              <span class="stat-value">${posta.toFixed(2)}€</span>
            </div>
            <div class="stat">
              <span class="stat-label">Saldo</span>
              <span class="stat-value" style="color:${saldoColor}">
                ${saldoSlot >= 0 ? '+' : ''}${saldoSlot.toFixed(2)}€
              </span>
            </div>
          </div>
        </div>`;
    }).join('');

  } catch (e) {
    grid.innerHTML = `<div class="loading-state">Errore caricamento: ${escHtml(e.message)}</div>`;
  }
}

// ── BET GIORNATA ──────────────────────────────────
let currentGiornata = null;
let currentBets = [];

async function loadBet() {
  const content = document.getElementById('betContent');
  const badge = document.getElementById('tabBadge');

  try {
    const dash = await api(API.dashboard);
    const n = dash.prossima_giornata;
    currentGiornata = n;
    document.getElementById('betGiornataNum').textContent = n;

    const data = await api(API.giornata(n));
    currentBets = data.bets || [];

    // Conta bet in attesa (non ancora compilate)
    const inAttesa = currentBets.filter(b => !b.giocata && !b.esito).length;
    if (inAttesa > 0) {
      badge.textContent = inAttesa;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }

    renderBetForm(data);
  } catch (e) {
    content.innerHTML = `<div class="loading-state">Errore: ${escHtml(e.message)}</div>`;
  }
}

function renderBetForm(data) {
  const content = document.getElementById('betContent');
  const bets = data.bets || [];
  const statoGiornata = data.stato;

  if (bets.length === 0) {
    content.innerHTML = `
      <div class="empty-state">
        <div class="icon">📅</div>
        <p>Nessuna partita disponibile per questa giornata.<br>
        Prova a sincronizzare i dati da Sportradar.</p>
      </div>`;
    return;
  }

  // Controlla se tutte le bet sono già state inserite
  const tutteInserite = bets.every(b => b.giocata || b.esito);

  let html = '<form id="betFormEl" class="bet-form">';

  bets.forEach((b, i) => {
    const nome = b.nome_squadra || '—';
    const casa = b.nome_casa || '?';
    const trasf = b.nome_trasferta || '?';
    const dataOra = b.data_ora || '—';
    const posta = parseFloat(b.posta || 0);
    const alreadyDone = (b.giocata || b.esito) && b.esito;

    // Badge esito se già calcolato
    let esitoBadge = '';
    if (b.esito) {
      const cls = { WIN:'esito-win', LOSS:'esito-loss', SKIP:'esito-skip' }[b.esito] || 'esito-null';
      esitoBadge = `<span class="esito-badge ${cls}">${b.esito}</span>`;
    }

    html += `
      <div class="bet-row ${alreadyDone ? 'done' : ''}" data-slot="${b.id_slot}">
        <div class="bet-row-logo">
          ${makeTeamVisual(nome, i, 40, '', b.logo_url || null)}
          <span style="font-size:.65rem;color:var(--muted);margin-top:2px;">Slot ${b.id_slot}</span>
        </div>
        <div class="bet-row-info">
          <div class="bet-match">${escHtml(nome)} — ${escHtml(casa)} vs ${escHtml(trasf)}</div>
          <div class="bet-meta">
            <span>📅 ${escHtml(dataOra)}</span>
            <span>💰 ${posta.toFixed(2)}€</span>
            ${b.gol_casa !== null ? `<span>⚽ ${b.gol_casa}-${b.gol_trasferta}</span>` : ''}
            ${esitoBadge}
          </div>
        </div>
        <div class="bet-controls">
          <label class="toggle-wrap">
            <input type="checkbox" class="gioca-toggle" name="gioca_${b.id_slot}"
              ${b.giocata ? 'checked' : ''} ${alreadyDone ? 'disabled' : ''}
              onchange="onGiocaChange(this)">
            <span class="toggle-btn"></span>
            <span class="toggle-label">Gioco</span>
          </label>
          <div class="quota-wrap ${b.giocata ? 'visible' : ''}" id="quotaWrap_${b.id_slot}">
            <span class="quota-at">@</span>
            <input type="number" step="0.01" min="1.01"
              class="quota-input" name="quota_${b.id_slot}"
              placeholder="3.25"
              value="${b.quota_x || ''}"
              ${alreadyDone ? 'disabled' : ''}>
          </div>
        </div>
      </div>`;
  });

  if (!tutteInserite || statoGiornata === 'pending') {
    html += `<button type="submit" class="btn btn-submit" id="betSubmitBtn">
      Salva Bet Giornata ${currentGiornata}
    </button>`;
  }

  html += '</form>';
  content.innerHTML = html;

  const form = document.getElementById('betFormEl');
  if (form) form.addEventListener('submit', submitBet);
}

function onGiocaChange(checkbox) {
  const slot = checkbox.closest('.bet-row').dataset.slot;
  const wrap = document.getElementById(`quotaWrap_${slot}`);
  if (wrap) wrap.classList.toggle('visible', checkbox.checked);
}

// Rendi onGiocaChange globale (usata inline nel HTML generato)
window.onGiocaChange = onGiocaChange;

async function submitBet(e) {
  e.preventDefault();
  const btn = document.getElementById('betSubmitBtn');
  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  const promises = currentBets.map(b => {
    const checkbox = document.querySelector(`input[name="gioca_${b.id_slot}"]`);
    const quotaInput = document.querySelector(`input[name="quota_${b.id_slot}"]`);
    if (!checkbox) return Promise.resolve();

    const giocata = checkbox.checked;
    const quotaX = giocata ? parseFloat(quotaInput?.value || '0') : null;

    if (giocata && (!quotaX || quotaX <= 1.0)) {
      return Promise.reject(new Error(`Slot ${b.id_slot}: inserisci una quota X valida (> 1.00)`));
    }

    return api(API.bet, {
      method: 'POST',
      body: JSON.stringify({
        giornata: currentGiornata,
        id_slot:  b.id_slot,
        giocata,
        quota_x:  quotaX,
      }),
    });
  });

  try {
    await Promise.all(promises);
    toast('Bet salvate con successo!', 'success');
    await loadBet();
    await loadDashboard();
  } catch (err) {
    toast('Errore: ' + err.message, 'error');
    btn.disabled = false;
    btn.textContent = `Salva Bet Giornata ${currentGiornata}`;
  }
}

// ── STORICO P&L ───────────────────────────────────
async function loadStorico() {
  const tableEl = document.getElementById('storicoTable');
  const chartLoading = document.getElementById('chartLoading');

  try {
    const data = await api(API.storico);
    const storico = data.storico || [];

    chartLoading.style.display = 'none';
    drawChart(storico);
    renderStoricoTable(storico);
  } catch (e) {
    chartLoading.textContent = 'Errore: ' + e.message;
    tableEl.innerHTML = '';
  }
}

function drawChart(storico) {
  const canvas = document.getElementById('plChart');
  if (!canvas || storico.length === 0) return;

  const ctx = canvas.getContext('2d');
  const dpr = window.devicePixelRatio || 1;
  const rect = canvas.getBoundingClientRect();

  // Resize per DPI
  canvas.width  = rect.width  * dpr;
  canvas.height = rect.height * dpr;
  ctx.scale(dpr, dpr);

  const W = rect.width;
  const H = rect.height;
  const pad = { top: 20, right: 20, bottom: 36, left: 60 };
  const chartW = W - pad.left - pad.right;
  const chartH = H - pad.top - pad.bottom;

  const values = storico.map(s => s.saldo_cumulativo);
  const minV = Math.min(0, ...values);
  const maxV = Math.max(0, ...values);
  const range = maxV - minV || 1;

  function xOf(i)  { return pad.left + (i / (storico.length - 1 || 1)) * chartW; }
  function yOf(v)  { return pad.top + chartH - ((v - minV) / range) * chartH; }

  // Griglia
  ctx.strokeStyle = 'rgba(51,65,85,.5)';
  ctx.lineWidth = 1;
  const steps = 5;
  for (let s = 0; s <= steps; s++) {
    const v = minV + (range / steps) * s;
    const y = yOf(v);
    ctx.beginPath();
    ctx.moveTo(pad.left, y);
    ctx.lineTo(W - pad.right, y);
    ctx.stroke();

    ctx.fillStyle = '#94a3b8';
    ctx.font = '11px system-ui';
    ctx.textAlign = 'right';
    const label = (v >= 0 ? '+' : '') + v.toFixed(0) + '€';
    ctx.fillText(label, pad.left - 6, y + 4);
  }

  // Linea zero
  const y0 = yOf(0);
  ctx.strokeStyle = 'rgba(148,163,184,.3)';
  ctx.lineWidth = 1;
  ctx.setLineDash([4, 4]);
  ctx.beginPath();
  ctx.moveTo(pad.left, y0);
  ctx.lineTo(W - pad.right, y0);
  ctx.stroke();
  ctx.setLineDash([]);

  // Area fill
  const gradient = ctx.createLinearGradient(0, pad.top, 0, H - pad.bottom);
  gradient.addColorStop(0, 'rgba(99,102,241,.35)');
  gradient.addColorStop(1, 'rgba(99,102,241,.02)');

  ctx.beginPath();
  storico.forEach((s, i) => {
    const x = xOf(i), y = yOf(s.saldo_cumulativo);
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.lineTo(xOf(storico.length - 1), yOf(minV));
  ctx.lineTo(xOf(0), yOf(minV));
  ctx.closePath();
  ctx.fillStyle = gradient;
  ctx.fill();

  // Linea principale
  ctx.beginPath();
  ctx.lineWidth = 2.5;
  ctx.strokeStyle = '#818cf8';
  ctx.lineJoin = 'round';
  storico.forEach((s, i) => {
    const x = xOf(i), y = yOf(s.saldo_cumulativo);
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  });
  ctx.stroke();

  // Punti
  storico.forEach((s, i) => {
    const x = xOf(i), y = yOf(s.saldo_cumulativo);
    const color = s.saldo_cumulativo >= 0 ? '#22c55e' : '#ef4444';
    ctx.beginPath();
    ctx.arc(x, y, 3.5, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
  });

  // Label asse X (ogni 5 giornate)
  ctx.fillStyle = '#94a3b8';
  ctx.font = '10px system-ui';
  ctx.textAlign = 'center';
  storico.forEach((s, i) => {
    if (i % 5 === 0 || i === storico.length - 1) {
      ctx.fillText('G' + s.giornata, xOf(i), H - pad.bottom + 16);
    }
  });
}

function renderStoricoTable(storico) {
  const el = document.getElementById('storicoTable');
  if (storico.length === 0) {
    el.innerHTML = '<div class="empty-state"><div class="icon">📊</div><p>Nessun dato storico disponibile.</p></div>';
    return;
  }

  const reversed = [...storico].reverse();
  el.innerHTML = `
    <table class="storico-table">
      <thead>
        <tr>
          <th>Giornata</th>
          <th>Saldo g.</th>
          <th>Saldo cumulativo</th>
          <th>Esiti</th>
        </tr>
      </thead>
      <tbody>
        ${reversed.map(s => {
          const sgClass = s.saldo_giornata >= 0 ? 'pos' : 'neg';
          const scClass = s.saldo_cumulativo >= 0 ? 'pos' : 'neg';
          const sg = (s.saldo_giornata >= 0 ? '+' : '') + s.saldo_giornata.toFixed(2) + '€';
          const sc = (s.saldo_cumulativo >= 0 ? '+' : '') + s.saldo_cumulativo.toFixed(2) + '€';
          const pills = [
            ...Array(s.win).fill('<span class="pill pill-w">W</span>'),
            ...Array(s.loss).fill('<span class="pill pill-l">L</span>'),
            ...Array(s.skip).fill('<span class="pill pill-s">S</span>'),
          ].join('');
          return `<tr>
            <td><strong>G${s.giornata}</strong></td>
            <td class="${sgClass}">${sg}</td>
            <td class="${scClass}">${sc}</td>
            <td><div class="esiti-pills">${pills}</div></td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>`;
}

// ── UTILS ─────────────────────────────────────────
function escHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── INIT ──────────────────────────────────────────
(async () => {
  await loadDashboard();
  await loadBet();
})();
