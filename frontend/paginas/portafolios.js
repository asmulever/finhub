import { getJson, postJson, deleteJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  profile: null,
  items: [],
  filtered: [],
  portfolio: [],
  catalogIndex: new Map(),
  counts: { all: 0, cedears: 0, acciones: 0, bonos: 0, portfolio: 0 },
  historicosCache: {},
  historicoItems: [],
  historicoSymbol: '',
  historicoCurrency: 'ARS',
  historicoPeriod: 'all',
  targetCurrency: 'ARS',
  fx: { rate: null, asOf: null, source: 'AlphaVantage', fetchedAt: 0 },
};

const setError = (msg) => {
  const el = document.getElementById('rava-error');
  if (el) el.textContent = msg || '';
};

const ensureAuthenticated = () => {
  const token = authStore.getToken() ?? localStorage.getItem('jwt');
  if (!token) {
    const target = '/';
    if (window.top && window.top !== window) {
      window.top.location.href = target;
    } else {
      window.location.href = target;
    }
  }
};

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
};

const formatCompact = (value, decimals = 1) => {
  if (!Number.isFinite(Number(value))) return '—';
  const num = Number(value);
  const abs = Math.abs(num);
  if (abs >= 1_000_000) {
    return `${formatNumber(num / 1_000_000, decimals)}M`;
  }
  if (abs >= 1_000) {
    return `${formatNumber(num / 1_000, decimals)}k`;
  }
  return formatNumber(num, Math.min(decimals, 2));
};

const formatSignedPercent = (value) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return { text: '–', className: '' };
  const num = Number(value);
  const className = num > 0 ? 'pos' : (num < 0 ? 'neg' : '');
  const text = `${num > 0 ? '+' : ''}${formatNumber(num, 2)}%`;
  return { text, className };
};

const setFxBadge = () => {
  const badge = document.getElementById('rava-badge-fx');
  if (!badge) return;
  if (state.fx.rate && state.fx.asOf) {
    badge.textContent = `FX USD/ARS: ${formatNumber(state.fx.rate, 4)} · ${String(state.fx.asOf).slice(0, 16)}`;
  } else {
    badge.textContent = 'FX USD/ARS: --';
  }
};

const convertPrice = (value, currency = 'ARS') => {
  const target = (state.targetCurrency || 'ARS').toUpperCase();
  const from = (currency || '').toUpperCase() || 'ARS';
  if (!Number.isFinite(Number(value))) return null;
  const num = Number(value);
  if (target === from || target === '' || from === '') return num;
  const rate = state.fx.rate;
  if (!Number.isFinite(rate)) return null;
  // rate = ARS por USD
  if (from === 'USD' && target === 'ARS') return num * rate;
  if (from === 'ARS' && target === 'USD') return num / rate;
  return num; // no conversión para otros pares
};

const normalizeCatalogItems = (items) => {
  if (!Array.isArray(items)) return [];
  return items.map((row) => {
    const symbol = String(row.symbol ?? '').toUpperCase();
    const tipo = row.tipo ?? row.type ?? '';
    return {
      symbol,
      especie: row.especie ?? row.symbol ?? symbol,
      name: row.name ?? row.nombre ?? symbol,
      panel: row.panel ?? '',
      mercado: row.mercado ?? '',
      tipo,
      currency: row.currency ?? '',
      source: row.source ?? '',
      price: Number.isFinite(row.price) ? Number(row.price) : null,
      var_pct: Number.isFinite(row.var_pct) ? Number(row.var_pct) : null,
      var_mtd: Number.isFinite(row.var_mtd) ? Number(row.var_mtd) : null,
      var_ytd: Number.isFinite(row.var_ytd) ? Number(row.var_ytd) : null,
      volume_nominal: row.volume_nominal ?? null,
      volume_efectivo: row.volume_efectivo ?? null,
      anterior: row.anterior ?? null,
      apertura: row.apertura ?? null,
      maximo: row.maximo ?? null,
      minimo: row.minimo ?? null,
      as_of: row.as_of ?? null,
      operaciones: row.operaciones ?? null,
    };
  }).filter((row) => row.symbol);
};

