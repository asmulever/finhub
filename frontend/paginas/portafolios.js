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
  historicoMeta: {},
  historicoSymbol: '',
  historicoCurrency: 'ARS',
  targetCurrency: 'ARS',
  fx: { rate: null, asOf: null, source: 'AlphaVantage', fetchedAt: 0 },
};

const setError = (msg) => {
  const el = document.getElementById('rava-error');
  if (el) el.textContent = msg || '';
};

const setHistoryError = (msg) => {
  const el = document.getElementById('history-error');
  if (el) el.textContent = msg || '';
};

const setHistoryStatus = (msg) => {
  const el = document.getElementById('history-status');
  if (el) el.textContent = msg || '';
};

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
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

const renderHistoricosMeta = () => {
  const meta = state.historicoMeta || {};
  const extras = [];
  if (meta.from) extras.push(`desde ${meta.from}`);
  if (meta.to) extras.push(`hasta ${meta.to}`);
  if (meta.source) extras.push(meta.source);
  if (state.targetCurrency && state.targetCurrency !== 'ARS') {
    extras.push(`FX USD/ARS: ${state.fx.rate ? formatNumber(state.fx.rate, 4) : 'N/D'}`);
  }
  const metaEl = document.getElementById('history-meta');
  if (metaEl) metaEl.textContent = extras.join(' · ');
  const symbolEl = document.getElementById('history-symbol');
  if (symbolEl) symbolEl.textContent = `Símbolo: ${state.historicoSymbol || '--'} · ${state.targetCurrency || 'ARS'}`;
  const countEl = document.getElementById('history-count');
  if (countEl) countEl.textContent = `Registros: ${state.historicoItems.length || 0}`;
};

const renderHistoricosTable = () => {
  const body = document.getElementById('historicos-body');
  if (!body) return;
  if (!state.historicoSymbol) {
    body.innerHTML = '<tr><td colspan="8" class="muted">Selecciona un instrumento para ver su histórico.</td></tr>';
    return;
  }
  const ordered = [...(state.historicoItems || [])].sort((a, b) => (b.fecha ?? '').localeCompare(a.fecha ?? ''));
  const rows = ordered.map((item) => {
    const variation = formatSignedPercent(item.variacion);
    const currency = state.historicoCurrency || 'ARS';
    return `
      <tr>
        <td>${item.fecha ?? '–'}</td>
        <td>${formatNumber(convertPrice(item.apertura, currency), 2)}</td>
        <td>${formatNumber(convertPrice(item.maximo, currency), 2)}</td>
        <td>${formatNumber(convertPrice(item.minimo, currency), 2)}</td>
        <td>${formatNumber(convertPrice(item.cierre, currency), 2)}</td>
        <td class="${variation.className}">${variation.text}</td>
        <td>${formatNumber(item.volumen, 0)}</td>
        <td>${formatNumber(convertPrice(item.ajuste, currency), 2)}</td>
      </tr>
    `;
  }).join('');
  body.innerHTML = rows || '<tr><td colspan="8" class="muted">Sin datos</td></tr>';
};

