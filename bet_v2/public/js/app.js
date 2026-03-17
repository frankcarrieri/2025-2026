/* ──────────────────────────────────────────────────
   Serie B Draw Betting — Vanilla JS SPA
────────────────────────────────────────────────── */

'use strict';

// ── CONFIGURAZIONE ────────────────────────────────
const API = {
  dashboard: '/api/dashboard',
  giornata:  (n) => `/api/giornata/${n}`,
  partite:   (n) => `/api/partite?n=${n}`,
  bet:       '/api/bet',
  storico:   '/api/storico',
  sync:      '/api/sync',
  rielabora: '/api/rielabora',
};

const MAX_GIORNATA = 38;

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
  dashboard:  document.getElementById('panelDashboard'),
  bet:        document.getElementById('panelBet'),
  partite:    document.getElementById('panelPartite'),
  classifica: document.getElementById('panelClassifica'),
  storico:    document.getElementById('panelStorico'),
};

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    Object.values(panels).forEach(p => p.classList.remove('active'));
    tab.classList.add('active');
    const name = tab.dataset.tab;
    panels[name].classList.add('active');
    if (name === 'storico')    loadStorico();
    if (name === 'partite')    loadPartite();
    if (name === 'classifica') loadClassifica();
  });
});

// ── SYNC LOG ──────────────────────────────────────
let _syncLogTimer = null;
function showSyncLog(lines) {
  const logEl = document.getElementById('syncLog');
  logEl.innerHTML =
    `<button class="sync-close" onclick="document.getElementById('syncLog').style.display='none'">✕</button>` +
    lines.map(l => `<span>→ ${escHtml(String(l))}</span>`).join('');
  logEl.style.display = 'block';
  if (_syncLogTimer) clearTimeout(_syncLogTimer);
  _syncLogTimer = setTimeout(() => { logEl.style.display = 'none'; }, 5000);
}

// ── SYNC ──────────────────────────────────────────
document.getElementById('btnSync').addEventListener('click', async () => {
  const btn = document.getElementById('btnSync');
  btn.classList.add('syncing');
  btn.disabled = true;
  const logEl = document.getElementById('syncLog');
  showSyncLog(['Sincronizzazione in corso...']);

  try {
    const data = await api(API.sync, { method: 'POST' });
    showSyncLog(data.log);
    toast('Sincronizzazione completata', 'success');
    await loadDashboard();
    await loadBet();
    if (currentPartiteGiornata != null) await loadPartite(currentPartiteGiornata);
  } catch (e) {
    showSyncLog(['Errore: ' + e.message]);
    toast('Errore sync: ' + e.message, 'error');
  } finally {
    btn.classList.remove('syncing');
    btn.disabled = false;
  }
});

// ── DASHBOARD ─────────────────────────────────────
let _dashSlots = [];

