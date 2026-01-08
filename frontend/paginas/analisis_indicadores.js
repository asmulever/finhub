import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  profile: null,
  instruments: [],
  series: {},
  signals: [],
};

const setError = (msg) => {
  const el = document.getElementById('an-error');
  if (el) el.textContent = msg || '';
};

const setBadge = (id, text) => {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
};

const normalizePrice = (values) => {
  if (!Array.isArray(values) || values.length === 0) return [];
  const first = values.find((v) => Number.isFinite(v.close))?.close ?? 0;
  if (!Number.isFinite(first) || first === 0) return values.map((v) => ({ ...v, norm: null }));
  return values.map((v) => ({ ...v, norm: Number.isFinite(v.close) ? v.close / first : null }));
};

const sma = (series, window) => {
  const result = [];
  const queue = [];
  for (const point of series) {
    if (!Number.isFinite(point.close)) {
      result.push(null);
      continue;
    }
    queue.push(point.close);
    if (queue.length > window) queue.shift();
    if (queue.length < window) {
      result.push(null);
    } else {
      const avg = queue.reduce((a, b) => a + b, 0) / window;
      result.push(avg);
    }
  }
  return result;
};

const buildSignals = () => {
  const rows = [];
  state.instruments.forEach((inst) => {
    const series = state.series[inst.symbol];
    if (!series || series.length === 0) return;
    const sma20 = sma(series, 20);
    const sma50 = sma(series, 50);
    const lastIdx = series.length - 1;
    const last = series[lastIdx];
    const lastSma20 = sma20[lastIdx];
    const lastSma50 = sma50[lastIdx];
    let signal = 'Neutral';
    if (Number.isFinite(lastSma20) && Number.isFinite(lastSma50)) {
      if (lastSma20 > lastSma50 * 1.001) signal = 'Compra';
      else if (lastSma20 < lastSma50 * 0.999) signal = 'Venta';
    }
    rows.push({
      symbol: inst.symbol,
      name: inst.name ?? '',
      last: last?.close ?? null,
      sma20: lastSma20,
      sma50: lastSma50,
      signal,
      source: last?.source ?? 'N/D',
    });
  });
  state.signals = rows;
};

const renderSignals = () => {
  const tbody = document.getElementById('signals-body');
  if (!tbody) return;
  if (state.signals.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="muted">Sin datos</td></tr>';
    return;
  }
  tbody.innerHTML = state.signals.map((s) => `
    <tr>
      <td>${s.symbol}</td>
      <td>${s.name}</td>
      <td>${Number.isFinite(s.last) ? s.last.toFixed(2) : '—'}</td>
      <td>${Number.isFinite(s.sma20) ? s.sma20.toFixed(2) : '—'}</td>
      <td>${Number.isFinite(s.sma50) ? s.sma50.toFixed(2) : '—'}</td>
      <td class="${s.signal === 'Compra' ? 'signal-buy' : s.signal === 'Venta' ? 'signal-sell' : 'signal-neutral'}">${s.signal}</td>
      <td>${s.source}</td>
    </tr>
  `).join('');
};

