import { deleteJson, getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  stocks: [],
  filtered: [],
  loadingStocks: false,
  errorStocks: '',
  profile: null,
  category: '',
  searchTerm: '',
  selectedSymbols: new Set(),
  selectedItems: [],
  loadingSelected: false,
  errorSelected: '',
  exchange: '',
  preferred: 'eodhd',
  forceRefresh: false,
  quoteCache: new Map(),
  page: 1,
  pageSize: 12,
};

const isSelected = (symbol) => state.selectedSymbols.has(String(symbol ?? ''));

const formatCurrency = (value) => {
  if (!Number.isFinite(value)) return '—';
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
};

const getCookie = (name) => {
  const cookies = document.cookie ? document.cookie.split(';') : [];
  for (const raw of cookies) {
    const [k, ...rest] = raw.trim().split('=');
    if (k === name) {
      return decodeURIComponent(rest.join('='));
    }
  }
  return '';
};

const setCookie = (name, value) => {
  document.cookie = `${name}=${encodeURIComponent(value || '')}; path=/; expires=Fri, 31 Dec 9999 23:59:59 GMT`;
};

const exchangeCookieKey = (profile) => {
  const email = profile?.email ? String(profile.email).toLowerCase().replace(/[^a-z0-9._-]/g, '') : 'default';
  return `eodhd_exchange_${email}`;
};

const refreshCookieKey = (profile) => {
  const email = profile?.email ? String(profile.email).toLowerCase().replace(/[^a-z0-9._-]/g, '') : 'default';
  return `quote_force_${email}`;
};

const renderSelected = () => {
  const container = document.getElementById('selected-container');
  if (!container) return;
  if (state.loadingSelected) {
    container.innerHTML = '<p class="muted">Cargando cartera...</p>';
    return;
  }
  if (state.errorSelected) {
    container.innerHTML = `<p class="price-error">${state.errorSelected}</p>`;
    return;
  }
  if (state.selectedItems.length === 0) {
    container.innerHTML = '<p class="muted">Aún no tienes instrumentos en tu cartera.</p>';
    return;
  }
  const tiles = state.selectedItems.map((item) => {
    const quote = item.quote ?? {};
    const price =
      Number.isFinite(quote?.close) ? quote.close :
      Number.isFinite(quote?.price) ? quote.price :
      Number.isFinite(quote?.c) ? quote.c : null;
    const asOf = quote?.asOf ?? quote?.t ?? quote?.time ?? null;
    const sources = Array.isArray(quote?.sources) ? quote.sources.join(', ') : (quote?.source ?? '');
    const cachedFlag = quote?.cached ? ' • cacheado' : '';
    const priceLine = quote?.error
      ? `<span class="price-error">${quote.error.message ?? 'Precio no disponible'}</span>`
      : `<div class="price-meta">
          <strong class="price-value">${formatCurrency(price)}</strong>
          <small>${asOf ? new Date(asOf).toLocaleString() : 'Fecha no disponible'}</small>
          <small class="muted">Fuentes: ${sources || 'N/D'}${cachedFlag}</small>
        </div>`;
    return `
      <article class="stock-tile selected-tile">
        <div class="stock-symbol">
          <strong>${item.symbol}</strong>
          <span class="stock-tag">${item.exchange ?? 'N/D'}</span>
        </div>
        <div class="stock-meta">${item.name ?? 'Sin nombre'}</div>
        ${priceLine}
        <button type="button" class="remove-btn" data-remove="${item.symbol}">Quitar</button>
      </article>
    `;
  });
  container.innerHTML = tiles.join('');
};

