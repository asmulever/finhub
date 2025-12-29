import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  symbols: [],
  selectedSymbols: [],
  period: '1m',
  chart: null,
  collecting: false,
  profile: null,
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

const getLogArea = () => document.getElementById('ingestion-log');

const timestamp = () => new Date().toISOString();

const resetLog = (message = '') => {
  const log = getLogArea();
  if (!log) return;
  log.value = '';
  if (message) {
    log.value = `[${timestamp()}] ${message}`;
  }
};

const appendLog = (message, at = null) => {
  const log = getLogArea();
  if (!log) return;
  const ts = at ?? timestamp();
  const prefix = log.value === '' ? '' : '\n';
  log.value = `${log.value}${prefix}[${ts}] ${message}`;
  log.scrollTop = log.scrollHeight;
};

const formatStepLine = (step) => {
  if (!step || typeof step !== 'object') return '';
  const progress = step.progress ? ` (${step.progress.current}/${step.progress.total})` : '';
  const symbol = step.symbol ? `[${step.symbol}] ` : '';
  const stage = step.stage ? `${step.stage}: ` : '';
  const status = step.status ? `${String(step.status).toUpperCase()} ` : '';
  const message = step.message ?? '';
  return `${symbol}${stage}${status}${message}${progress}`.trim();
};

const renderStepLog = (steps = []) => {
  if (!Array.isArray(steps)) return;
  steps.forEach((step) => {
    const line = formatStepLine(step);
    if (line !== '') {
      appendLog(line, step.at ?? null);
    }
  });
};

const colors = [
  '#0ea5e9', '#22d3ee', '#a78bfa', '#f472b6', '#f59e0b',
  '#10b981', '#ef4444', '#6366f1', '#14b8a6', '#f97316',
];

const numberOrNull = (value) => {
  if (value === null || value === undefined || value === '') return null;
  if (Number.isNaN(Number(value))) return null;
  return Number(value);
};

const candlestickPlugin = {
  id: 'candles',
  afterDatasetsDraw(chart) {
    const { ctx, scales } = chart;
    const xScale = scales.x;
    const yScale = scales.y;
    if (!xScale || !yScale) return;

    chart.data.datasets.forEach((dataset) => {
      const data = dataset.data || [];
      const upColor = dataset.colorUp || '#10b981';
      const downColor = dataset.colorDown || '#ef4444';
      data.forEach((point, idx) => {
        const { x, o, h, l, c } = point;
        if ([o, h, l, c].some((v) => v === null || v === undefined)) return;
        const xPos = xScale.getPixelForValue(x);
        const next = data[idx + 1];
        const prev = data[idx - 1];
        const nextX = next ? xScale.getPixelForValue(next.x) : xPos;
        const prevX = prev ? xScale.getPixelForValue(prev.x) : xPos;
        const gap = Math.max(6, Math.min(24, Math.min(Math.abs(nextX - xPos) || 12, Math.abs(xPos - prevX) || 12)));
        const bodyWidth = Math.max(4, Math.min(18, gap * 0.6));

        const yHigh = yScale.getPixelForValue(h);
        const yLow = yScale.getPixelForValue(l);
        const yOpen = yScale.getPixelForValue(o);
        const yClose = yScale.getPixelForValue(c);
        const isUp = c >= o;
        const color = isUp ? upColor : downColor;

        ctx.save();
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = 1;
        // Mecha
        ctx.beginPath();
        ctx.moveTo(xPos, yHigh);
        ctx.lineTo(xPos, yLow);
        ctx.stroke();
        // Cuerpo
        const bodyTop = Math.min(yOpen, yClose);
        const bodyBottom = Math.max(yOpen, yClose);
        const bodyHeight = Math.max(1, bodyBottom - bodyTop);
        ctx.fillRect(xPos - bodyWidth / 2, bodyTop, bodyWidth, bodyHeight);
        ctx.restore();
      });
    });
  },
};

if (typeof Chart !== 'undefined') {
  Chart.register(candlestickPlugin);
}

const buildCandles = (serie) => (serie?.points ?? []).map((p) => ({
  x: new Date(p.t),
  o: numberOrNull(p.open ?? p.price ?? p.close),
  h: numberOrNull(p.high ?? p.price ?? p.close),
  l: numberOrNull(p.low ?? p.price ?? p.close),
  c: numberOrNull(p.close ?? p.price),
  y: numberOrNull(p.close ?? p.price),
})).filter((p) => p.o !== null && p.h !== null && p.l !== null && p.c !== null && p.y !== null);