async function loadDashboard() {
  // Se il pannello mostra la vista dettaglio (slotsGrid assente), ripristina la griglia
  if (!document.getElementById('slotsGrid')) {
    const panel = document.getElementById('panelDashboard');
    panel.innerHTML = `
      <div class="section-header">
        <h2>Slot Correnti</h2>
        <div class="giornata-info">Prossima giornata: <strong id="prossimaGiornata">—</strong></div>
      </div>
      <div class="slots-grid" id="slotsGrid">
        <div class="loading-state">Caricamento slot...</div>
      </div>`;
  }
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

    _dashSlots = data.slots;

    grid.innerHTML = data.slots.map((s, i) => {
      const nome = s.nome_squadra || '—';
      const saldoSlot = parseFloat(s.saldo);
      const step = parseInt(s.step);
      const posta = parseFloat(s.posta_corrente);

      const stepClass = step === 0 ? '' : step <= 2 ? 'danger' : 'critical';
      const saldoColor = saldoSlot >= 0 ? 'var(--green)' : 'var(--red)';

      return `
        <div class="slot-card" style="cursor:pointer" onclick="showSlotDetail(${s.id_slot})">
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

// ── SLOT DETAIL ───────────────────────────────────
function showSlotDetail(slotId) {
  const panel = document.getElementById('panelDashboard');
  const slot  = _dashSlots.find(s => s.id_slot == slotId) || {};
  const nome  = slot.nome_squadra || ('Slot ' + slotId);
  const i     = slotId - 1;

  const tabsHtml = _dashSlots.map(s => {
    const n = s.nome_squadra || ('Slot ' + s.id_slot);
    return `<button class="slot-tab-btn ${s.id_slot == slotId ? 'active' : ''}"
      onclick="showSlotDetail(${s.id_slot})">
      ${makeTeamVisual(n, s.id_slot - 1, 24, '', s.logo_url || null)}
      <span>${escHtml(n)}</span>
    </button>`;
  }).join('');

  panel.innerHTML = `
    <div class="slot-detail-header">
      <button class="btn-back" onclick="restoreDashboard()">← Tutti gli slot</button>
      <div>
        ${makeTeamVisual(nome, i, 36, '', slot.logo_url || null)}
      </div>
      <h2>${escHtml(nome)}</h2>
    </div>
    <div class="slot-tabs-bar">${tabsHtml}</div>
    <div class="chart-container">
      <canvas id="slotChart"></canvas>
      <div class="loading-state" id="slotChartLoading">Caricamento...</div>
    </div>
    <div id="slotDetailTable"></div>
  `;

  loadSlotStorico(slotId);
}

async function loadSlotStorico(slotId) {
  try {
    const data   = await api(API.storico + '?slot=' + slotId);
    const detail = data.detail || [];
    const loadEl = document.getElementById('slotChartLoading');
    if (loadEl) loadEl.style.display = 'none';
    const canvas = document.getElementById('slotChart');
    drawChart(detail, canvas);
    renderSlotDetailTable(detail);
  } catch (e) {
    const loadEl = document.getElementById('slotChartLoading');
    if (loadEl) loadEl.textContent = 'Errore: ' + e.message;
  }
}

async function restoreDashboard() {
  const panel = document.getElementById('panelDashboard');
  panel.innerHTML = `
    <div class="section-header">
      <h2>Slot Correnti</h2>
      <div class="giornata-info">Prossima giornata: <strong id="prossimaGiornata">—</strong></div>
    </div>
    <div class="slots-grid" id="slotsGrid">
      <div class="loading-state">Caricamento slot...</div>
    </div>
  `;
  await loadDashboard();
}

function renderSlotDetailTable(detail) {
  const el = document.getElementById('slotDetailTable');
  if (!el) return;
  if (detail.length === 0) {
    el.innerHTML = '<div class="empty-state"><div class="icon">📊</div><p>Nessun dato disponibile per questo slot.</p></div>';
    return;
  }

  const reversed = [...detail].reverse();
  el.innerHTML = `
    <table class="storico-table">
      <thead>
        <tr>
          <th>Giornata</th>
          <th>Partita</th>
          <th>Quota / Posta</th>
          <th>Esito</th>
          <th>Saldo g.</th>
          <th>Cumulativo</th>
        </tr>
      </thead>
      <tbody>
        ${reversed.map(r => {
          const partita = (r.nome_casa && r.nome_trasferta)
            ? `${escHtml(r.nome_casa)} vs ${escHtml(r.nome_trasferta)}`
            : '—';
          const score = r.gol_casa !== null
            ? ` <span style="color:var(--muted);font-size:.8em">(${r.gol_casa}-${r.gol_trasferta})</span>`
            : '';
          const quotaPosta = r.giocata
            ? `@${(r.quota_x || 0).toFixed(2)} / ${r.posta.toFixed(2)}€`
            : `<span style="color:var(--muted)">SKIP</span>`;
          const esitoCls = { WIN:'esito-win', LOSS:'esito-loss', SKIP:'esito-skip' };
          const esito = r.esito
            ? `<span class="esito-badge ${esitoCls[r.esito] || 'esito-null'}">${r.esito}</span>`
            : '—';
          const svClass = r.saldo_variazione >= 0 ? 'pos' : 'neg';
          const scClass = r.saldo_cumulativo >= 0 ? 'pos' : 'neg';
          const sv = (r.saldo_variazione >= 0 ? '+' : '') + r.saldo_variazione.toFixed(2) + '€';
          const sc = (r.saldo_cumulativo >= 0 ? '+' : '') + r.saldo_cumulativo.toFixed(2) + '€';
          const cambioTag = r.cambio_squadra
            ? `<span class="cambio-badge" title="Cambio squadra: ${escHtml(r.nome_squadra || '')}">↕ ${escHtml(r.nome_squadra || '')}</span>`
            : '';
          return `<tr class="${r.cambio_squadra ? 'cambio-squadra' : ''}">
            <td><strong>${r.giornata}</strong>${cambioTag}</td>
            <td>${partita}${score}</td>
            <td>${quotaPosta}</td>
            <td>${esito}</td>
            <td class="${svClass}">${sv}</td>
            <td class="${scClass}">${sc}</td>
          </tr>`;
        }).join('')}
      </tbody>
    </table>`;
}

window.showSlotDetail  = showSlotDetail;
window.restoreDashboard = restoreDashboard;

// ── BET GIORNATA ──────────────────────────────────
let currentGiornata = null;
let currentBets = [];

async function loadBet(n) {
  const content = document.getElementById('betContent');
  const badge = document.getElementById('tabBadge');

  try {
    if (n == null) {
      const dash = await api(API.dashboard);
      n = dash.prossima_giornata;
    }
    currentGiornata = n;
    document.getElementById('betGiornataNum').textContent = n;

    // Aggiorna pulsanti navigazione e input
    const prevBtn = document.getElementById('btnGiornataPrev');
    const nextBtn = document.getElementById('btnGiornataNext');
    const inputG  = document.getElementById('inputGiornata');
    if (prevBtn) prevBtn.disabled = n <= 1;
    if (nextBtn) nextBtn.disabled = n >= MAX_GIORNATA;
    if (inputG)  inputG.value = n;

    const data = await api(API.giornata(n));
    currentBets = (data.bets || []).map(b => ({ ...b, giocata: b.giocata == 1 }));

    // Badge: bet attive piazzate (giocata=1, esito non ancora calcolato)
    const attive = currentBets.filter(b => b.giocata && !b.esito).length;
    if (attive > 0) {
      badge.textContent = attive;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }

    renderBetForm({ ...data, bets: currentBets });
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

  // Controlla se tutte le bet hanno già un esito calcolato (post-Rielabora)
  const tutteInserite = bets.length > 0 && bets.every(b => !!b.esito);

  let html = '<form id="betFormEl" class="bet-form">';

  bets.forEach((b, i) => {
    const nome = b.nome_squadra || '—';
    const casa = b.nome_casa || '?';
    const trasf = b.nome_trasferta || '?';
    const dataOra = b.data_ora || '—';
    const posta = parseFloat(b.posta || 0);
    // Per giornate completate si permette sempre la modifica (l'utente poi usa Rielabora)
    const alreadyDone = statoGiornata !== 'completata' && !!(b.giocata || b.esito) && !!b.esito;

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

  // Mostra sempre il pulsante salva (sia per pending che per completata in modalità storico)
  html += `<button type="submit" class="btn btn-submit" id="betSubmitBtn">
    Salva Bet Giornata ${currentGiornata}
  </button>`;

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

  // Raccoglie solo i bet che l'utente ha deciso (giocata=true con quota, o giocata=false se era già giocata=true)
  const promises = [];
  for (const b of currentBets) {
    const checkbox = document.querySelector(`input[name="gioca_${b.id_slot}"]`);
    if (!checkbox || checkbox.disabled) continue;  // salta slot con esito già calcolato

    const giocata = checkbox.checked;
    const quotaInput = document.querySelector(`input[name="quota_${b.id_slot}"]`);
    const quotaX = giocata ? parseFloat(quotaInput?.value || '0') : null;

    if (giocata && (!quotaX || quotaX <= 1.0)) {
      return void (async () => {
        toast(`Slot ${b.id_slot}: inserisci una quota X valida (> 1.00)`, 'error');
        btn.disabled = false;
        btn.textContent = `Salva Bet Giornata ${currentGiornata}`;
      })();
    }

    // Invia solo se ha cambiato rispetto allo stato salvato
    const changed = (giocata !== !!b.giocata) || (giocata && quotaX !== b.quota_x);
    if (changed || giocata) {
      promises.push(api(API.bet, {
        method: 'POST',
        body: JSON.stringify({
          giornata: currentGiornata,
          id_slot:  b.id_slot,
          giocata,
          quota_x:  quotaX,
        }),
      }));
    }
  }

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

// ── PARTITE GIORNATA ──────────────────────────────
let currentPartiteGiornata = null;

async function loadPartite(n) {
  const content = document.getElementById('partiteContent');
  try {
    if (n == null) {
      const dash = await api(API.dashboard);
      n = dash.prossima_giornata;
    }
    currentPartiteGiornata = n;
    document.getElementById('partiteGiornataNum').textContent = n;

    const prevBtn = document.getElementById('btnPartitePrev');
    const nextBtn = document.getElementById('btnPartiteNext');
    const inputG  = document.getElementById('inputPartiteGiornata');
    if (prevBtn) prevBtn.disabled = n <= 1;
    if (nextBtn) nextBtn.disabled = n >= MAX_GIORNATA;
    if (inputG)  inputG.value = n;

    content.innerHTML = '<div class="loading-state">Caricamento...</div>';
    const data = await api(API.partite(n));
    renderPartite(data);
  } catch (e) {
    content.innerHTML = `<div class="loading-state">Errore: ${escHtml(e.message)}</div>`;
  }
}

function renderPartite(data) {
  const content = document.getElementById('partiteContent');
  const partite = data.partite || [];
  const slotMap = data.slot_map || {};   // { id_squadra: id_slot }
  const stato   = data.stato || 'pending';

  if (partite.length === 0) {
    content.innerHTML = `
      <div class="empty-state">
        <div class="icon">📅</div>
        <p>Nessuna partita disponibile per questa giornata.<br>
        Prova a sincronizzare i dati da Sportradar.</p>
      </div>`;
    return;
  }

  const statoLabel = {
    completata: '<span class="stato-badge stato-completata">Completata</span>',
    pending:    '<span class="stato-badge stato-pending">In corso / Da giocare</span>',
  }[stato] || '';

  const rows = partite.map(p => {
    const casaInSlot  = slotMap[p.id_casa];
    const trasfInSlot = slotMap[p.id_trasferta];

    const casaHtml  = teamCell(p.nome_casa,      casaInSlot, p.logo_casa);
    const trasfHtml = teamCell(p.nome_trasferta, trasfInSlot, p.logo_trasferta);

    // Score
    let scoreHtml = '<span class="match-score match-score--tbd">—</span>';
    if (p.gol_casa !== null && p.gol_trasferta !== null) {
      const isPari = parseInt(p.gol_casa) === parseInt(p.gol_trasferta);
      scoreHtml = `<span class="match-score ${isPari ? 'match-score--draw' : ''}">${p.gol_casa} – ${p.gol_trasferta}</span>`;
    }

    // Stato partita
    let statoPartita = '';
    if (p.stato === 'finished') {
      statoPartita = '<span class="partita-stato finished">FT</span>';
    } else if (p.stato === 'live') {
      const min = p.minuto ? `${p.minuto}'` : 'LIVE';
      statoPartita = `<span class="partita-stato live">🔴 ${min}</span>`;
    } else if (p.stato === 'postponed') {
      statoPartita = '<span class="partita-stato postponed">RINV.</span>';
    } else {
      statoPartita = '<span class="partita-stato scheduled">—</span>';
    }

    return `
      <div class="partita-row ${casaInSlot || trasfInSlot ? 'partita-row--slot' : ''}">
        <div class="partita-ora">${escHtml(p.data_ora || '—')}</div>
        <div class="partita-teams">
          ${casaHtml}
          ${scoreHtml}
          ${trasfHtml}
        </div>
        <div class="partita-stato-col">${statoPartita}</div>
      </div>`;
  }).join('');

  content.innerHTML = `
    <div class="partite-header-info">${statoLabel}
      <span class="partite-legend">
        <span class="slot-dot"></span> Squadra monitorata in uno slot
      </span>
    </div>
    <div class="partite-list">${rows}</div>`;
}