const ensureChartOverlay = () => {
  let overlayEl = document.getElementById('chart-overlay');
  if (!overlayEl) {
    overlayEl = document.createElement('div');
    overlayEl.id = 'chart-overlay';
    overlayEl.className = 'chart-overlay';
    document.body.appendChild(overlayEl);
  }
  return overlayEl;
};

const drawCandles = (canvas, items) => {
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const dpr = window.devicePixelRatio || 1;
  const width = canvas.clientWidth * dpr;
  const height = canvas.clientHeight * dpr;
  canvas.width = width;
  canvas.height = height;
  ctx.scale(dpr, dpr);
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  if (!items || items.length === 0) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', 20, 30);
    return;
  }

  const padding = { top: 20, right: 20, bottom: 30, left: 50 };
  const plotW = canvas.clientWidth - padding.left - padding.right;
  const plotH = canvas.clientHeight - padding.top - padding.bottom;
  const highs = items.map((p) => Number(p.high)).filter(Number.isFinite);
  const lows = items.map((p) => Number(p.low)).filter(Number.isFinite);
  if (highs.length === 0 || lows.length === 0) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', 20, 30);
    return;
  }
  const max = Math.max(...highs);
  const min = Math.min(...lows);
  const range = max - min || 1;
  const barW = Math.max(3, Math.min(18, plotW / Math.max(items.length, 1)));
  const gap = Math.min(6, barW * 0.3);

  ctx.strokeStyle = '#334155';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(padding.left, padding.top);
  ctx.lineTo(padding.left, padding.top + plotH);
  ctx.lineTo(padding.left + plotW, padding.top + plotH);
  ctx.stroke();

  items.forEach((p, idx) => {
    const x = padding.left + idx * (barW + gap);
    const yHigh = padding.top + (1 - (Number(p.high) - min) / range) * plotH;
    const yLow = padding.top + (1 - (Number(p.low) - min) / range) * plotH;
    const yOpen = padding.top + (1 - (Number(p.open) - min) / range) * plotH;
    const yClose = padding.top + (1 - (Number(p.close) - min) / range) * plotH;
    const rising = Number(p.close) >= Number(p.open);
    ctx.strokeStyle = rising ? '#22c55e' : '#ef4444';
    ctx.fillStyle = rising ? '#22c55e' : '#ef4444';
    ctx.beginPath();
    ctx.moveTo(x + barW / 2, yHigh);
    ctx.lineTo(x + barW / 2, yLow);
    ctx.stroke();
    const rectY = Math.min(yOpen, yClose);
    const rectH = Math.max(2, Math.abs(yClose - yOpen));
    ctx.fillRect(x, rectY, barW, rectH);
  });
};

