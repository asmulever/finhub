import { getJson } from '../apicliente.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();

const state = {
  items: [],
  selectedSymbol: '',
  baseCurrency: 'USD',
  asOf: '--',
  fxSource: null,
  fxAt: null,
  rava: {
    acciones: [],
    bonos: [],
    cedears: [],
    map: {},
  },
  histories: {},
};

const els = {
  list: null,
  analysis: null,
  details: null,
  error: null,
  count: null,
  updated: null,
  base: null,
  meta: null,
  chartSymbol: null,
  chartRange: null,
  chartMeta: null,
  chartCanvas: null,
  ravaSelected: null,
  tablaAcciones: null,
  tablaBonos: null,
  tablaCedears: null,
  metaAcciones: null,
  metaBonos: null,
  metaCedears: null,
};

const formatPct = (v) => (Number.isFinite(v) ? `${v > 0 ? '+' : ''}${v.toFixed(2)}%` : '–');
const formatNum = (v, d = 2) => (Number.isFinite(v) ? v.toFixed(d) : '–');
const formatText = (v, fallback = '—') => {
  const t = (v ?? '').toString().trim();
  return t === '' ? fallback : t;
};

const logInfo = (label, payload) => {
  try {
    console.info(`[analisis] ${label}`, payload);
  } catch {
    /* noop */
  }
};

const setError = (msg) => {
  if (els.error) els.error.textContent = msg || '';
  if (msg) console.error('[analisis] error', msg);
};

const renderList = () => {
  if (!els.list) return;
  if (state.items.length === 0) {
    els.list.innerHTML = '<p class="muted">Sin instrumentos elegibles.</p>';
    if (els.meta) els.meta.textContent = '0 símbolos';
    return;
  }
  els.meta.textContent = `${state.items.length} símbolos`;
  els.list.innerHTML = state.items
    .map((item) => {
      const active = item.symbol === state.selectedSymbol ? 'active' : '';
      return `<button class="item-btn ${active}" data-symbol="${item.symbol}">${item.symbol} · ${formatText(item.name, 'Sin nombre')}</button>`;
    })
    .join('');
  els.list.querySelectorAll('[data-symbol]').forEach((btn) => {
    btn.addEventListener('click', () => {
      state.selectedSymbol = btn.getAttribute('data-symbol') || '';
      renderList();
      renderPanels();
      renderChart();
      renderRavaSelected();
    });
  });
};

const renderPanels = () => {
  if (!els.analysis || !els.details) return;
  const item = state.items.find((i) => i.symbol === state.selectedSymbol);
  if (!item) {
    els.analysis.innerHTML = 'Selecciona un instrumento.';
    els.details.innerHTML = 'Selecciona un instrumento.';
    return;
  }

  els.analysis.innerHTML = `
    <div class="row"><span class="label">Precio</span><span class="value">${formatNum(item.price)}</span></div>
    <div class="row"><span class="label">Var diaria</span><span class="value">${formatPct(item.change_pct_d)}</span></div>
    <div class="row"><span class="label">Peso</span><span class="value">${formatPct(item.weight_pct)}</span></div>
    <div class="row"><span class="label">Market value</span><span class="value">${formatNum(item.market_value)}</span></div>
    <div class="row"><span class="label">Moneda</span><span class="value">${formatText(item.currency, 'N/D')}</span></div>
    <div class="row"><span class="label">Precio as_of</span><span class="value">${formatText(item.price_at, '--')}</span></div>
  `;

  els.details.innerHTML = `
    <div class="row"><span class="label">Símbolo</span><span class="value">${formatText(item.symbol)}</span></div>
    <div class="row"><span class="label">Nombre</span><span class="value">${formatText(item.name, 'Sin nombre')}</span></div>
    <div class="row"><span class="label">Sector</span><span class="value">${formatText(item.sector, 'Sin sector')}</span></div>
    <div class="row"><span class="label">Industry</span><span class="value">${formatText(item.industry, 'Sin industry')}</span></div>
    <div class="row"><span class="label">FX source</span><span class="value">${formatText(item.fx_source || state.fxSource || 'N/D')}</span></div>
    <div class="row"><span class="label">FX at</span><span class="value">${formatText(item.fx_at || state.fxAt || '--')}</span></div>
    <div class="row"><span class="label">Base currency</span><span class="value">${formatText(item.base_currency || state.baseCurrency, 'N/D')}</span></div>
  `;
};