function teamCell(nome, slotId, logoUrl = null) {
  const cls   = slotId ? 'team-cell team-cell--slot' : 'team-cell';
  const badge = slotId ? `<span class="slot-badge-inline">S${slotId}</span>` : '';
  const logo  = makeTeamVisual(nome, slotId ? slotId - 1 : 0, 28, 'partita-logo', logoUrl || null);
  return `<div class="${cls}">${logo}<span class="team-cell-name">${escHtml(nome.toUpperCase())}</span>${badge}</div>`;
}

document.getElementById('btnPartitePrev').addEventListener('click', () => {
  if (currentPartiteGiornata > 1) loadPartite(currentPartiteGiornata - 1);
});
document.getElementById('btnPartiteNext').addEventListener('click', () => {
  if (currentPartiteGiornata < MAX_GIORNATA) loadPartite(currentPartiteGiornata + 1);
});
document.getElementById('inputPartiteGiornata').addEventListener('change', (e) => {
  const n = Math.max(1, Math.min(MAX_GIORNATA, parseInt(e.target.value) || 1));
  e.target.value = n;
  loadPartite(n);
});

// ── CLASSIFICA PAREGGI ────────────────────────────
async function loadClassifica() {
  const el = document.getElementById('classificaContent');
  el.innerHTML = '<div class="loading-state">Caricamento...</div>';
  try {
    const data = await api('/api/classifica');
    renderClassifica(data.classifica || []);
  } catch (e) {
    el.innerHTML = `<div class="loading-state">Errore: ${escHtml(e.message)}</div>`;
  }
}

