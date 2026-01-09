import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  symbols: [],
  period: '1m',
  collecting: false,
  profile: null,
  snapshots: {},
  series: [],
};

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
};

const resetLog = (message = '') => {
  const log = document.getElementById('ingestion-log');
  if (!log) return;
  log.value = message ? `[${new Date().toISOString()}] ${message}` : '';
};

const appendLog = (message) => {
  const log = document.getElementById('ingestion-log');
  if (!log) return;
  const prefix = log.value === '' ? '' : '\n';
  log.value = `${log.value}${prefix}[${new Date().toISOString()}] ${message}`;
  log.scrollTop = log.scrollHeight;
};

const renderTabs = () => {
  document.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-tab');
      document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
      btn.classList.add('active');
      const panel = document.getElementById(`tab-${tab}`);
      if (panel) panel.classList.add('active');
    });
  });
};

const loadSymbols = async () => {
  const response = await getJson('/datalake/prices/symbols');
  state.symbols = Array.isArray(response?.symbols) ? response.symbols : [];
  const selects = [document.getElementById('snapshot-symbol'), document.getElementById('series-symbol')];
  selects.forEach((sel) => {
    if (!sel) return;
    sel.innerHTML = state.symbols.map((s) => `<option value="${s}">${s}</option>`).join('');
  });
};

const loadLatestSnapshot = async () => {
  const symbol = document.getElementById('snapshot-symbol')?.value || '';
  if (!symbol) return;
  const meta = document.getElementById('snapshot-meta');
  const body = document.getElementById('snapshot-body');
  if (meta) meta.textContent = 'Cargando...';
  if (body) body.innerHTML = '<tr><td class="muted">Cargando...</td></tr>';
  try {
    const resp = await overlay.withLoader(() => getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`));
    state.snapshots[symbol] = resp;
    if (meta) meta.textContent = `Fuente: ${resp?.source ?? 'N/D'} · As of: ${resp?.asOf ?? '--'}`;
    if (body) {
      body.innerHTML = `
        <tr><td>Símbolo</td><td>${resp?.symbol ?? symbol}</td></tr>
        <tr><td>Precio</td><td>${formatNumber(resp?.close, 4)}</td></tr>
        <tr><td>Open</td><td>${formatNumber(resp?.open, 4)}</td></tr>
        <tr><td>High</td><td>${formatNumber(resp?.high, 4)}</td></tr>
        <tr><td>Low</td><td>${formatNumber(resp?.low, 4)}</td></tr>
        <tr><td>Prev Close</td><td>${formatNumber(resp?.previous_close, 4)}</td></tr>
        <tr><td>Moneda</td><td>${resp?.currency ?? 'N/D'}</td></tr>
      `;
    }
  } catch (error) {
    if (meta) meta.textContent = error?.error?.message ?? 'No se pudo cargar snapshot';
    if (body) body.innerHTML = '<tr><td class="muted">Error al cargar</td></tr>';
  }
};

const loadSeries = async () => {
  const symbol = document.getElementById('series-symbol')?.value || '';
  const period = document.getElementById('series-period')?.value || '1m';
  const body = document.getElementById('series-body');
  const meta = document.getElementById('series-meta');
  if (!symbol) return;
  if (body) body.innerHTML = '<tr><td class="muted" colspan="5">Cargando...</td></tr>';
  if (meta) meta.textContent = '';
  try {
    const resp = await overlay.withLoader(() => getJson(`/datalake/prices/series?symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(period)}`));
    const points = Array.isArray(resp?.points) ? resp.points : [];
    state.series = points;
    if (body) {
      body.innerHTML = points.map((p) => `
        <tr>
          <td>${p.t ? String(p.t).slice(0, 16) : '—'}</td>
          <td>${formatNumber(p.open ?? p.price, 4)}</td>
          <td>${formatNumber(p.high ?? p.price, 4)}</td>
          <td>${formatNumber(p.low ?? p.price, 4)}</td>
          <td>${formatNumber(p.close ?? p.price, 4)}</td>
        </tr>
      `).join('') || '<tr><td class="muted" colspan="5">Sin datos</td></tr>';
    }
    if (meta) meta.textContent = `${points.length} puntos · Período ${period} · Serie ${symbol}`;
  } catch (error) {
    if (body) body.innerHTML = '<tr><td class="muted" colspan="5">Error al cargar</td></tr>';
    if (meta) meta.textContent = error?.error?.message ?? 'No se pudo cargar serie';
  }
};

const handleCollect = async () => {
  if (state.collecting) return;
  state.collecting = true;
  resetLog('Iniciando ingesta de precios...');
  try {
    appendLog('Solicitando ingesta al servidor...');
    const response = await overlay.withLoader(() => postJson('/datalake/prices/collect', {}));
    (response?.steps ?? []).forEach((step) => appendLog(`${step.symbol ?? ''} ${step.stage ?? ''} ${step.status ?? ''} ${step.message ?? ''}`.trim()));
    appendLog(`Resultado -> OK: ${response.ok} | Fallidos: ${response.failed} | Total: ${response.total_symbols}`);
  } catch (error) {
    appendLog(`Error al recolectar: ${error?.error?.message ?? error?.message ?? 'Desconocido'}`);
  } finally {
    state.collecting = false;
  }
};

const init = async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await postJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
  });
  bindToolbarNavigation();
  highlightToolbar();
  renderTabs();

  try {
    const profile = await getJson('/me');
    state.profile = profile;
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch {
    const cachedProfile = authStore.getProfile();
    state.profile = cachedProfile;
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }

  await loadSymbols();
  await loadLatestSnapshot();
  await loadSeries();

  document.getElementById('snapshot-load')?.addEventListener('click', loadLatestSnapshot);
  document.getElementById('collect-btn')?.addEventListener('click', () => {
    if (window.confirm('Recolectar precios ahora?')) handleCollect();
  });
  document.getElementById('series-load')?.addEventListener('click', loadSeries);
  document.getElementById('series-period')?.addEventListener('change', loadSeries);
};

document.addEventListener('DOMContentLoaded', init);