const renderChart = () => {
  const canvas = els.chartCanvas;
  if (!canvas || !canvas.getContext) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  const symbol = els.chartSymbol?.value || state.selectedSymbol;
  const days = Number(els.chartRange?.value || 90);
  if (!symbol) {
    els.chartMeta.textContent = 'Serie: --';
    return;
  }
  const history = state.histories[symbol] || [];
  if (!history.length) {
    els.chartMeta.textContent = `Serie: ${symbol} · sin datos`;
    return;
  }
  const cutoff = new Date(Date.now() - days * 86400000);
  const filtered = history
    .filter((row) => {
      const dt = new Date(row.fecha);
      return !Number.isNaN(dt.getTime()) && dt >= cutoff;
    })
    .sort((a, b) => new Date(a.fecha) - new Date(b.fecha));
  if (!filtered.length) {
    els.chartMeta.textContent = `Serie: ${symbol} · sin datos en rango`;
    return;
  }
  const base = filtered[0].cierre || 1;
  const series = filtered.map((p) => ({
    date: p.fecha,
    normalized: (p.cierre / (base || 1)) * 100,
  }));
  const min = Math.min(...series.map((p) => p.normalized));
  const max = Math.max(...series.map((p) => p.normalized));
  const range = (max - min) || 1;
  const w = canvas.width;
  const h = canvas.height;
  ctx.beginPath();
  series.forEach((p, idx) => {
    const x = (idx / (series.length - 1 || 1)) * (w - 20) + 10;
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
  els.chartMeta.textContent = `Serie: ${symbol} · ${series.length} puntos · ${days}d`;
};

const renderRavaSelected = () => {
  if (!els.ravaSelected) return;
  const symbol = state.selectedSymbol;
  if (!symbol) {
    els.ravaSelected.textContent = 'Selecciona un instrumento.';
    return;
  }
  const row = state.rava.map[symbol];
  if (!row) {
    els.ravaSelected.textContent = `No se encontró ${symbol} en RAVA (AR).`;
    return;
  }
  els.ravaSelected.innerHTML = `
    <div class="row"><span class="label">Símbolo</span><span class="value">${symbol}</span></div>
    <div class="row"><span class="label">Último</span><span class="value">${formatNum(row.ultimo)}</span></div>
    <div class="row"><span class="label">Variación</span><span class="value">${formatPct(row.variacion)}</span></div>
    <div class="row"><span class="label">Panel</span><span class="value">${formatText(row.panel || row.mercado || '')}</span></div>
  `;
};

const renderTable = (tableEl, metaEl, rows, title) => {
  if (metaEl) metaEl.textContent = `${rows.length} items`;
  if (!tableEl) return;
  if (!rows.length) {
    tableEl.innerHTML = '<tr><td class="muted">Sin datos</td></tr>';
    return;
  }
  tableEl.innerHTML = `
    <thead><tr><th>Símbolo</th><th>Último</th><th>Var%</th><th>Panel</th></tr></thead>
    <tbody>
      ${rows
        .map((r) => `<tr><td>${r.symbol}</td><td>${formatNum(r.ultimo)}</td><td>${formatPct(r.variacion)}</td><td>${formatText(r.panel || r.mercado || '')}</td></tr>`)
        .join('')}
    </tbody>
  `;
  logInfo(`tabla ${title}`, rows.slice(0, 5));
};

const normalizeHistory = (items) => {
  if (!Array.isArray(items)) return [];
  return items
    .map((row) => ({
      fecha: row.fecha ?? row.date ?? null,
      cierre: Number(row.cierre ?? row.close ?? row.ultimo ?? row.apertura ?? row.cierre_adj ?? null),
    }))
    .filter((r) => r.fecha && Number.isFinite(r.cierre))
    .sort((a, b) => new Date(b.fecha) - new Date(a.fecha));
};

const loadHeatmap = async () => {
  console.info('[analisis] GET /portfolio/heatmap');
  const resp = await getJson('/portfolio/heatmap');
  const base = resp?.base_currency ?? 'USD';
  const groups = Array.isArray(resp?.groups) ? resp.groups : [];
  const collected = [];
  groups.forEach((g) => {
    const sector = g?.sector ?? 'Sin sector';
    const industry = g?.industry ?? 'Sin industry';
    (g?.items ?? []).forEach((it) => {
      collected.push({
        sector,
        industry,
        base_currency: base,
        fx_source: resp?.fx_source ?? null,
        fx_at: resp?.fx_at ?? null,
        ...it,
      });
    });
  });
  state.items = collected.sort((a, b) => (b.weight_pct ?? 0) - (a.weight_pct ?? 0));
  state.selectedSymbol = state.items[0]?.symbol ?? '';
  state.baseCurrency = base;
  state.asOf = resp?.as_of ?? '--';
  state.fxSource = resp?.fx_source ?? null;
  state.fxAt = resp?.fx_at ?? null;
  logInfo('heatmap items', state.items.slice(0, 5));
};

const loadRava = async () => {
  const fetchList = async (path) => {
    try {
      const resp = await getJson(path);
      return resp?.items ?? resp?.data ?? [];
    } catch (err) {
      console.error(`[analisis] fallo ${path}`, err);
      return [];
    }
  };
  const [cedears, acciones, bonos] = await Promise.all([
    fetchList('/rava/cedears'),
    fetchList('/rava/acciones'),
    fetchList('/rava/bonos'),
  ]);
  state.rava.cedears = cedears;
  state.rava.acciones = acciones;
  state.rava.bonos = bonos;
  const map = {};
  const add = (list) => {
    list.forEach((row) => {
      const symbol = (row.symbol ?? '').toUpperCase();
      if (!symbol) return;
      map[symbol] = {
        ultimo: Number(row.ultimo ?? row.close ?? row.cierre ?? null),
        variacion: Number(row.variacion ?? row.var ?? row.varDia ?? null),
        panel: row.panel ?? row.segment ?? row.mercado ?? null,
        mercado: row.mercado ?? null,
      };
    });
  };
  add(cedears);
  add(acciones);
  add(bonos);
  state.rava.map = map;
  logInfo('rava map', map);
};

const loadHistory = async (symbol) => {
  if (!symbol || state.histories[symbol]) {
    return;
  }
  try {
    const resp = await getJson(`/rava/historicos?especie=${encodeURIComponent(symbol)}`);
    const items = resp?.items ?? resp?.data ?? [];
    state.histories[symbol] = normalizeHistory(items);
    logInfo(`history ${symbol}`, state.histories[symbol].slice(0, 5));
  } catch (error) {
    console.error('[analisis] historico fallo', symbol, error);
    state.histories[symbol] = [];
  }
};

const populateSelectors = () => {
  if (!els.chartSymbol) return;
  els.chartSymbol.innerHTML = '<option value="">Selecciona símbolo</option>' +
    state.items.map((i) => `<option value="${i.symbol}">${i.symbol} ${i.name ? `- ${i.name}` : ''}</option>`).join('');
  els.chartSymbol.value = state.selectedSymbol || '';
};

const bindUi = () => {
  els.chartSymbol?.addEventListener('change', async () => {
    state.selectedSymbol = els.chartSymbol.value || '';
    renderList();
    renderPanels();
    renderRavaSelected();
    await loadHistory(state.selectedSymbol);
    renderChart();
  });
  els.chartRange?.addEventListener('change', () => renderChart());
  document.getElementById('btn-reload')?.addEventListener('click', loadAll);
};

const renderStatus = () => {
  if (els.count) els.count.textContent = `Items: ${state.items.length}`;
  if (els.updated) els.updated.textContent = `As of: ${state.asOf}`;
  if (els.base) els.base.textContent = `Base: ${state.baseCurrency}`;
  if (els.chartSymbol && !els.chartSymbol.value) {
    els.chartSymbol.value = state.selectedSymbol || '';
  }
};

const renderRavaTables = () => {
  renderTable(els.tablaAcciones, els.metaAcciones, state.rava.acciones, 'acciones');
  renderTable(els.tablaBonos, els.metaBonos, state.rava.bonos, 'bonos');
  renderTable(els.tablaCedears, els.metaCedears, state.rava.cedears, 'cedears');
};

const loadAll = async () => {
  setError('');
  await overlay.withLoader(async () => {
    await loadHeatmap();
    if (state.items.length === 0) {
      setError('No hay instrumentos en tus portafolios.');
      return;
    }
    await loadRava();
    if (state.selectedSymbol) {
      await loadHistory(state.selectedSymbol);
    }
    state.asOf = new Date().toISOString();
  });
  populateSelectors();
  renderList();
  renderPanels();
  renderChart();
  renderStatus();
  renderRavaSelected();
  renderRavaTables();
  console.info('[analisis] carga completa', {
    instrumentos: state.items.length,
    rava_acciones: state.rava.acciones.length,
    rava_bonos: state.rava.bonos.length,
    rava_cedears: state.rava.cedears.length,
  });
};

document.addEventListener('DOMContentLoaded', () => {
  els.list = document.getElementById('instrument-list');
  els.analysis = document.getElementById('analysis-block');
  els.details = document.getElementById('details-block');
  els.error = document.getElementById('error-box');
  els.count = document.getElementById('badge-count');
  els.updated = document.getElementById('badge-updated');
  els.base = document.getElementById('badge-base');
  els.meta = document.getElementById('selected-meta');
  els.chartSymbol = document.getElementById('chart-symbol');
  els.chartRange = document.getElementById('chart-range');
  els.chartMeta = document.getElementById('chart-meta');
  els.chartCanvas = document.getElementById('history-chart');
  els.ravaSelected = document.getElementById('rava-selected');
  els.tablaAcciones = document.getElementById('tabla-acciones');
  els.tablaBonos = document.getElementById('tabla-bonos');
  els.tablaCedears = document.getElementById('tabla-cedears');
  els.metaAcciones = document.getElementById('rava-acciones-meta');
  els.metaBonos = document.getElementById('rava-bonos-meta');
  els.metaCedears = document.getElementById('rava-cedears-meta');

  bindUi();
  loadAll();
});
