import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setToolbarUserName } from '../components/toolbar.js';

const state = {
  stocks: [],
  filtered: [],
  loading: false,
  error: '',
  profile: null,
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
    </article>
  `);
  container.innerHTML = tiles.join('');
};

const applyFilter = (term) => {
  if (!term) {
    state.filtered = state.stocks;
    renderStocks();
    return;
  }
  const needle = term.toLowerCase();
  state.filtered = state.stocks.filter((item) =>
    (item.symbol ?? '').toLowerCase().includes(needle) ||
    (item.name ?? '').toLowerCase().includes(needle) ||
    (item.country ?? '').toLowerCase().includes(needle)
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
    state.filtered = state.stocks;
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
  } catch {
    state.profile = null;
    setToolbarUserName('');
  }
};

const init = () => {
  renderToolbar();
  bindToolbarNavigation();
  highlightToolbar();
  bindUserMenu({
    onLogout: handleLogout,
    onAbm: () => {
      window.location.href = '/Frontend/Dashboard.html';
    },
  });
  setToolbarUserName('');
  loadProfile();
  fetchStocks();

  const filterInput = document.getElementById('filter-input');
  filterInput?.addEventListener('input', (event) => {
    applyFilter(event.target.value.trim());
  });
};

document.addEventListener('DOMContentLoaded', init);
