import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  quote: null,
  loading: false,
  error: '',
};

const formatCurrency = (value) => {
  if (typeof value !== 'number') {
    return '---';
  }
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
};

const buildTile = (quote) => {
  if (quote.error) {
    return `
      <article class="price-tile">
        <div class="price-badge">${quote.symbol}</div>
        <strong>${quote.symbol}</strong>
        <span class="price-error">${quote.error.message ?? 'No disponible'}</span>
      </article>
    `;
  }
  const asOf = quote.asOf ? new Date(quote.asOf).toLocaleString() : 'Fecha no disponible';
  return `
    <article class="price-tile">
      <div class="price-badge">${quote.source ?? 'Mercado'}</div>
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

const renderQuote = () => {
  const container = document.getElementById('prices-content');
  if (!container) return;
  if (state.loading) {
    container.innerHTML = '<p class="muted">Consultando precio...</p>';
    return;
  }
  if (state.error) {
    container.innerHTML = `<p class="price-error">${state.error}</p>`;
    return;
  }
  if (!state.quote) {
    container.innerHTML = '<p class="muted">Ingresa un ticker y presiona "Consultar".</p>';
    return;
  }
  container.innerHTML = buildTile(state.quote);
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

const fetchPrice = async (symbol) => {
  state.loading = true;
  state.error = '';
  renderQuote();
  try {
    const quote = await getJson(`/prices?symbol=${encodeURIComponent(symbol)}`);
    state.quote = quote;
    state.error = '';
  } catch (error) {
    state.quote = null;
    state.error = error?.error?.message ?? 'No se pudo obtener el precio';
  } finally {
    state.loading = false;
    renderQuote();
  }
};

const onSubmit = (event) => {
  event.preventDefault();
  const input = document.getElementById('ticker-input');
  const symbol = input?.value.trim().toUpperCase();
  if (!symbol) {
    state.error = 'Ingresa un ticker';
    state.quote = null;
    renderQuote();
    return;
  }
  fetchPrice(symbol);
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
  renderQuote();
  loadProfile();
  document.getElementById('price-form')?.addEventListener('submit', onSubmit);
  const tickerInput = document.getElementById('ticker-input');
  if (tickerInput) {
    tickerInput.value = 'AAPL';
    fetchPrice('AAPL');
  }
};

document.addEventListener('DOMContentLoaded', init);