function renderClassifica(rows) {
  const el = document.getElementById('classificaContent');
  if (rows.length === 0) {
    el.innerHTML = '<div class="empty-state"><div class="icon">📊</div><p>Nessun dato disponibile.</p></div>';
    return;
  }

  const maxPar = rows[0].pareggi || 1;

  const tableRows = rows.map((r, idx) => {
    const isSlot   = r.id_slot != null;
    const slotBadge = isSlot ? `<span class="slot-badge-inline">S${r.id_slot}</span>` : '';
    const logo     = makeTeamVisual(r.nome, isSlot ? r.id_slot - 1 : idx % 6, 28, 'partita-logo', r.logo_url);
    const pct      = maxPar > 0 ? Math.round((r.pareggi / maxPar) * 100) : 0;
    const gd       = r.gol_fatti - r.gol_subiti;
    const gdStr    = (gd >= 0 ? '+' : '') + gd;
    const gdCls    = gd > 0 ? 'pos' : gd < 0 ? 'neg' : '';

    const posReal = r.pos_classifica;
    const posArrow = '';

    return `
      <tr class="${isSlot ? 'class-row--slot' : ''}">
        <td class="class-pos">${idx + 1}</td>
        <td class="class-team">
          ${logo}
          <span class="team-cell-name">${escHtml(r.nome.toUpperCase())}</span>
          ${slotBadge}
        </td>
        <td class="class-pg">${r.partite_giocate}</td>
        <td class="class-v">${r.vittorie}</td>
        <td class="class-n class-n--bold">${r.pareggi}</td>
        <td class="class-s">${r.sconfitte}</td>
        <td class="class-gd ${gdCls}">${gdStr}</td>
        <td class="class-pts">${r.punti}</td>
        <td class="class-pos-real">${posReal}° ${posArrow}</td>
        <td class="class-bar-cell">
          <div class="class-bar-track">
            <div class="class-bar-fill ${isSlot ? 'class-bar-fill--slot' : ''}" style="width:${pct}%"></div>
          </div>
        </td>
      </tr>`;
  }).join('');

  el.innerHTML = `
    <table class="classifica-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Squadra</th>
          <th title="Partite giocate">PG</th>
          <th title="Vittorie">V</th>
          <th title="Pareggi" class="class-n--bold">N</th>
          <th title="Sconfitte">S</th>
          <th title="Differenza reti">DR</th>
          <th title="Punti">Pts</th>
          <th title="Posizione in classifica reale">Class.</th>
          <th>Pareggi</th>
        </tr>
      </thead>
      <tbody>${tableRows}</tbody>
    </table>`;
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

function drawChart(storico, canvasEl = null) {
  const canvas = canvasEl || document.getElementById('plChart');
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

    if (Math.abs(v) > 0.01) {  // skip 0€ label — drawn in green with zero line
      ctx.fillStyle = '#94a3b8';
      ctx.font = '11px system-ui';
      ctx.textAlign = 'right';
      const label = (v >= 0 ? '+' : '') + v.toFixed(0) + '€';
      ctx.fillText(label, pad.left - 6, y + 4);
    }
  }

  // Linea zero — verde evidenziata
  const y0 = yOf(0);
  ctx.strokeStyle = 'rgba(34,197,94,.65)';
  ctx.lineWidth = 1.5;
  ctx.setLineDash([6, 4]);
  ctx.beginPath();
  ctx.moveTo(pad.left, y0);
  ctx.lineTo(W - pad.right, y0);
  ctx.stroke();
  ctx.setLineDash([]);

  // Label "0€" sull'asse Y per la linea zero
  ctx.fillStyle = 'rgba(34,197,94,.8)';
  ctx.font = 'bold 10px system-ui';
  ctx.textAlign = 'right';
  ctx.fillText('0€', pad.left - 6, y0 + 4);

  // Area fill — indigo above zero, red below zero
  const gradient = ctx.createLinearGradient(0, pad.top, 0, H - pad.bottom);
  if (minV >= 0) {
    gradient.addColorStop(0, 'rgba(99,102,241,.35)');
    gradient.addColorStop(1, 'rgba(99,102,241,.02)');
  } else if (maxV <= 0) {
    gradient.addColorStop(0, 'rgba(239,68,68,.3)');
    gradient.addColorStop(1, 'rgba(239,68,68,.05)');
  } else {
    const zeroStop = (yOf(0) - pad.top) / chartH;
    gradient.addColorStop(0,              'rgba(99,102,241,.35)');
    gradient.addColorStop(zeroStop - 0.001, 'rgba(99,102,241,.08)');
    gradient.addColorStop(zeroStop,         'rgba(239,68,68,.08)');
    gradient.addColorStop(1,              'rgba(239,68,68,.28)');
  }

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

  // ── Crosshair hover ───────────────────────────────
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

  // Tooltip element (riuso o crea)
  const container = canvas.parentElement;
  let ttEl = container.querySelector('.chart-tooltip');
  if (!ttEl) {
    ttEl = document.createElement('div');
    ttEl.className = 'chart-tooltip';
    container.appendChild(ttEl);
  }

  const onMove = (e) => {
    const r   = canvas.getBoundingClientRect();
    const mx  = e.clientX - r.left;

    // Punto più vicino
    let best = null, bestDist = Infinity;
    storico.forEach((s, idx) => {
      const dist = Math.abs(mx - xOf(idx));
      if (dist < bestDist) { bestDist = dist; best = { s, idx }; }
    });

    ctx.putImageData(imageData, 0, 0);

    if (!best || bestDist > chartW / Math.max(storico.length - 1, 1)) {
      ttEl.style.display = 'none';
      return;
    }

    const cx = xOf(best.idx);
    const cy = yOf(best.s.saldo_cumulativo);

    // Linea verticale
    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,.2)';
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 3]);
    ctx.beginPath();
    ctx.moveTo(cx, pad.top);
    ctx.lineTo(cx, H - pad.bottom);
    ctx.stroke();
    ctx.setLineDash([]);

    // Dot evidenziato
    ctx.beginPath();
    ctx.arc(cx, cy, 5.5, 0, Math.PI * 2);
    ctx.fillStyle = best.s.saldo_cumulativo >= 0 ? '#22c55e' : '#ef4444';
    ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,.85)';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    ctx.restore();

    // Tooltip
    const val   = best.s.saldo_cumulativo;
    const sign  = val >= 0 ? '+' : '';
    const color = val >= 0 ? 'var(--green)' : 'var(--red)';
    ttEl.innerHTML = `<span class="tt-day">G${best.s.giornata}</span><span class="tt-val" style="color:${color}">${sign}${val.toFixed(2)}€</span>`;

    const tipLeft = (cx + 14 + 110 > r.width) ? cx - 120 : cx + 14;
    const tipTop  = Math.max(cy - 24, pad.top);
    ttEl.style.left    = tipLeft + 'px';
    ttEl.style.top     = tipTop  + 'px';
    ttEl.style.display = 'flex';
  };

  const onLeave = () => {
    ttEl.style.display = 'none';
    ctx.putImageData(imageData, 0, 0);
  };

  canvas.removeEventListener('mousemove', canvas._onMove);
  canvas.removeEventListener('mouseleave', canvas._onLeave);
  canvas._onMove  = onMove;
  canvas._onLeave = onLeave;
  canvas.addEventListener('mousemove', onMove);
  canvas.addEventListener('mouseleave', onLeave);
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
          const winAmt  = s.win_totale  > 0 ? `<span class="win-amt">+${s.win_totale.toFixed(2)}€</span>` : '';
          const lossAmt = s.loss_totale < 0 ? `<span class="loss-amt">${s.loss_totale.toFixed(2)}€</span>` : '';
          const detail  = (winAmt || lossAmt)
            ? `<div class="saldo-detail">${[winAmt, lossAmt].filter(Boolean).join(' / ')}</div>`
            : '';
          return `<tr>
            <td><strong>${s.giornata}</strong></td>
            <td class="${sgClass}">${sg}${detail}</td>
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

// ── NAV GIORNATA ──────────────────────────────────
document.getElementById('btnGiornataPrev').addEventListener('click', () => {
  if (currentGiornata > 1) loadBet(currentGiornata - 1);
});
document.getElementById('btnGiornataNext').addEventListener('click', () => {
  if (currentGiornata < MAX_GIORNATA) loadBet(currentGiornata + 1);
});
document.getElementById('inputGiornata').addEventListener('change', (e) => {
  const n = Math.max(1, Math.min(MAX_GIORNATA, parseInt(e.target.value) || 1));
  e.target.value = n;
  loadBet(n);
});

// ── RIELABORA STORICO ─────────────────────────────
document.getElementById('btnRielabora').addEventListener('click', async () => {
  const btn = document.getElementById('btnRielabora');
  btn.disabled = true;
  btn.textContent = 'Ricalcolo in corso...';
  try {
    const data = await api(API.rielabora, { method: 'POST' });
    showSyncLog(data.log);
    toast('Rielaborazione completata!', 'success');
    await loadStorico();
    await loadDashboard();
    await loadBet(currentGiornata);
  } catch (e) {
    toast('Errore rielabora: ' + e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = '↺ Rielabora Storico';
  }
});

// ── INIT ──────────────────────────────────────────
(async () => {
  await loadDashboard();
  await loadBet();
})();