const renderChartOverlay = (symbol, items, period) => {
  const overlayEl = ensureChartOverlay();
  overlayEl.innerHTML = '';
  const modal = document.createElement('div');
  modal.className = 'chart-modal';
  const header = document.createElement('header');
  const title = document.createElement('h4');
  title.textContent = `Gráfico ${symbol}`;
  const controls = document.createElement('div');
  controls.className = 'chart-controls';
  const select = document.createElement('select');
  select.innerHTML = `
    <option value="all">Todos</option>
    <option value="3m">Últimos 3 meses</option>
    <option value="6m">Últimos 6 meses</option>
  `;
  select.value = period;
  const closeBtn = document.createElement('button');
  closeBtn.className = 'chart-close';
  closeBtn.textContent = 'Cerrar';
  controls.appendChild(select);
  controls.appendChild(closeBtn);
  header.appendChild(title);
  header.appendChild(controls);
  const canvasWrap = document.createElement('div');
  canvasWrap.className = 'chart-canvas-wrap';
  const canvas = document.createElement('canvas');
  canvas.style.width = '100%';
  canvas.style.height = '420px';
  canvasWrap.appendChild(canvas);
  modal.appendChild(header);
  modal.appendChild(canvasWrap);
  overlayEl.appendChild(modal);
  overlayEl.classList.add('visible');
  overlayEl.setAttribute('aria-hidden', 'false');

  const applyDraw = (per) => {
    let filtered = items;
    if (per === '3m' || per === '6m') {
      const months = per === '3m' ? 3 : 6;
      const cutoff = new Date();
      cutoff.setMonth(cutoff.getMonth() - months);
      filtered = items.filter((p) => {
        const d = p.fecha ? new Date(p.fecha) : (p.t ? new Date(p.t) : null);
        return d && d >= cutoff;
      });
    }
    if (!filtered || filtered.length === 0) {
      canvasWrap.innerHTML = '<div class="chart-empty">Sin datos en el período seleccionado</div>';
    } else {
      canvasWrap.innerHTML = '';
      canvasWrap.appendChild(canvas);
      const mapped = filtered.map((p) => ({
        open: p.apertura ?? p.open ?? p.price ?? p.close,
        high: p.maximo ?? p.high ?? p.price ?? p.close,
        low: p.minimo ?? p.low ?? p.price ?? p.close,
        close: p.cierre ?? p.close ?? p.price,
      }));
      drawCandles(canvas, mapped);
    }
  };

  applyDraw(period);

  select.addEventListener('change', () => {
    state.historicoPeriod = select.value;
    applyDraw(select.value);
  });

  const close = () => {
    overlayEl.classList.remove('visible');
    overlayEl.setAttribute('aria-hidden', 'true');
  };
  closeBtn.addEventListener('click', close);
  overlayEl.addEventListener('click', (e) => {
    if (e.target === overlayEl) close();
  });
};

const loadHistoricos = async (especie, { openOverlay = false } = {}) => {
  const normalized = (especie || '').trim();
  if (!normalized) return;
  const found = state.items.find((i) => i.symbol === normalized.toUpperCase()) || state.portfolio.find((i) => i.symbol === normalized.toUpperCase());
  state.historicoCurrency = (found?.currency || 'ARS').toUpperCase();
  const especieParam = (found?.especie || normalized).trim();
  const cacheKey = especieParam.toUpperCase();
  if (state.historicosCache[cacheKey]) {
    state.historicoSymbol = especieParam;
    state.historicoItems = state.historicosCache[cacheKey].data ?? [];
    try { console.table(state.historicoItems); } catch {}
    if (openOverlay) {
      renderChartOverlay(especieParam, state.historicoItems, state.historicoPeriod || 'all');
    }
    return;
  }
  try {
    const resp = await overlay.withLoader(() => getJson(`/rava/historicos?especie=${encodeURIComponent(especieParam)}`));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    state.historicoSymbol = especieParam;
    state.historicoItems = Array.isArray(items) ? items : [];
    state.historicosCache[cacheKey] = { data: state.historicoItems };
    try { console.table(state.historicoItems); } catch {}
    if (openOverlay) {
      renderChartOverlay(especieParam, state.historicoItems, state.historicoPeriod || 'all');
    }
  } catch (error) {
    console.info('[portafolios] historicos error', error);
    if (openOverlay) {
      const overlayEl = ensureChartOverlay();
      overlayEl.innerHTML = '<div class="chart-modal"><p class="chart-empty">No se pudo obtener histórico</p></div>';
      overlayEl.classList.add('visible');
    }
  }
};