const renderStocks = () => {
  const container = document.getElementById('stocks-container');
  if (!container) return;
  if (state.loadingStocks) {
    container.innerHTML = '<p class="muted">Cargando tickers...</p>';
    return;
  }
  if (state.errorStocks) {
    container.innerHTML = `<p class="price-error">${state.errorStocks}</p>`;
    return;
  }
  if (!state.category) {
    container.innerHTML = '<p class="muted">Selecciona una categoría para listar instrumentos.</p>';
    return;
  }
  if (state.filtered.length === 0) {
    container.innerHTML = '<p class="muted">No se encontraron tickers con ese filtro.</p>';
    return;
  }
  const start = (state.page - 1) * state.pageSize;
  const end = start + state.pageSize;
  const pageItems = state.filtered.slice(start, end);
  const rows = pageItems.map((item) => `
    <tr>
      <td><strong>${item.symbol}</strong></td>
      <td>${item.name ?? 'Sin nombre'}</td>
      <td>${item.exchange ?? 'N/D'}</td>
      <td>${item.currency ?? 'N/D'}</td>
      <td>${item.type ?? 'N/D'}</td>
      <td style="text-align:center;"><input type="checkbox" disabled ${item.in_eodhd ? 'checked' : ''} /></td>
      <td style="text-align:center;"><input type="checkbox" disabled ${item.in_twelvedata ? 'checked' : ''} /></td>
      <td><button type="button" class="add-btn" data-symbol="${item.symbol}" ${isSelected(item.symbol) ? 'disabled' : ''}>
        ${isSelected(item.symbol) ? 'Agregado' : 'Agregar'}
      </button></td>
    </tr>
  `).join('');
  const totalPages = Math.max(1, Math.ceil(state.filtered.length / state.pageSize));
  container.innerHTML = `
    <div class="table-wrapper">
      <table class="stocks-table">
        <thead>
          <tr>
            <th>Símbolo</th><th>Nombre</th><th>Exchange</th><th>Moneda</th><th>Tipo</th><th>EODHD</th><th>Twelve</th><th></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
    <div class="pagination">
      <button type="button" id="prev-page" ${state.page <= 1 ? 'disabled' : ''}>Anterior</button>
      <span>Página ${state.page} de ${totalPages}</span>
      <button type="button" id="next-page" ${state.page >= totalPages ? 'disabled' : ''}>Siguiente</button>
    </div>
  `;
  document.getElementById('prev-page')?.addEventListener('click', () => {
    if (state.page > 1) {
      state.page -= 1;
      renderStocks();
    }
  });
  document.getElementById('next-page')?.addEventListener('click', () => {
    const total = Math.ceil(state.filtered.length / state.pageSize);
    if (state.page < total) {
      state.page += 1;
      renderStocks();
    }
  });
};

const matchesCategory = (item, category) => {
  const micCode = (item.mic_code ?? '').toUpperCase();
  const type = (item.type ?? '').toLowerCase();
  const currency = (item.currency ?? '').toUpperCase();
  switch (category) {
    case 'on':
      return micCode === 'XBUE' && type === 'common stock' && currency === 'ARS';
    case 'cedear':
      return micCode === 'XBUE' && type === 'depositary receipt';
    case 'bond':
      return type.includes('bond') || type.includes('debenture');
    case 'etf':
      return type.includes('etf') || type.includes('trust');
    default:
      return true;
  }
};

const matchesSearch = (item, term) => {
  if (!term) return true;
  const needle = term.toLowerCase();
  return (
    (item.symbol ?? '').toLowerCase().includes(needle) ||
    (item.name ?? '').toLowerCase().includes(needle) ||
    (item.exchange ?? '').toLowerCase().includes(needle) ||
    (item.currency ?? '').toLowerCase().includes(needle) ||
    (item.type ?? '').toLowerCase().includes(needle)
  );
};

const applyFilter = () => {
  if (!state.category) {
    state.filtered = [];
    renderStocks();
    return;
  }
  state.filtered = state.stocks.filter((item) =>
    matchesCategory(item, state.category) && matchesSearch(item, state.searchTerm)
  );
  renderStocks();
};

const fetchSelectedQuote = async (symbol) => {
  const cacheKey = `${symbol}|${state.exchange}|${state.preferred}|${state.forceRefresh ? '1' : '0'}`;
  if (!state.forceRefresh && state.quoteCache.has(cacheKey)) {
    return { ...state.quoteCache.get(cacheKey), cached: true };
  }
  // Siempre intentar DataLake primero; si falla, disparar ingesta y reintentar.
  try {
    const quote = await getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`);
    state.quoteCache.set(cacheKey, quote);
    return quote;
  } catch {
    // Disparar ingesta puntual para el ticker
    try {
      await postJson('/datalake/prices/collect', { symbols: [symbol] });
      const quote = await getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`);
      state.quoteCache.set(cacheKey, quote);
      return quote;
    } catch (error) {
      return { symbol, error: { message: error?.error?.message ?? 'No se pudo obtener el precio del Data Lake' } };
    }
  }
};

const fetchSelectedPortfolio = async () => {
  state.loadingSelected = true;
  state.errorSelected = '';
  renderSelected();
  try {
    const response = await getJson('/portfolio/instruments');
    const items = Array.isArray(response?.data) ? response.data : [];
    state.selectedSymbols = new Set(
      items.filter((i) => i?.symbol).map((i) => String(i.symbol))
    );
    const enriched = [];
    for (const item of items) {
      // eslint-disable-next-line no-await-in-loop
      const quote = await fetchSelectedQuote(item.symbol);
      enriched.push({ ...item, quote });
    }
    state.selectedItems = enriched;
  } catch (error) {
    state.selectedItems = [];
    state.selectedSymbols = new Set();
    state.errorSelected = error?.error?.message ?? 'No se pudo cargar tu cartera';
  } finally {
    state.loadingSelected = false;
    renderSelected();
  }
};

