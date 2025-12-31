import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const state = { profile: null };
const overlay = createLoadingOverlay();

const setError = (id, message) => {
  const el = document.getElementById(id);
  if (el) el.textContent = message || '';
};

const setOutput = (id, payload) => {
  const el = document.getElementById(id);
  if (el) el.textContent = JSON.stringify(payload ?? {}, null, 2);
};

const requireAdmin = () => String(state.profile?.role ?? '').toLowerCase() === 'admin';

const logError = (context, error) => {
  console.info(`[eodhd] Error en ${context}`, error);
};

const loadProfile = async () => {
  try {
    state.profile = await getJson('/me');
    setToolbarUserName(state.profile?.email ?? '');
    setAdminMenuVisibility(state.profile);
  } catch {
    state.profile = authStore.getProfile();
    setToolbarUserName(state.profile?.email ?? '');
    setAdminMenuVisibility(state.profile);
  }
};

const guardAdmin = () => {
  if (requireAdmin()) return true;
  document.querySelectorAll('button').forEach((b) => { b.disabled = true; });
  ['eod','search','ex-symbols','exchanges-list','user'].forEach((prefix) => {
    setError(`${prefix}-error`, 'Acceso solo admin');
  });
  return false;
};

const fetchEod = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('eod-symbol')?.value.trim().toUpperCase();
  setError('eod-error', '');
  if (!symbol) return setError('eod-error', 'Ingresa símbolo (ej. AAPL.US)');
  try {
    const resp = await overlay.withLoader(() => getJson(`/eodhd/eod?symbol=${encodeURIComponent(symbol)}`));
    setOutput('eod-output', resp?.data ?? resp);
  } catch (error) {
    logError('eod', error);
    setError('eod-error', error?.error?.message ?? 'Error en EOD');
  }
};

const fetchSearch = async () => {
  if (!guardAdmin()) return;
  const query = document.getElementById('search-query')?.value.trim();
  setError('search-error', '');
  if (!query) return setError('search-error', 'Ingresa texto a buscar');
  try {
    const resp = await overlay.withLoader(() => getJson(`/eodhd/search?q=${encodeURIComponent(query)}`));
    setOutput('search-output', resp?.data ?? resp);
  } catch (error) {
    logError('search', error);
    setError('search-error', error?.error?.message ?? 'Error en search');
  }
};

const fetchExchangeSymbols = async () => {
  if (!guardAdmin()) return;
  const exchange = document.getElementById('ex-symbols-exchange')?.value.trim().toUpperCase() || 'US';
  setError('ex-symbols-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson(`/eodhd/exchange-symbols?exchange=${encodeURIComponent(exchange)}`));
    setOutput('ex-symbols-output', resp?.data ?? resp);
  } catch (error) {
    logError('exchange-symbols', error);
    setError('ex-symbols-error', error?.error?.message ?? 'Error en exchange-symbols');
  }
};

const fetchExchangesList = async () => {
  if (!guardAdmin()) return;
  setError('exchanges-list-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/eodhd/exchanges-list'));
    setOutput('exchanges-list-output', resp?.data ?? resp);
  } catch (error) {
    logError('exchanges-list', error);
    setError('exchanges-list-error', error?.error?.message ?? 'Error en exchanges-list');
  }
};

const fetchUser = async () => {
  if (!guardAdmin()) return;
  setError('user-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/eodhd/user'));
    setOutput('user-output', resp?.data ?? resp);
  } catch (error) {
    logError('user', error);
    setError('user-error', error?.error?.message ?? 'Error en user');
  }
};

const bindUi = () => {
  document.getElementById('btn-eod')?.addEventListener('click', fetchEod);
  document.getElementById('btn-search')?.addEventListener('click', fetchSearch);
  document.getElementById('btn-exchange-symbols')?.addEventListener('click', fetchExchangeSymbols);
  document.getElementById('btn-exchanges-list')?.addEventListener('click', fetchExchangesList);
  document.getElementById('btn-user')?.addEventListener('click', fetchUser);
};

document.addEventListener('DOMContentLoaded', async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await getJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  bindUi();
});