const normalizeItems = (lists) => {
  const map = new Map();
  const push = (arr, type) => {
    (arr ?? []).forEach((row) => {
      const symbol = String(row.symbol ?? row.especie ?? '').toUpperCase();
      if (!symbol) return;
      if (map.has(symbol)) return;
      map.set(symbol, {
        symbol,
        especie: row.especie ?? row.symbol ?? symbol,
        name: row.nombre ?? row.name ?? row.especie ?? row.symbol ?? symbol,
        panel: row.panel ?? '',
        mercado: row.mercado ?? '',
        tipo: type,
        currency: row.currency ?? row.moneda ?? '',
        price: Number(row.ultimo ?? row.close ?? row.price ?? null),
        var_pct: Number.isFinite(row.variacion) ? Number(row.variacion) : null,
        var_mtd: Number.isFinite(row.var_mtd) ? Number(row.var_mtd) : null,
        var_ytd: Number.isFinite(row.var_ytd) ? Number(row.var_ytd) : null,
        volume_nominal: row.volumen_nominal ?? row.volnominal ?? null,
        volume_efectivo: row.volumen_efectivo ?? row.volefectivo ?? null,
        anterior: row.anterior ?? null,
        apertura: row.apertura ?? null,
        maximo: row.maximo ?? null,
        minimo: row.minimo ?? null,
        as_of: row.as_of ?? row.fecha ?? null,
        operaciones: row.operaciones ?? null,
      });
    });
  };
  push(lists.cedears, 'CEDEAR');
  push(lists.acciones, 'ACCION_AR');
  push(lists.bonos, 'BONO');
  return Array.from(map.values());
};

const rebuildCatalogIndex = () => {
  const map = new Map();
  state.items.forEach((item) => {
    map.set(item.symbol, item);
  });
  state.catalogIndex = map;
};

const formatAsOf = (value) => {
  if (!value) return '—';
  const dt = new Date(value);
  if (Number.isNaN(dt.getTime())) {
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}/.test(text)) {
      return `${text.slice(8, 10)}/${text.slice(5, 7)}/${text.slice(2, 4)}:${text.slice(11, 16)}`;
    }
    return text.slice(0, 16);
  }
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(dt.getDate())}/${pad(dt.getMonth() + 1)}/${String(dt.getFullYear()).slice(-2)}:${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
};

const syncPortfolioFromCatalog = (list = state.portfolio) => {
  if (!(state.catalogIndex instanceof Map) || state.catalogIndex.size === 0) return list;
  return list.map((p) => {
    const cat = state.catalogIndex.get(p.symbol);
    if (!cat) return p;
    return {
      ...p,
      name: cat.name || p.name || p.symbol,
      especie: cat.especie ?? p.especie ?? p.symbol,
      price: Number.isFinite(cat.price) ? cat.price : p.price,
      var_pct: Number.isFinite(cat.var_pct) ? cat.var_pct : p.var_pct,
      var_mtd: Number.isFinite(cat.var_mtd) ? cat.var_mtd : p.var_mtd,
      var_ytd: Number.isFinite(cat.var_ytd) ? cat.var_ytd : p.var_ytd,
      volume_nominal: cat.volume_nominal ?? p.volume_nominal,
      volume_efectivo: cat.volume_efectivo ?? p.volume_efectivo,
      anterior: cat.anterior ?? p.anterior,
      apertura: cat.apertura ?? p.apertura,
      maximo: cat.maximo ?? p.maximo,
      minimo: cat.minimo ?? p.minimo,
      as_of: cat.as_of ?? p.as_of,
      tipo: cat.tipo ?? p.tipo,
      panel: cat.panel ?? p.panel,
      mercado: cat.mercado ?? p.mercado,
      currency: cat.currency ?? p.currency,
    };
  });
};

