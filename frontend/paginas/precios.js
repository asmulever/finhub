import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  selectedSymbols: [],
  selectedQuotes: [],
  tempQuote: null,
  loadingSelected: false,
  loadingTemp: false,
  errorSelected: '',
  errorTemp: '',
  exchange: '',
  preferred: 'eodhd',
  forceRefresh: false,
  cache: new Map(),
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

const formatCurrency = (value) => {
  if (typeof value !== 'number') {
    return '---';
  }
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
};

const renderProviderSection = (providerResult) => {
  const provider = providerResult.provider ?? 'N/D';
  if (!providerResult.ok) {
    return '';
  }
  const q = providerResult.quote ?? {};
  if (q.close === undefined && q.price === undefined) return '';
  const asOf = q.asOf ? new Date(q.asOf).toLocaleString() : 'Fecha n/d';
  return `<div class="provider-row">
    <div><strong>${provider}</strong> <small class="muted">${q.currency ?? ''}</small></div>
    <div class="price-meta">
      <span>${formatCurrency(q.close ?? q.price)}</span>
      <small>${asOf}</small>
    </div>
    <div class="price-meta">
      <span>O:${formatCurrency(q.open ?? q.close)}</span>
      <span>H:${formatCurrency(q.high ?? q.close)}</span>
      <span>L:${formatCurrency(q.low ?? q.close)}</span>
      <span>P:${formatCurrency(q.previous_close ?? q.close)}</span>
    </div>
  </div>`;
};

const buildTile = (quote, label = '') => {
  const badge = label || quote.source || 'Mercado';
  const sources = Array.isArray(quote.sources) ? quote.sources.join(', ') : (quote.source ?? '');
  const cachedFlag = quote.cached ? ' • cacheado' : '';
  const providers = Array.isArray(quote.providers) ? quote.providers.filter((p) => p?.ok) : [];
  const providerBlocks = providers.map(renderProviderSection).filter(Boolean).join('');
  if (quote.error) {
    return `
      <article class="price-tile">
        <div class="price-badge">${badge}</div>
        <strong>${quote.symbol}</strong>
        <span class="price-error">${quote.error.message ?? 'No disponible'}</span>
        <button type="button" class="refresh-btn" data-refresh="${quote.symbol}">Refrescar e ingresar</button>
      </article>
    `;
  }
  const asOf = quote.asOf ? new Date(quote.asOf).toLocaleString() : 'Fecha no disponible';
  const datalakeBadge = quote.datalake ? `
    <div class="price-meta">
      <span>DataLake: ${formatCurrency(quote.datalake.close ?? quote.datalake.price)}</span>
      <small>${quote.datalake.asOf ? new Date(quote.datalake.asOf).toLocaleString() : ''}</small>
    </div>` : '';
  return `
    <article class="price-tile">
      <div class="price-badge">${badge}</div>
      <strong>${quote.symbol}${quote.name ? ` • ${quote.name}` : ''}</strong>
      <div class="price-meta">
        <span>${formatCurrency(quote.close)}</span>
        <small>${asOf}</small>
      </div>
      <div class="price-meta">
        <span>Máx ${formatCurrency(quote.high ?? quote.close)}</span>
        <span>Mín ${formatCurrency(quote.low ?? quote.close)}</span>
      </div>
      <div class="price-meta">
        <span>Apertura ${formatCurrency(quote.open ?? quote.close)}</span>
        <span>Prev ${formatCurrency(quote.previous_close ?? quote.close)}</span>
      </div>
      <div class="price-meta">
        <span>Fuentes: ${sources || 'N/D'}</span>
        <span>${cachedFlag}</span>
      </div>
      <div class="providers-block">
        ${providerBlocks || '<div class="muted">Sin datos de proveedores</div>'}
      </div>
      ${datalakeBadge}
      <button type="button" class="refresh-btn" data-refresh="${quote.symbol}">Refrescar e ingresar</button>
    </article>
  `;
};

const renderPrices = () => {
  const container = document.getElementById('prices-content');
  if (!container) return;

  if (state.loadingSelected && state.selectedQuotes.length === 0) {
    container.innerHTML = '<p class="muted">Cargando precios del portafolio...</p>';
    return;
  }

  const tiles = [];

  if (state.errorSelected) {
    tiles.push(`<p class="price-error">${state.errorSelected}</p>`);
  }
  if (state.errorTemp && !state.tempQuote) {
    tiles.push(`<p class="price-error">${state.errorTemp}</p>`);
  }

  state.selectedQuotes.forEach((quote) => {
    tiles.push(buildTile(quote, 'Portafolio'));
  });

  if (state.loadingTemp) {
    tiles.push('<p class="muted">Consultando precio...</p>');
  } else if (state.tempQuote) {
    tiles.push(buildTile(state.tempQuote, 'Consulta'));
  }

  if (tiles.length === 0) {
    container.innerHTML = '<p class="muted">No hay precios cargados. Agrega tickers a tu portafolio o consulta uno.</p>';
    return;
  }

  container.innerHTML = tiles.join('');
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
    state.exchange = exchCookie ? exchCookie.toUpperCase() : 'US';
    const forceCookie = getCookie(refreshCookieKey(state.profile));
    state.forceRefresh = forceCookie === '1';
  } catch (error) {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
};

