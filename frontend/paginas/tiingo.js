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

const guardAdmin = () => {
  if (requireAdmin()) return true;
  document.querySelectorAll('button').forEach((b) => { b.disabled = true; });
  document.querySelectorAll('.error').forEach((el) => { el.textContent = 'Acceso solo admin'; });
  return false;
};

const loadProfile = async () => {
  try {
    state.profile = await getJson('/me');
  } catch {
    state.profile = authStore.getProfile();
  }
  setToolbarUserName(state.profile?.email ?? '');
  setAdminMenuVisibility(state.profile);
};

const buildParams = (params) => {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== null && value !== undefined && `${value}`.trim() !== '') {
      search.set(key, value);
    }
  });
  const qs = search.toString();
  return qs ? `?${qs}` : '';
};

const callEndpoint = async (path, outputId, errorId) => {
  if (!guardAdmin()) return;
  setError(errorId, '');
  try {
    const resp = await overlay.withLoader(() => getJson(path));
    setOutput(outputId, resp?.data ?? resp);
  } catch (error) {
    setError(errorId, error?.error?.message ?? 'Error al consultar');
  }
};

const splitTickers = (value) => (value || '')
  .split(',')
  .map((s) => s.trim().toUpperCase())
  .filter((s) => s !== '');

const fetchIexTops = async () => {
  const tickers = splitTickers(document.getElementById('tiingo-iex-tickers')?.value);
  if (!tickers.length) {
    return setError('tiingo-iex-tops-error', 'Ingresa tickers');
  }
  const params = buildParams({ tickers: tickers.join(',') });
  await callEndpoint(`/tiingo/iex/tops${params}`, 'tiingo-iex-tops-output', 'tiingo-iex-tops-error');
};

const fetchIexLast = async () => {
  const tickers = splitTickers(document.getElementById('tiingo-iex-last')?.value);
  if (!tickers.length) {
    return setError('tiingo-iex-last-error', 'Ingresa tickers');
  }
  const params = buildParams({ tickers: tickers.join(',') });
  await callEndpoint(`/tiingo/iex/last${params}`, 'tiingo-iex-last-output', 'tiingo-iex-last-error');
};

const fetchDailyPrices = async () => {
  const symbol = document.getElementById('tiingo-daily-symbol')?.value.trim().toUpperCase();
  if (!symbol) {
    return setError('tiingo-daily-error', 'Ingresa símbolo');
  }
  const params = buildParams({
    symbol,
    startDate: document.getElementById('tiingo-daily-start')?.value,
    endDate: document.getElementById('tiingo-daily-end')?.value,
    resampleFreq: document.getElementById('tiingo-daily-freq')?.value.trim(),
  });
  await callEndpoint(`/tiingo/daily/prices${params}`, 'tiingo-daily-output', 'tiingo-daily-error');
};

const fetchDailyMeta = async () => {
  const symbol = document.getElementById('tiingo-meta-symbol')?.value.trim().toUpperCase();
  if (!symbol) {
    return setError('tiingo-meta-error', 'Ingresa símbolo');
  }
  await callEndpoint(`/tiingo/daily/meta?symbol=${encodeURIComponent(symbol)}`, 'tiingo-meta-output', 'tiingo-meta-error');
};

const fetchCryptoPrices = async () => {
  const tickers = splitTickers(document.getElementById('tiingo-crypto-tickers')?.value.toLowerCase());
  if (!tickers.length) {
    return setError('tiingo-crypto-error', 'Ingresa tickers');
  }
  const params = buildParams({
    tickers: tickers.join(','),
    startDate: document.getElementById('tiingo-crypto-start')?.value,
    endDate: document.getElementById('tiingo-crypto-end')?.value,
    resampleFreq: document.getElementById('tiingo-crypto-freq')?.value.trim(),
  });
  await callEndpoint(`/tiingo/crypto/prices${params}`, 'tiingo-crypto-output', 'tiingo-crypto-error');
};

const fetchFxPrices = async () => {
  const tickers = splitTickers(document.getElementById('tiingo-fx-tickers')?.value.toUpperCase());
  if (!tickers.length) {
    return setError('tiingo-fx-error', 'Ingresa pares FX');
  }
  const params = buildParams({
    tickers: tickers.join(','),
    startDate: document.getElementById('tiingo-fx-start')?.value,
    endDate: document.getElementById('tiingo-fx-end')?.value,
    resampleFreq: document.getElementById('tiingo-fx-freq')?.value.trim(),
  });
  await callEndpoint(`/tiingo/fx/prices${params}`, 'tiingo-fx-output', 'tiingo-fx-error');
};

const fetchSearch = async () => {
  const query = document.getElementById('tiingo-search-query')?.value.trim();
  if (!query) {
    return setError('tiingo-search-error', 'Ingresa texto');
  }
  await callEndpoint(`/tiingo/search?query=${encodeURIComponent(query)}`, 'tiingo-search-output', 'tiingo-search-error');
};

const fetchNews = async () => {
  const tickers = splitTickers(document.getElementById('tiingo-news-tickers')?.value);
  if (!tickers.length) {
    return setError('tiingo-news-error', 'Ingresa tickers');
  }
  const params = buildParams({
    tickers: tickers.join(','),
    startDate: document.getElementById('tiingo-news-start')?.value,
    endDate: document.getElementById('tiingo-news-end')?.value,
    limit: document.getElementById('tiingo-news-limit')?.value || undefined,
  });
  await callEndpoint(`/tiingo/news${params}`, 'tiingo-news-output', 'tiingo-news-error');
};

const bindUi = () => {
  document.getElementById('tiingo-btn-iex-tops')?.addEventListener('click', fetchIexTops);
  document.getElementById('tiingo-btn-iex-last')?.addEventListener('click', fetchIexLast);
  document.getElementById('tiingo-btn-daily')?.addEventListener('click', fetchDailyPrices);
  document.getElementById('tiingo-btn-meta')?.addEventListener('click', fetchDailyMeta);
  document.getElementById('tiingo-btn-crypto')?.addEventListener('click', fetchCryptoPrices);
  document.getElementById('tiingo-btn-fx')?.addEventListener('click', fetchFxPrices);
  document.getElementById('tiingo-btn-search')?.addEventListener('click', fetchSearch);
  document.getElementById('tiingo-btn-news')?.addEventListener('click', fetchNews);
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