const renderList = () => {
  const list = document.getElementById('rava-list');
  if (!list) return;
  if (state.filtered.length === 0) {
    list.innerHTML = '<p class="muted">Sin resultados</p>';
    return;
  }
  const category = document.getElementById('rava-category')?.value || 'all';
  list.innerHTML = state.filtered.map((item) => {
    const displayPrice = convertPrice(item.price, item.currency ?? 'ARS');
    const varPct = item.var_pct !== null && item.var_pct !== undefined ? `${item.var_pct.toFixed(2)}%` : '—';
    const varTooltip = 'Variación porcentual del instrumento (último vs. anterior)';
    const varMtd = item.var_mtd !== null && item.var_mtd !== undefined ? `${item.var_mtd.toFixed(2)}%` : '—';
    const varYtd = item.var_ytd !== null && item.var_ytd !== undefined ? `${item.var_ytd.toFixed(2)}%` : '—';
    const volumeNom = item.volume_nominal ?? item.volumen_nominal ?? null;
    const volumeEfe = convertPrice(item.volume_efectivo ?? item.volumen_efectivo ?? null, item.currency ?? 'ARS');
    const volLabel = item.volatility_30d !== null && item.volatility_30d !== undefined ? item.volatility_30d.toFixed(3) : '—';
    const asOf = formatAsOf(item.as_of);
    const volumeNomLabel = volumeNom !== null && volumeNom !== undefined ? formatCompact(volumeNom, 1) : '—';
    const volumeEfeLabel = volumeEfe !== null && volumeEfe !== undefined ? formatCompact(volumeEfe, 2) : '—';
    const volumeNomTitle = `Volumen nominal: cantidad de títulos negociados (unidades). Valor completo: ${volumeNom !== null && volumeNom !== undefined ? formatNumber(volumeNom, 0) : '—'}`;
    const volumeEfeTitle = `Volumen efectivo: monto operado en dinero (VNº x precio), en ${state.targetCurrency || item.currency || ''}. Valor completo: ${volumeEfe !== null && volumeEfe !== undefined ? formatNumber(volumeEfe, 2) : '—'}`;
    return `
      <div class="tile" data-symbol="${item.symbol}">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
          <div>
            <strong>${item.symbol}</strong>
            <small style="display:block;">${item.name || 'Sin nombre'}</small>
            <small class="muted">${item.tipo || item.type || 'N/D'} · ${state.targetCurrency || item.currency || 'N/D'}</small>
          </div>
          <div>
            <div style="font-size:1.1rem;font-weight:800;color:#e2e8f0;">${Number.isFinite(displayPrice) ? formatNumber(displayPrice, 2) : '—'}</div>
            <div title="${varTooltip}" style="font-size:0.85rem;color:${(item.var_pct ?? 0) < 0 ? '#ef4444' : '#22c55e'};">${varPct}</div>
            <div class="muted" style="font-size:0.85rem;">${asOf}</div>
          </div>
        </div>
        <div class="meta-row" style="margin-top:6px;">
          <span>Anterior: ${formatNumber(convertPrice(item.anterior, item.currency ?? 'ARS'), 2)}</span>
          <span>Apertura: ${formatNumber(convertPrice(item.apertura, item.currency ?? 'ARS'), 2)}</span>
          <span>Máximo: ${formatNumber(convertPrice(item.maximo, item.currency ?? 'ARS'), 2)}</span>
          <span>Mínimo: ${formatNumber(convertPrice(item.minimo, item.currency ?? 'ARS'), 2)}</span>
        </div>
        <div class="meta-row" style="font-size:0.9rem;gap:6px;align-items:center;">
          <span title="${volumeNomTitle}">${'VNº: '}<span title="${volumeNomTitle}">${volumeNomLabel}</span></span>
          <span title="${volumeEfeTitle}">${'VE$: '}<span title="${volumeEfeTitle}">${volumeEfeLabel}</span></span>
        </div>
      <button type="button" class="${category === 'selected' ? 'deselect-btn' : ''}" data-symbol="${item.symbol}">
          ${category === 'selected' ? 'Quitar de cartera' : 'Agregar a cartera'}
        </button>
      </div>
    `;
  }).join('');
  list.querySelectorAll('button[data-symbol]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const symbol = btn.getAttribute('data-symbol');
      const currentCategory = document.getElementById('rava-category')?.value || '';
      try {
        if (currentCategory === 'selected') {
          await overlay.withLoader(() => deleteJson(`/portfolio/instruments/${encodeURIComponent(symbol)}`));
          await loadPortfolio();
        } else {
          await overlay.withLoader(() => postJson('/portfolio/instruments', { symbol }));
          await loadPortfolio();
        }
        applyFilter();
      } catch (error) {
        setError(error?.error?.message ?? 'No se pudo procesar la acción');
      }
    });
  });
  list.querySelectorAll('.tile[data-symbol]').forEach((tile) => {
    tile.addEventListener('click', (event) => {
      if (event.target?.closest?.('button[data-symbol]')) return;
      const symbol = tile.getAttribute('data-symbol') || '';
      loadHistoricos(symbol, { openOverlay: true });
    });
  });
};

