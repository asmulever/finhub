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
  console.info(`[alphavantage] Error en ${context}`, error);
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
  ['quote','search','daily','fx-daily','sma','rsi','overview'].forEach((prefix) => {
    setError(`${prefix}-error`, 'Acceso solo admin');
  });
  return false;
};

const fetchQuote = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('quote-symbol')?.value.trim().toUpperCase();
  setError('quote-error', '');
  if (!symbol) return setError('quote-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/quote?symbol=${encodeURIComponent(symbol)}`));
    setOutput('quote-output', resp?.data ?? resp);
  } catch (error) {
    logError('quote', error);
    setError('quote-error', error?.error?.message ?? 'Error al consultar quote');
  }
};

const fetchSearch = async () => {
  if (!guardAdmin()) return;
  const kw = document.getElementById('search-keywords')?.value.trim();
  setError('search-error', '');
  if (!kw) return setError('search-error', 'Ingresa keywords');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/search?keywords=${encodeURIComponent(kw)}`));
    setOutput('search-output', resp?.data ?? resp);
  } catch (error) {
    logError('search', error);
    setError('search-error', error?.error?.message ?? 'Error al buscar');
  }
};

const fetchDaily = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('daily-symbol')?.value.trim().toUpperCase();
  const size = document.getElementById('daily-size')?.value || 'compact';
  setError('daily-error', '');
  if (!symbol) return setError('daily-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/daily?symbol=${encodeURIComponent(symbol)}&outputsize=${encodeURIComponent(size)}`));
    setOutput('daily-output', resp?.data ?? resp);
  } catch (error) {
    logError('daily', error);
    setError('daily-error', error?.error?.message ?? 'Error al consultar daily');
  }
};

const fetchFxDaily = async () => {
  if (!guardAdmin()) return;
  const from = document.getElementById('fxd-from')?.value.trim().toUpperCase();
  const to = document.getElementById('fxd-to')?.value.trim().toUpperCase();
  setError('fx-daily-error', '');
  if (!from || !to) return setError('fx-daily-error', 'Ingresa from/to');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/fx-daily?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`));
    setOutput('fx-daily-output', resp?.data ?? resp);
  } catch (error) {
    logError('fx-daily', error);
    setError('fx-daily-error', error?.error?.message ?? 'Error en FX daily');
  }
};

const fetchSma = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('sma-symbol')?.value.trim().toUpperCase();
  const interval = document.getElementById('sma-interval')?.value || 'daily';
  const period = parseInt(document.getElementById('sma-period')?.value || '20', 10);
  const series = document.getElementById('sma-series')?.value || 'close';
  setError('sma-error', '');
  if (!symbol) return setError('sma-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/sma?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&time_period=${encodeURIComponent(period)}&series_type=${encodeURIComponent(series)}`));
    setOutput('sma-output', resp?.data ?? resp);
  } catch (error) {
    logError('sma', error);
    setError('sma-error', error?.error?.message ?? 'Error en SMA');
  }
};

const fetchRsi = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('rsi-symbol')?.value.trim().toUpperCase();
  const interval = document.getElementById('rsi-interval')?.value || 'daily';
  const period = parseInt(document.getElementById('rsi-period')?.value || '14', 10);
  const series = document.getElementById('rsi-series')?.value || 'close';
  setError('rsi-error', '');
  if (!symbol) return setError('rsi-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/rsi?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&time_period=${encodeURIComponent(period)}&series_type=${encodeURIComponent(series)}`));
    setOutput('rsi-output', resp?.data ?? resp);
  } catch (error) {
    logError('rsi', error);
    setError('rsi-error', error?.error?.message ?? 'Error en RSI');
  }
};

const fetchOverview = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('overview-symbol')?.value.trim().toUpperCase();
  setError('overview-error', '');
  if (!symbol) return setError('overview-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/alphavantage/overview?symbol=${encodeURIComponent(symbol)}`));
    setOutput('overview-output', resp?.data ?? resp);
  } catch (error) {
    logError('overview', error);
    setError('overview-error', error?.error?.message ?? 'Error en overview');
  }
};

const bindUi = () => {
  document.getElementById('btn-quote')?.addEventListener('click', fetchQuote);
  document.getElementById('btn-search')?.addEventListener('click', fetchSearch);
  document.getElementById('btn-daily')?.addEventListener('click', fetchDaily);
  document.getElementById('btn-fx-daily')?.addEventListener('click', fetchFxDaily);
  document.getElementById('btn-sma')?.addEventListener('click', fetchSma);
  document.getElementById('btn-rsi')?.addEventListener('click', fetchRsi);
  document.getElementById('btn-overview')?.addEventListener('click', fetchOverview);
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