const fetchStocks = async () => {
  state.loadingStocks = true;
  state.errorStocks = '';
  state.stocks = [];
  state.filtered = [];
  state.page = 1;
  renderStocks();
  const filterInput = document.getElementById('filter-input');
  if (filterInput) filterInput.disabled = true;
  try {
    const exch = state.exchange || 'US';
    const response = await getJson(`/quote/symbols?exchange=${encodeURIComponent(exch)}`);
    state.stocks = Array.isArray(response?.data) ? response.data : [];
    applyFilter();
    if (filterInput) {
      filterInput.disabled = state.filtered.length === 0;
    }
  } catch (error) {
    state.errorStocks = error?.error?.message ?? 'No se pudo obtener el listado de tickers';
    state.stocks = [];
    state.filtered = [];
    if (filterInput) filterInput.disabled = true;
  } finally {
    state.loadingStocks = false;
    renderStocks();
  }
};

const handleAddToPortfolio = async (event) => {
  const button = event.target.closest('button[data-symbol]');
  if (!button || button.disabled) return;
  const symbol = button.dataset.symbol;
  const instrument = state.stocks.find((item) => item.symbol === symbol);
  if (!instrument) return;
  button.disabled = true;
  try {
    await postJson('/portfolio/instruments', {
      symbol: instrument.symbol,
      name: instrument.name ?? '',
      exchange: instrument.exchange ?? '',
      currency: instrument.currency ?? '',
      country: instrument.country ?? '',
      type: instrument.type ?? '',
      mic_code: instrument.mic_code ?? '',
    });
    state.selectedSymbols.add(symbol);
    const quote = await fetchSelectedQuote(symbol);
    const exists = state.selectedItems.some((i) => i.symbol === symbol);
    if (!exists) {
      state.selectedItems.push({ ...instrument, quote });
      state.selectedItems.sort((a, b) => a.symbol.localeCompare(b.symbol));
    }
    renderSelected();
    applyFilter();
  } catch (error) {
    button.disabled = false;
    const container = document.getElementById('stocks-container');
    if (container) {
      container.insertAdjacentHTML(
        'afterbegin',
        `<p class="price-error">No se pudo agregar ${symbol}: ${error?.error?.message ?? 'Error inesperado'}</p>`
      );
    }
  }
};

const handleRemoveFromPortfolio = async (event) => {
  const button = event.target.closest('button[data-remove]');
  if (!button) return;
  const symbol = button.dataset.remove;
  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = 'Quitando...';
  try {
    await deleteJson(`/portfolio/instruments/${encodeURIComponent(symbol)}`);
    state.selectedSymbols.delete(symbol);
    state.selectedItems = state.selectedItems.filter((item) => item.symbol !== symbol);
    renderSelected();
    applyFilter();
  } catch (error) {
    button.disabled = false;
    button.textContent = originalText;
    const container = document.getElementById('selected-container');
    container?.insertAdjacentHTML(
      'afterbegin',
      `<p class="price-error">No se pudo quitar ${symbol}: ${error?.error?.message ?? 'Error inesperado'}</p>`
    );
  }
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
    state.profile = await getJson('/me');
    setToolbarUserName(state.profile?.email ?? '');
    setAdminMenuVisibility(state.profile);
    const exchCookie = getCookie(exchangeCookieKey(state.profile));
    if (exchCookie) {
      state.exchange = exchCookie.toUpperCase();
    } else {
      state.exchange = 'US';
    }
    const forceCookie = getCookie(refreshCookieKey(state.profile));
    state.forceRefresh = forceCookie === '1';
  } catch {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
};

const bindTabs = () => {
  const tabs = document.querySelectorAll('.tab-btn');
  const panels = document.querySelectorAll('.tab-panel');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      tabs.forEach((btn) => btn.classList.toggle('active', btn === tab));
      panels.forEach((panel) => {
        panel.classList.toggle('active', panel.id === `tab-${target}`);
      });
    });
  });
};

const init = () => {
  renderToolbar();
  bindToolbarNavigation();
  highlightToolbar();
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
  });
  setToolbarUserName('');
  loadProfile();
  fetchSelectedPortfolio();
  bindTabs();

  const filterInput = document.getElementById('filter-input');
  const categoryFilter = document.getElementById('category-filter');
  const container = document.getElementById('stocks-container');
  const selectedContainer = document.getElementById('selected-container');
  const exchangeSelect = document.getElementById('exchange-select');
  filterInput?.addEventListener('input', (event) => {
    state.searchTerm = event.target.value.trim();
    applyFilter();
  });
  categoryFilter?.addEventListener('change', (event) => {
    state.category = event.target.value;
    state.searchTerm = '';
    if (filterInput) {
      filterInput.value = '';
    }
    if (!state.category) {
      state.filtered = [];
      if (filterInput) filterInput.disabled = true;
      renderStocks();
      return;
    }
    fetchStocks();
  });
  exchangeSelect?.addEventListener('change', (event) => {
    state.exchange = event.target.value.toUpperCase();
    setCookie(exchangeCookieKey(state.profile), state.exchange);
    fetchStocks();
  });
  container?.addEventListener('click', handleAddToPortfolio);
  selectedContainer?.addEventListener('click', handleRemoveFromPortfolio);
};

document.addEventListener('DOMContentLoaded', init);