const applyFilter = () => {
  const category = document.getElementById('rava-category')?.value || 'all';
  const search = (document.getElementById('rava-search')?.value || '').toLowerCase();
  const sourceItems = category === 'selected' ? state.portfolio : state.items;
  state.filtered = sourceItems.filter((i) => {
    const okCat = category === 'all'
      ? true
      : category === 'selected'
        ? true
        : (category === 'cedears' && i.tipo === 'CEDEAR') || (category === 'acciones' && i.tipo === 'ACCION_AR') || (category === 'bonos' && i.tipo === 'BONO');
    const okSearch = search === '' || i.symbol.toLowerCase().includes(search) || (i.name ?? '').toLowerCase().includes(search);
    return okCat && okSearch;
  });
  renderList();
  const bPort = document.getElementById('rava-badge-portfolio');
  const bCed = document.getElementById('rava-badge-cedears');
  const bAcc = document.getElementById('rava-badge-acciones');
  const bBon = document.getElementById('rava-badge-bonos');
  if (bPort) bPort.textContent = `En cartera: ${state.counts.portfolio}`;
  if (bCed) bCed.textContent = `CEDEARs: ${state.counts.cedears}`;
  if (bAcc) bAcc.textContent = `Acciones: ${state.counts.acciones}`;
  if (bBon) bBon.textContent = `Bonos: ${state.counts.bonos}`;
  setFxBadge();
};

const fetchFxRate = async () => {
  const now = Date.now();
  if (state.fx.fetchedAt && (now - state.fx.fetchedAt) < 10000 && Number.isFinite(state.fx.rate)) {
    return;
  }
  try {
    const resp = await overlay.withLoader(() => getJson('/alphavantage/fx-daily?from=USD&to=ARS'));
    const payload = resp?.data ?? resp ?? {};
    const ts = payload['Time Series FX (Daily)'] ?? payload['Time Series FX (Daily)'] ?? payload['time series fx (daily)'];
    const meta = payload['Meta Data'] ?? payload['meta data'] ?? {};
    let asOf = meta['5. Last Refreshed'] ?? meta['6. Last Refreshed'] ?? '';
    let rate = null;
    if (ts && typeof ts === 'object') {
      const dates = Object.keys(ts);
      const latest = asOf !== '' ? asOf : (dates.sort((a, b) => b.localeCompare(a))[0] ?? '');
      const row = ts[latest] ?? {};
      rate = Number(row['4. close'] ?? row['1. open'] ?? row['2. high'] ?? row['3. low'] ?? null);
      asOf = latest || asOf;
    }
    if (Number.isFinite(rate)) {
      state.fx.rate = Number(rate);
      state.fx.asOf = asOf || null;
      state.fx.fetchedAt = Date.now();
      state.fx.source = 'AlphaVantage';
    } else {
      setError('No se pudo obtener FX (AlphaVantage)');
    }
  } catch (error) {
    setError(error?.error?.message ?? 'No se pudo obtener FX');
  } finally {
    setFxBadge();
  }
};

const withTimeout = (promise, ms = 5000) => {
  let timer;
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error('timeout')), ms);
  });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
};

const loadData = async () => {
  setError('');
  const [cedears, acciones, bonos] = await Promise.all([
    getJson('/rava/cedears').catch(() => ({ data: [] })),
    getJson('/rava/acciones').catch(() => ({ data: [] })),
    getJson('/rava/bonos').catch(() => ({ data: [] })),
  ]);
  state.items = normalizeItems({
    cedears: cedears?.data ?? [],
    acciones: acciones?.data ?? [],
    bonos: bonos?.data ?? [],
  });
  state.counts.cedears = (cedears?.data ?? []).length;
  state.counts.acciones = (acciones?.data ?? []).length;
  state.counts.bonos = (bonos?.data ?? []).length;

  rebuildCatalogIndex();
  state.counts.all = state.items.length;
  if (state.counts.cedears === 0 || state.counts.acciones === 0 || state.counts.bonos === 0) {
    state.counts.cedears = state.items.filter((i) => i.tipo === 'CEDEAR').length;
    state.counts.acciones = state.items.filter((i) => i.tipo === 'ACCION_AR').length;
    state.counts.bonos = state.items.filter((i) => i.tipo === 'BONO').length;
  }
  state.portfolio = syncPortfolioFromCatalog(state.portfolio).map((p) => ({
    ...p,
    name: p.name || p.symbol,
  }));
  if (state.items.length === 0) {
    setError('No se pudo cargar el catálogo de instrumentos');
  }
};

