import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  stocks: [],
  filtered: [],
  loading: false,
  error: '',
  profile: null,
  category: 'all',
  searchTerm: '',
};

const renderStocks = () => {
  const container = document.getElementById('stocks-container');
  if (!container) return;
  if (state.loading) {
    container.innerHTML = '<p class="muted">Cargando tickers...</p>';
    return;
  }
  if (state.error) {
    container.innerHTML = `<p class="price-error">${state.error}</p>`;
    return;
  }
  if (state.filtered.length === 0) {
    container.innerHTML = '<p class="muted">No se encontraron tickers con ese filtro.</p>';
    return;
  }
  const tiles = state.filtered.map((item) => `
    <article class="stock-tile">
      <div class="stock-symbol">
        <strong>${item.symbol}</strong>
        <span class="stock-tag">${item.exchange ?? 'N/D'}</span>
      </div>
      <div class="stock-meta">${item.name ?? 'Sin nombre'}</div>
      <div class="stock-meta">Moneda: ${item.currency ?? 'N/D'}</div>
      <div class="stock-meta">País: ${item.country ?? 'N/D'} • MIC: ${item.mic_code ?? 'N/D'}</div>
      <div class="stock-meta">Tipo: ${item.type ?? 'N/D'}</div>
    </article>
  `);
  container.innerHTML = tiles.join('');
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
    (item.country ?? '').toLowerCase().includes(needle) ||
    (item.exchange ?? '').toLowerCase().includes(needle)
  );
};

const applyFilter = () => {
  state.filtered = state.stocks.filter((item) =>
    matchesCategory(item, state.category) && matchesSearch(item, state.searchTerm)
  );
  renderStocks();
};

const fetchStocks = async () => {
  state.loading = true;
  state.error = '';
  renderStocks();
  try {
    const response = await getJson('/stocks');
    state.stocks = Array.isArray(response?.data) ? response.data : [];
    applyFilter();
  } catch (error) {
    state.error = error?.error?.message ?? 'No se pudo obtener el listado de tickers';
    state.stocks = [];
    state.filtered = [];
  } finally {
    state.loading = false;
    renderStocks();
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
  } catch {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
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
  fetchStocks();

  const filterInput = document.getElementById('filter-input');
  const categoryFilter = document.getElementById('category-filter');
  filterInput?.addEventListener('input', (event) => {
    state.searchTerm = event.target.value.trim();
    applyFilter();
  });
  categoryFilter?.addEventListener('change', (event) => {
    state.category = event.target.value;
    applyFilter();
  });
};

document.addEventListener('DOMContentLoaded', init);
