import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  data: null,
  currency: 'ARS',
};

const setError = (msg) => {
  const el = document.getElementById('heatmap-error');
  if (el) el.textContent = msg || '';
};

const colorForChange = (pct) => {
  const clamped = Math.max(-5, Math.min(5, Number.isFinite(pct) ? pct : 0));
  const norm = (clamped + 5) / 10; // 0..1
  const r = clamped < 0 ? 239 : Math.round(15 + (34 - 15) * norm);
  const g = clamped < 0 ? 68 : Math.round(23 + (197 - 23) * norm);
  const b = clamped < 0 ? 68 : Math.round(42 + (94 - 42) * norm);
  return `rgb(${r},${g},${b})`;
};

const formatNumber = (value, digits = 2) => {
  if (!Number.isFinite(Number(value))) return '—';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
};

const formatPercent = (value) => {
  if (!Number.isFinite(Number(value))) return '—';
  return `${Number(value) >= 0 ? '+' : ''}${formatNumber(value, 2)}%`;
};

const renderGrid = () => {
  const grid = document.getElementById('heatmap-grid');
  const meta = document.getElementById('heatmap-meta');
  if (!grid || !state.data) {
    console.info('[heatmap] sin grid o sin datos aún');
    return;
  }
  const groups = Array.isArray(state.data.groups) ? state.data.groups : [];
  if (groups.length === 0) {
    console.warn('[heatmap] groups vacío', state.data);
    grid.innerHTML = '<p class="muted">Sin datos para el heatmap.</p>';
    if (meta) meta.textContent = 'Sin datos';
    return;
  }
  try {
    console.table(groups.map((g) => ({
      sector: g.sector,
      industry: g.industry,
      items: (g.items ?? []).length,
    })));
  } catch {}
  const items = groups.flatMap((g) => (g.items ?? []).map((i) => ({
    ...i,
    sector: g.sector,
    industry: g.industry,
  })));
  try {
    console.table(items.map((i) => ({
      symbol: i.symbol,
      mv: i.market_value,
      change: i.change_pct_d,
      sector: i.sector,
      industry: i.industry,
    })));
  } catch {}
  const maxWeight = Math.max(...items.map((i) => Number(i.market_value) || 0), 0.0001);
  const tiles = items.map((i) => {
    const weight = Number(i.market_value) || 0;
    const size = Math.max(1.5, Math.sqrt(weight / maxWeight) * 4); // factor de escala suave
    const change = Number(i.change_pct_d);
    const bg = colorForChange(change);
    return `
      <div class="tile" style="background:${bg};flex:${size} 1 ${Math.round(120 * size)}px;">
        <div style="display:flex;justify-content:space-between;gap:6px;">
          <strong>${i.symbol}</strong>
          <small>${formatPercent(change)}</small>
        </div>
        <small class="muted">${i.sector ?? 'Sin sector'} · ${i.industry ?? 'Sin industry'}</small><br />
        <div class="weight">Valor: ${formatNumber(i.market_value, 2)} ${state.data.base_currency}</div>
      </div>
    `;
  }).join('');
  grid.innerHTML = tiles;
  if (meta) meta.textContent = `Cuenta: ${state.data.account_id ?? '--'} · Base: ${state.data.base_currency ?? '--'} · As of: ${state.data.as_of ?? '--'}`;
};

const loadHeatmap = async () => {
  setError('');
  try {
    console.info('[heatmap] solicitando /portfolio/heatmap');
    const resp = await overlay.withLoader(() => getJson('/portfolio/heatmap'));
    console.info('[heatmap] respuesta', resp);
    state.data = resp;
    renderGrid();
    // Debug: también ver instrumentos y summary para cruzar datos
    try {
      const instruments = await getJson('/portfolio/instruments');
      console.table((instruments?.data ?? []).map((i) => ({
        symbol: i.symbol,
        name: i.name,
        currency: i.currency,
      })));
    } catch (err) {
      console.info('[heatmap] no se pudo obtener /portfolio/instruments', err);
    }
    try {
      const summary = await getJson('/portfolio/summary');
      console.table((summary?.data ?? []).map((s) => ({
        symbol: s.symbol,
        price: s.price,
        prev_close: s.previous_close,
        currency: s.currency,
      })));
    } catch (err) {
      console.info('[heatmap] no se pudo obtener /portfolio/summary', err);
    }
  } catch (error) {
    console.error('[heatmap] load error', error);
    setError(error?.error?.message ?? 'No se pudo cargar el heatmap');
  }
};

const bindUi = () => {
  document.getElementById('heatmap-reload')?.addEventListener('click', loadHeatmap);
  document.getElementById('heatmap-currency')?.addEventListener('change', async (event) => {
    state.currency = (event.target?.value || 'ARS').toUpperCase();
    await loadHeatmap();
  });
};

const init = async () => {
  renderToolbar();
  bindToolbarNavigation();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await getJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
  });
  highlightToolbar();
  try {
    const profile = await getJson('/me');
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch {
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
  await loadHeatmap();
  bindUi();
};

document.addEventListener('DOMContentLoaded', init);