const loadHistoricos = async (especie) => {
  const normalized = (especie || '').trim();
  if (!normalized) return;
  const found = state.items.find((i) => i.symbol === normalized.toUpperCase()) || state.portfolio.find((i) => i.symbol === normalized.toUpperCase());
  state.historicoCurrency = (found?.currency || 'ARS').toUpperCase();
  const cacheKey = normalized.toUpperCase();
  setHistoryError('');
  setHistoryStatus(`Cargando histórico para ${normalized}...`);
  if (state.historicosCache[cacheKey]) {
    state.historicoSymbol = normalized;
    state.historicoItems = state.historicosCache[cacheKey].data ?? [];
    state.historicoMeta = state.historicosCache[cacheKey].meta ?? {};
    renderHistoricosMeta();
    renderHistoricosTable();
    setHistoryStatus(`Histórico en caché para ${normalized}`);
    return;
  }
  try {
    const resp = await overlay.withLoader(() => getJson(`/rava/historicos?especie=${encodeURIComponent(normalized)}`));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    const meta = resp?.meta ?? {};
    state.historicoSymbol = normalized;
    state.historicoItems = Array.isArray(items) ? items : [];
    state.historicoMeta = meta;
    state.historicosCache[cacheKey] = { data: state.historicoItems, meta };
    renderHistoricosMeta();
    renderHistoricosTable();
    setHistoryStatus(`Histórico cargado para ${normalized}`);
  } catch (error) {
    console.info('[portafolios] historicos error', error);
    setHistoryError(error?.error?.message ?? 'No se pudo obtener histórico');
    setHistoryStatus(`Fallo al cargar ${normalized}`);
    renderHistoricosMeta();
    renderHistoricosTable();
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

const syncPortfolioFromCatalog = (list = state.portfolio) => {
  if (!(state.catalogIndex instanceof Map) || state.catalogIndex.size === 0) return list;
  return list.map((p) => {
    const cat = state.catalogIndex.get(p.symbol);
    if (!cat) return p;
    return {
      ...p,
      name: cat.name || p.name || p.symbol,
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
    const varMtd = item.var_mtd !== null && item.var_mtd !== undefined ? `${item.var_mtd.toFixed(2)}%` : '—';
    const varYtd = item.var_ytd !== null && item.var_ytd !== undefined ? `${item.var_ytd.toFixed(2)}%` : '—';
    const volumeNom = item.volume_nominal ?? item.volumen_nominal ?? null;
    const volumeEfe = convertPrice(item.volume_efectivo ?? item.volumen_efectivo ?? null, item.currency ?? 'ARS');
    const volLabel = item.volatility_30d !== null && item.volatility_30d !== undefined ? item.volatility_30d.toFixed(3) : '—';
    const asOf = item.as_of ? String(item.as_of).replace('T', ' ').slice(0, 16) : '—';
    return `
      <div class="tile" data-symbol="${item.symbol}">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
          <div>
            <strong>${item.symbol}</strong>
            <small style="display:block;">${item.name || 'Sin nombre'}</small>
            <small class="muted">${item.tipo || item.type || 'N/D'} · ${item.mercado || 'Mercado N/D'} · ${state.targetCurrency || item.currency || 'N/D'}</small>
          </div>
          <div>
            <div style="font-size:1.4rem;font-weight:800;color:#e2e8f0;">${Number.isFinite(displayPrice) ? formatNumber(displayPrice, 2) : '—'}</div>
            <div style="color:${(item.var_pct ?? 0) < 0 ? '#ef4444' : '#22c55e'};">${varPct}</div>
            <div class="muted">${asOf}</div>
          </div>
        </div>
        <div class="meta-row" style="margin-top:6px;">
          <span>Anterior: ${formatNumber(convertPrice(item.anterior, item.currency ?? 'ARS'), 2)}</span>
          <span>Apertura: ${formatNumber(convertPrice(item.apertura, item.currency ?? 'ARS'), 2)}</span>
          <span>Máximo: ${formatNumber(convertPrice(item.maximo, item.currency ?? 'ARS'), 2)}</span>
          <span>Mínimo: ${formatNumber(convertPrice(item.minimo, item.currency ?? 'ARS'), 2)}</span>
        </div>
        <div class="meta-row">
          <span>Vol. Nominal: ${volumeNom ?? '—'}</span>
          <span>Vol. Efectivo: ${volumeEfe !== null && volumeEfe !== undefined ? formatNumber(volumeEfe, 2) : '—'}</span>
          <span>Vol. Promedio: ${formatNumber(item.volumen_promedio, 2)}</span>
          <span>Volumen %: ${formatNumber(item.volumen_pct, 2)}</span>
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
      loadHistoricos(symbol);
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
    renderHistoricosTable();
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
  setHistoryStatus('Selecciona un instrumento para ver su histórico.');
  renderHistoricosMeta();
  renderHistoricosTable();
  bindUi();
};

document.addEventListener('DOMContentLoaded', init);