const fetchSelectedSymbols = async () => {
  state.loadingSelected = true;
  state.errorSelected = '';
  renderPrices();
  try {
    const response = await getJson('/portfolio/instruments');
    const items = Array.isArray(response?.data) ? response.data : [];
    state.selectedSymbols = items.map((i) => String(i.symbol)).filter(Boolean);
  } catch (error) {
    state.selectedSymbols = [];
    state.errorSelected = error?.error?.message ?? 'No se pudieron cargar tus instrumentos';
  }
};

const fetchSelectedQuotes = async () => {
  if (!state.selectedSymbols.length) {
    state.selectedQuotes = [];
    state.loadingSelected = false;
    renderPrices();
    return;
  }
  const symbols = Array.from(new Set(state.selectedSymbols));
  const quotes = [];
  for (const symbol of symbols) {
    // sequential to avoid flooding if la lista es corta; se puede paralelizar si hace falta
    // eslint-disable-next-line no-await-in-loop
    const q = await fetchSelectedQuote(symbol);
    quotes.push(q);
  }
  state.selectedQuotes = quotes;
  state.loadingSelected = false;
  renderPrices();
};

const fetchQuoteSearch = async (symbol, { force = false } = {}) => {
  const cacheKey = `${symbol}|${state.exchange}|${state.preferred}`;
  if (!force && !state.forceRefresh && state.cache.has(cacheKey)) {
    return { ...state.cache.get(cacheKey), cached: true };
  }
  const params = new URLSearchParams();
  params.set('s', symbol);
  if (state.exchange) params.set('ex', state.exchange);
  params.set('preferred', state.preferred);
  if (force || state.forceRefresh) params.set('force', '1');
  try {
    const quote = await getJson(`/quote/search?${params.toString()}`);
    // Traer DataLake más reciente
    try {
      const dl = await getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`);
      quote.datalake = dl;
    } catch {
      quote.datalake = null;
    }
    state.cache.set(cacheKey, quote);
    return quote;
  } catch (error) {
    return { symbol, error: { message: error?.error?.message ?? 'No se pudo obtener el precio' } };
  }
};

const fetchSelectedQuote = async (symbol) => fetchQuoteSearch(symbol);

const fetchTempQuote = async (symbol) => {
  state.loadingTemp = true;
  state.tempQuote = null;
  state.errorTemp = '';
  renderPrices();
  const quote = await fetchQuoteSearch(symbol);
  state.tempQuote = quote;
  state.loadingTemp = false;
  renderPrices();
};

const onSubmit = (event) => {
  event.preventDefault();
  const input = document.getElementById('ticker-input');
  const symbol = input?.value.trim().toUpperCase();
  if (!symbol) {
    state.errorTemp = 'Ingresa un ticker';
    state.tempQuote = null;
    renderPrices();
    return;
  }
  const exInput = document.getElementById('exchange-input');
  state.exchange = exInput?.value.trim().toUpperCase() ?? '';
  const prefSelect = document.getElementById('preferred-select');
  state.preferred = prefSelect?.value || 'eodhd';
  const forceCheckbox = document.getElementById('force-refresh');
  state.forceRefresh = !!forceCheckbox?.checked;
  const profile = state.profile ?? authStore.getProfile();
  setCookie(exchangeCookieKey(profile), state.exchange || '');
  setCookie(refreshCookieKey(profile), state.forceRefresh ? '1' : '0');
  fetchTempQuote(symbol);
};

const init = () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
  });
  bindToolbarNavigation();
  highlightToolbar();
  renderPrices();
  loadProfile().then(() => {
    const exInput = document.getElementById('exchange-input');
    if (exInput && state.exchange) exInput.value = state.exchange;
    const forceCheckbox = document.getElementById('force-refresh');
    if (forceCheckbox) forceCheckbox.checked = state.forceRefresh;
  });
  fetchSelectedSymbols()
    .then(fetchSelectedQuotes)
    .catch(() => {
      state.loadingSelected = false;
      renderPrices();
    });
  const pricesContent = document.getElementById('prices-content');
  pricesContent?.addEventListener('click', async (event) => {
    const btn = event.target.closest('button[data-refresh]');
    if (!btn) return;
    const symbol = btn.dataset.refresh;
    btn.disabled = true;
    btn.textContent = 'Refrescando...';
    try {
      const refreshed = await fetchQuoteSearch(symbol, { force: true });
      if (state.tempQuote && state.tempQuote.symbol === symbol) {
        state.tempQuote = refreshed;
      }
      await postJson('/datalake/prices/collect', { symbols: [symbol] });
      await fetchSelectedQuotes();
    } finally {
      btn.disabled = false;
      btn.textContent = 'Refrescar e ingresar';
      renderPrices();
    }
  });
  document.getElementById('price-form')?.addEventListener('submit', onSubmit);
};

document.addEventListener('DOMContentLoaded', init);
