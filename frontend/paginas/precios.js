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
};

const formatCurrency = (value) => {
  if (typeof value !== 'number') {
    return '---';
  }
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
};

const buildTile = (quote, label = '') => {
  const badge = label || quote.source || 'Mercado';
  if (quote.error) {
    return `
      <article class="price-tile">
        <div class="price-badge">${badge}</div>
        <strong>${quote.symbol}</strong>
        <span class="price-error">${quote.error.message ?? 'No disponible'}</span>
      </article>
    `;
  }
  const asOf = quote.asOf ? new Date(quote.asOf).toLocaleString() : 'Fecha no disponible';
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
  } catch (error) {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
};

const fetchQuote = async (symbol) => {
  try {
    const quote = await getJson(`/prices?symbol=${encodeURIComponent(symbol)}`);
    return quote;
  } catch (error) {
    return { symbol, error: { message: error?.error?.message ?? 'No se pudo obtener el precio' } };
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

const fetchSelectedQuote = async (symbol) => {
  try {
    const quote = await getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`);
    return quote;
  } catch (error) {
    return { symbol, error: { message: error?.error?.message ?? 'No se pudo obtener el precio del Data Lake' } };
  }
};

const fetchTempQuote = async (symbol) => {
  state.loadingTemp = true;
  state.tempQuote = null;
  state.errorTemp = '';
  renderPrices();
  const quote = await fetchQuote(symbol);
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
  loadProfile();
  fetchSelectedSymbols()
    .then(fetchSelectedQuotes)
    .catch(() => {
      state.loadingSelected = false;
      renderPrices();
    });
  document.getElementById('price-form')?.addEventListener('submit', onSubmit);
};

document.addEventListener('DOMContentLoaded', init);