const bindUi = () => {
  document.getElementById('rava-btn-reload')?.addEventListener('click', () => overlay.withLoader(async () => {
    await loadData({ forceSync: true });
    applyFilter();
  }));
  document.getElementById('rava-category')?.addEventListener('change', applyFilter);
  document.getElementById('rava-search')?.addEventListener('input', applyFilter);
  document.getElementById('rava-currency')?.addEventListener('change', async (event) => {
    state.targetCurrency = (event.target?.value || 'ARS').toUpperCase();
    if (state.targetCurrency === 'USD') {
      await fetchFxRate();
    }
    applyFilter();
  });
};

const loadPortfolio = async () => {
  setError('');
  try {
    const resp = await getJson('/portfolio/instruments');
    const items = Array.isArray(resp?.data) ? resp.data : [];
    const portfolio = items.map((row) => ({
      symbol: String(row.symbol ?? '').toUpperCase(),
      name: row.name ?? row.nombre ?? row.symbol ?? '',
      panel: row.panel ?? '',
      mercado: row.exchange ?? '',
      tipo: row.type ?? 'PORTFOLIO',
      currency: row.currency ?? '',
    })).filter((i) => i.symbol);

    try {
      const summaryResp = await withTimeout(getJson('/portfolio/summary'), 5000);
      const summaryItems = Array.isArray(summaryResp?.data) ? summaryResp.data : [];
      const summaryMap = new Map(summaryItems.map((row) => [String(row.symbol ?? '').toUpperCase(), row]));
      portfolio.forEach((p) => {
        const s = summaryMap.get(p.symbol);
        if (!s) return;
        p.price = Number.isFinite(s.price) ? s.price : p.price;
        p.var_pct = Number.isFinite(s.var_pct) ? s.var_pct : p.var_pct;
        p.var_mtd = Number.isFinite(s.var_mtd) ? s.var_mtd : p.var_mtd;
        p.var_ytd = Number.isFinite(s.var_ytd) ? s.var_ytd : p.var_ytd;
        p.signal = s.signal ?? p.signal;
        p.sma20 = Number.isFinite(s.sma20) ? s.sma20 : p.sma20;
        p.sma50 = Number.isFinite(s.sma50) ? s.sma50 : p.sma50;
        p.volatility_30d = Number.isFinite(s.volatility_30d) ? s.volatility_30d : p.volatility_30d;
        p.volume_nominal = s.volume_nominal ?? p.volume_nominal;
        p.volume_efectivo = s.volume_efectivo ?? p.volume_efectivo;
      });
    } catch (summaryError) {
      console.info('[portafolios] summary opcional no disponible', summaryError);
    }

    const enriched = syncPortfolioFromCatalog(portfolio);
    state.portfolio = enriched.map((p) => ({
      ...p,
      name: p.name || p.symbol,
    }));
    state.counts.portfolio = state.portfolio.length;
    console.info('[portafolios] cartera cargada', state.portfolio.length);
  } catch (error) {
    setError(error?.error?.message ?? 'No se pudo cargar tu portafolio');
    state.portfolio = [];
    state.counts.portfolio = 0;
  }
};

const init = async () => {
  ensureAuthenticated();
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
  await overlay.withLoader(async () => {
    await loadPortfolio();
    await loadData();
    await fetchFxRate();
    const categorySelect = document.getElementById('rava-category');
    if (categorySelect) {
      categorySelect.value = state.portfolio.length > 0 ? 'selected' : 'all';
    }
    applyFilter();
  });
  bindUi();
};

document.addEventListener('DOMContentLoaded', init);