const getTimeUnit = (period) => {
  if (period === '1m' || period === '3m' || period === '6m') return 'day';
  return 'month';
};

const updateChart = (series) => {
  const ctx = document.getElementById('prices-chart');
  if (!ctx) return;
  const timeUnit = getTimeUnit(state.period);
  const datasets = series.map((serie, idx) => ({
    label: serie.symbol,
    data: buildCandles(serie),
    borderColor: colors[idx % colors.length],
    colorUp: '#10b981',
    colorDown: '#ef4444',
    showLine: false,
    pointRadius: 0,
    type: 'scatter',
    parsing: { xAxisKey: 'x', yAxisKey: 'y' },
  }));

  if (state.chart) {
    state.chart.destroy();
  }

  state.chart = new Chart(ctx, {
    type: 'scatter',
    data: { datasets },
    options: {
      maintainAspectRatio: false,
      scales: {
        x: {
          type: 'time',
          time: { tooltipFormat: 'yyyy-MM-dd', unit: timeUnit },
          ticks: { color: '#cbd5f5' },
          grid: { color: 'rgba(148,163,184,0.15)' },
          title: { display: true, text: 'Fecha', color: '#cbd5f5' },
        },
        y: {
          ticks: { color: '#cbd5f5' },
          grid: { color: 'rgba(148,163,184,0.15)' },
          title: { display: true, text: 'Moneda', color: '#cbd5f5' },
        },
      },
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: '#cbd5f5', font: { size: 10 }, boxWidth: 10, padding: 6 },
        },
        tooltip: {
          callbacks: {
            label(context) {
              const d = context.raw || {};
              return `O:${d.o ?? '-'} H:${d.h ?? '-'} L:${d.l ?? '-'} C:${d.c ?? '-'}`;
            },
          },
        },
      },
      elements: { line: { tension: 0 } },
      interaction: { intersect: false, mode: 'index' },
    },
    plugins: [candlestickPlugin],
  });
};

const fetchSymbols = async () => {
  const response = await getJson('/datalake/prices/symbols');
  state.symbols = Array.isArray(response?.symbols) ? response.symbols : [];
  state.selectedSymbols = [...state.symbols];
};

const fetchSeries = async () => {
  if (!state.selectedSymbols.length) {
    updateChart([]);
    return;
  }
  const series = [];
  for (const symbol of state.selectedSymbols) {
    // eslint-disable-next-line no-await-in-loop
    const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(state.period)}`);
    series.push(resp);
  }
  updateChart(series);
};

const handleCollect = async () => {
  if (state.collecting) return;
  state.collecting = true;
  const btn = document.getElementById('collect-btn');
  const result = document.getElementById('collect-result');
  if (btn) btn.disabled = true;
  resetLog('Iniciando ingesta de precios...');
  if (result) result.textContent = 'Recolectando...';
  try {
    appendLog('Solicitando ingesta al servidor...');
    const response = await postJson('/datalake/prices/collect', {});
    renderStepLog(response?.steps);
    if (result) {
      result.textContent = `OK: ${response.ok} | Fallidos: ${response.failed} | Total símbolos: ${response.total_symbols}`;
    }
    appendLog(`Resultado final -> OK: ${response.ok} | Fallidos: ${response.failed} | Total: ${response.total_symbols}`);
    await fetchSeries();
  } catch (error) {
    if (result) {
      result.textContent = `Error al recolectar: ${error?.error?.message ?? 'Desconocido'}`;
    }
    appendLog(`Error al recolectar: ${error?.error?.message ?? error?.message ?? 'Desconocido'}`);
  } finally {
    state.collecting = false;
    if (btn) btn.disabled = false;
  }
};

const handlePeriodChange = (event) => {
  state.period = event.target.value;
  fetchSeries();
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const loadProfile = async () => {
  try {
    const profile = await getJson('/me');
    state.profile = profile;
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch (error) {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
};

const init = async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
    profile: authStore.getProfile(),
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  if (!isAdminProfile(state.profile ?? authStore.getProfile())) {
    const result = document.getElementById('collect-result');
    if (result) result.textContent = 'Acceso restringido: solo Admin puede usar DataLake.';
    document.getElementById('collect-btn')?.setAttribute('disabled', 'disabled');
    return;
  }
  await fetchSymbols();
  await fetchSeries();

  document.getElementById('collect-btn')?.addEventListener('click', handleCollect);
  document.getElementById('period-select')?.addEventListener('change', handlePeriodChange);
  resetLog('Listo para iniciar ingesta.');
};

document.addEventListener('DOMContentLoaded', init);
