import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  profile: null,
  instruments: [],
  rava: {},
  histories: {},
  timeframe: '3m',
  selectedSymbol: 'ALL',
  lastUpdated: null,
};

const RANGE_DAYS = { '3m': 90, '6m': 180, '1y': 365 };

const setText = (id, text) => {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
};

const setError = (message) => {
  const el = document.getElementById('an-error');
  if (el) el.textContent = message || '';
};

const parseNumber = (value) => {
  const num = Number(String(value ?? '').replace(',', '.'));
  return Number.isFinite(num) ? num : null;
};

const normalizeHistory = (items) => {
  if (!Array.isArray(items)) return [];
  return items
    .map((row) => ({
      fecha: row.fecha ?? row.date ?? null,
      cierre: parseNumber(row.cierre ?? row.close ?? row.ultimo ?? row.apertura ?? null),
    }))
    .filter((r) => r.fecha && r.cierre !== null)
    .sort((a, b) => new Date(b.fecha) - new Date(a.fecha)); // descendente
};

const loadProfile = async () => {
  try {
    state.profile = await getJson('/me');
  } catch {
    state.profile = authStore.getProfile();
  }
  setToolbarUserName(state.profile?.email ?? '');
  setAdminMenuVisibility(state.profile);
};

const fetchPortfolioInstruments = async () => {
  const resp = await getJson('/portfolio/instruments');
  const items = Array.isArray(resp?.data) ? resp.data : [];
  const seen = new Set();
  state.instruments = items
    .map((i) => ({
      symbol: (i.symbol ?? '').toUpperCase(),
      name: i.name ?? '',
      currency: i.currency ?? '',
      exchange: i.exchange ?? '',
    }))
    .filter((i) => i.symbol !== '')
    .filter((i) => {
      if (seen.has(i.symbol)) return false;
      seen.add(i.symbol);
      return true;
    });
};

const fetchRavaSnapshots = async () => {
  const [cedears, acciones, bonos] = await Promise.all([
    getJson('/rava/cedears').catch(() => null),
    getJson('/rava/acciones').catch(() => null),
    getJson('/rava/bonos').catch(() => null),
  ]);
  const map = {};
  const addItems = (payload) => {
    const items = Array.isArray(payload?.data) ? payload.data : (payload?.items ?? []);
    items.forEach((item) => {
      const symbol = (item.symbol ?? '').toUpperCase();
      if (!symbol) return;
      map[symbol] = {
        ultimo: parseNumber(item.ultimo),
        variacion: parseNumber(item.variacion),
        panel: item.panel ?? item.segment ?? '',
      };
    });
  };
  addItems(cedears);
  addItems(acciones);
  addItems(bonos);
  state.rava = map;
};

const fetchHistoricos = async () => {
  const tasks = [];
  state.instruments.forEach((inst) => {
    const symbol = inst.symbol;
    if (state.histories[symbol]) return;
    tasks.push(symbol);
  });

  for (const symbol of tasks) {
    try {
      const resp = await getJson(`/rava/historicos?especie=${encodeURIComponent(symbol)}`);
      const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
      state.histories[symbol] = normalizeHistory(items);
    } catch (error) {
      console.info('[analisis] historico fallo', symbol, error);
      state.histories[symbol] = [];
    }
  }
};

const findPriceAt = (history, daysAgo) => {
  if (!history.length) return null;
  const target = new Date(Date.now() - daysAgo * 86400000);
  for (const row of history) {
    const date = new Date(row.fecha);
    if (Number.isNaN(date.getTime())) continue;
    if (date <= target) {
      return row.cierre;
    }
  }
  return history[history.length - 1]?.cierre ?? null;
};

const computeReturns = (history) => {
  if (!history.length) return { r3: null, r6: null, r12: null };
  const last = history[0]?.cierre ?? null;
  if (last === null) return { r3: null, r6: null, r12: null };
  const calc = (days) => {
    const past = findPriceAt(history, days);
    if (past === null || past === 0) return null;
    return ((last - past) / Math.abs(past)) * 100;
  };
  return {
    r3: calc(90),
    r6: calc(180),
    r12: calc(365),
  };
};

const buildSeries = (history, rangeKey) => {
  const days = RANGE_DAYS[rangeKey] ?? 90;
  const cutoff = new Date(Date.now() - days * 86400000);
  const filtered = history
    .filter((row) => {
      const date = new Date(row.fecha);
      return !Number.isNaN(date.getTime()) && date >= cutoff;
    })
    .sort((a, b) => new Date(a.fecha) - new Date(b.fecha));
  if (!filtered.length) return [];
  const base = filtered[0].cierre || 1;
  return filtered.map((row) => ({
    date: row.fecha,
    normalized: (row.cierre / (base || 1)) * 100,
  }));
};

const computeAggregatedSeries = (rangeKey) => {
  const map = {};
  state.instruments.forEach((inst) => {
    const hist = state.histories[inst.symbol] ?? [];
    const series = buildSeries(hist, rangeKey);
    series.forEach((p) => {
      if (!map[p.date]) map[p.date] = { sum: 0, count: 0 };
      map[p.date].sum += p.normalized;
      map[p.date].count += 1;
    });
  });
  const dates = Object.keys(map).sort((a, b) => new Date(a) - new Date(b));
  return dates
    .map((d) => (map[d].count ? { date: d, normalized: map[d].sum / map[d].count } : null))
    .filter(Boolean);
};