const renderChart = (selectedSymbol) => {
  const canvas = document.getElementById('chart-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  const margin = { top: 20, right: 30, bottom: 30, left: 60 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const colors = ['#22d3ee', '#a855f7', '#f97316', '#22c55e', '#f87171', '#eab308', '#8b5cf6', '#38bdf8'];

  const seriesEntries = Object.entries(state.series)
    .filter(([symbol]) => selectedSymbol === 'ALL' || symbol === selectedSymbol)
    .map(([symbol, data]) => ({ symbol, data: normalizePrice(data) }))
    .filter((s) => s.data.some((p) => Number.isFinite(p.norm)));

  const allPoints = seriesEntries.flatMap((s) => s.data);
  const xs = allPoints.map((p) => p.date).filter(Boolean);
  const ys = allPoints.map((p) => p.norm).filter(Number.isFinite);
  if (xs.length === 0 || ys.length === 0) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', margin.left, margin.top + 20);
    return;
  }
  const dates = Array.from(new Set(xs)).sort();
  const minY = Math.min(...ys);
  const maxY = Math.max(...ys);

  const xScale = (date) => {
    const idx = dates.indexOf(date);
    return margin.left + (idx / Math.max(1, dates.length - 1)) * width;
  };
  const yScale = (value) => margin.top + height - ((value - minY) / Math.max(1e-6, maxY - minY)) * height;

  // axes
  ctx.strokeStyle = 'rgba(148,163,184,0.5)';
  ctx.beginPath();
  ctx.moveTo(margin.left, margin.top);
  ctx.lineTo(margin.left, margin.top + height);
  ctx.lineTo(margin.left + width, margin.top + height);
  ctx.stroke();

  // grid & labels
  ctx.fillStyle = '#94a3b8';
  ctx.font = '12px Inter, system-ui, sans-serif';
  const yTicks = 5;
  for (let i = 0; i <= yTicks; i++) {
    const v = minY + ((maxY - minY) * i) / yTicks;
    const y = yScale(v);
    ctx.strokeStyle = 'rgba(148,163,184,0.15)';
    ctx.beginPath();
    ctx.moveTo(margin.left, y);
    ctx.lineTo(margin.left + width, y);
    ctx.stroke();
    ctx.fillText(v.toFixed(2), margin.left - 50, y + 4);
  }

  seriesEntries.forEach((serie, idx) => {
    const color = colors[idx % colors.length];
    ctx.strokeStyle = color;
    ctx.beginPath();
    let started = false;
    serie.data.forEach((p) => {
      if (!Number.isFinite(p.norm)) return;
      const x = xScale(p.date);
      const y = yScale(p.norm);
      if (!started) {
        ctx.moveTo(x, y);
        started = true;
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.stroke();
    ctx.fillStyle = color;
    ctx.fillText(serie.symbol, margin.left + 10, margin.top + 16 + idx * 14);
  });
};

const fetchPortfolioInstruments = async () => {
  const resp = await getJson('/portfolio/instruments');
  const items = Array.isArray(resp?.data) ? resp.data : [];
  const unique = [];
  const seen = new Set();
  items.forEach((item) => {
    const symbol = String(item.symbol ?? '').toUpperCase();
    if (symbol && !seen.has(symbol)) {
      seen.add(symbol);
      unique.push({
        symbol,
        name: item.name ?? '',
        exchange: item.exchange ?? '',
      });
    }
  });
  state.instruments = unique;
  setBadge('badge-count', `Instrumentos: ${unique.length}`);
  const select = document.getElementById('instrument-select');
  if (select) {
    select.innerHTML = '<option value="ALL">Todos los instrumentos</option>' +
      unique.map((i) => `<option value="${i.symbol}">${i.symbol} — ${i.name}</option>`).join('');
  }
};

const fetchSeriesForSymbol = async (symbol, range) => {
  const period = range === '1m' ? '1m' : range === '3m' ? '3m' : range === '6m' ? '6m' : '1y';
  try {
    const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(period)}`);
    const items = Array.isArray(resp?.data) ? resp.data : Array.isArray(resp) ? resp : [];
    if (items.length > 0) {
      return items.map((row) => ({
        date: row.date ?? row.timestamp ?? row.t,
        close: Number(row.close ?? row.c ?? row.price ?? row.valor ?? null),
        source: row.provider ?? row.source ?? 'datalake',
      }));
    }
  } catch (error) {
    // fallback continua abajo
    console.info('[analisis_indicadores] fallback rava', error);
  }
  // fallback RAVA historicos
  try {
    const rava = await getJson(`/rava/historicos?especie=${encodeURIComponent(symbol)}`);
    const items = Array.isArray(rava?.data) ? rava.data : [];
    return items.map((row) => ({
      date: row.fecha ?? row.date ?? row.t,
      close: Number(row.cierre ?? row.close ?? row.c ?? null),
      source: 'rava',
    }));
  } catch (error) {
    console.info('[analisis_indicadores] fallback rava error', error);
    return [];
  }
};

const fetchAllSeries = async () => {
  const select = document.getElementById('instrument-select');
  const range = document.getElementById('range-select')?.value || '3m';
  const selectedSymbol = select?.value || 'ALL';
  setError('');
  const symbols = selectedSymbol === 'ALL'
    ? state.instruments.map((i) => i.symbol)
    : [selectedSymbol];
  const results = {};
  for (const symbol of symbols) {
    results[symbol] = await fetchSeriesForSymbol(symbol, range);
  }
  state.series = results;
  buildSignals();
  renderSignals();
  renderChart(selectedSymbol);
  setBadge('badge-updated', `Actualizado: ${new Date().toLocaleString()}`);
};

const bindUi = () => {
  document.getElementById('btn-reload')?.addEventListener('click', () => overlay.withLoader(fetchAllSeries));
  document.getElementById('instrument-select')?.addEventListener('change', () => overlay.withLoader(fetchAllSeries));
  document.getElementById('range-select')?.addEventListener('change', () => overlay.withLoader(fetchAllSeries));
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
    state.profile = await getJson('/me');
  } catch {
    state.profile = authStore.getProfile();
  }
  setAdminMenuVisibility(state.profile);
  await overlay.withLoader(fetchPortfolioInstruments);
  await overlay.withLoader(fetchAllSeries);
  bindUi();
};

document.addEventListener('DOMContentLoaded', init);