const colorForReturn = (value) => {
  if (value === null || Number.isNaN(value)) return '#1f2937';
  if (value >= 25) return '#16a34a';
  if (value >= 10) return '#22c55e';
  if (value >= 0) return '#10b981';
  if (value <= -25) return '#b91c1c';
  if (value <= -10) return '#ef4444';
  return '#f87171';
};

const formatNumber = (val, digits = 1) => {
  if (val === null || val === undefined || Number.isNaN(val)) return '–';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(val);
};

const renderStatus = () => {
  setText('badge-count', `Instrumentos: ${state.instruments.length}`);
  const histOk = Object.values(state.histories).filter((h) => Array.isArray(h) && h.length > 0).length;
  setText('badge-histos', `Históricos: ${histOk}/${state.instruments.length}`);
  setText('status-updated', `Actualizado: ${state.lastUpdated ?? '--'}`);
  setText('status-meta', `Rango: ${state.timeframe.toUpperCase()}`);
};

const renderHeatmap = () => {
  const container = document.getElementById('heatmap');
  if (!container) return;
  const range = state.timeframe;
  const tiles = state.instruments.map((inst) => {
    const hist = state.histories[inst.symbol] ?? [];
    const returns = computeReturns(hist);
    const value = range === '3m' ? returns.r3 : (range === '6m' ? returns.r6 : returns.r12);
    const bg = colorForReturn(value);
    const textColor = '#0b1021';
    const rava = state.rava[inst.symbol] ?? {};
    const panel = rava.panel || inst.exchange || '';
    return `
      <div class="tile" style="background:${bg}" data-symbol="${inst.symbol}">
        <div class="symbol">${inst.symbol}</div>
        <div class="name">${inst.name || panel || '—'}</div>
        <div class="value">${value !== null ? `${formatNumber(value)}%` : 'N/D'}</div>
        <div class="panel" style="color:${textColor};">${panel}</div>
      </div>
    `;
  }).join('');
  container.innerHTML = tiles || '<div class="muted">Sin instrumentos en portafolio.</div>';
  container.querySelectorAll('.tile').forEach((tile) => {
    tile.addEventListener('click', () => {
      const symbol = tile.getAttribute('data-symbol') || 'ALL';
      state.selectedSymbol = symbol;
      const select = document.getElementById('instrument-select');
      if (select) select.value = symbol;
      renderChart();
    });
  });
};

const renderChart = () => {
  const canvas = document.getElementById('evolution-chart');
  if (!canvas || !canvas.getContext) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  const rangeKey = state.timeframe;
  const selected = state.selectedSymbol;
  let series = [];
  if (selected === 'ALL') {
    series = computeAggregatedSeries(rangeKey);
    setText('chart-meta', `Serie: Todos · ${series.length} puntos · ${RANGE_DAYS[rangeKey]}d`);
  } else {
    const hist = state.histories[selected] ?? [];
    series = buildSeries(hist, rangeKey);
    setText('chart-meta', `Serie: ${selected} · ${series.length} puntos · ${RANGE_DAYS[rangeKey]}d`);
  }
  if (!series.length) return;
  const values = series;
  const min = Math.min(...values.map((p) => p.normalized));
  const max = Math.max(...values.map((p) => p.normalized));
  const range = (max - min) || 1;
  const w = canvas.width;
  const h = canvas.height;
  ctx.beginPath();
  values.forEach((p, idx) => {
    const x = (idx / (values.length - 1 || 1)) * (w - 20) + 10;
    const y = h - ((p.normalized - min) / range) * (h - 20) - 10;
    if (idx === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.strokeStyle = '#22d3ee';
  ctx.lineWidth = 2;
  ctx.stroke();
  ctx.lineTo(w - 10, h - 10);
  ctx.lineTo(10, h - 10);
  ctx.closePath();
  ctx.fillStyle = '#22d3ee33';
  ctx.fill();
};

const populateInstrumentSelect = () => {
  const select = document.getElementById('instrument-select');
  if (!select) return;
  const options = ['<option value="ALL">Todos los instrumentos</option>'].concat(
    state.instruments.map((inst) => `<option value="${inst.symbol}">${inst.symbol} ${inst.name ? `- ${inst.name}` : ''}</option>`)
  );
  select.innerHTML = options.join('');
  select.value = state.selectedSymbol;
  select.addEventListener('change', () => {
    state.selectedSymbol = select.value || 'ALL';
    renderChart();
  });
};

const bindRangeToggles = () => {
  document.querySelectorAll('#range-group .toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#range-group .toggle').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      const range = btn.getAttribute('data-range');
      state.timeframe = range || '3m';
      renderHeatmap();
      renderChart();
    });
  });
};

const loadAll = async () => {
  setError('');
  await overlay.withLoader(async () => {
    await fetchPortfolioInstruments();
    if (state.instruments.length === 0) {
      setError('No hay instrumentos en tus portafolios.');
      return;
    }
    await fetchRavaSnapshots();
    await fetchHistoricos();
    state.lastUpdated = new Date().toISOString();
  });
  populateInstrumentSelect();
  renderStatus();
  renderHeatmap();
  renderChart();
};

const bindUi = () => {
  document.getElementById('btn-reload')?.addEventListener('click', loadAll);
  bindRangeToggles();
};

document.addEventListener('DOMContentLoaded', async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await getJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  bindUi();
  loadAll();
});
