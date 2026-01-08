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

const fetchTickers = async () => {
  const search = document.getElementById('poly-ticker-search')?.value.trim();
  const market = document.getElementById('poly-ticker-market')?.value.trim();
  const locale = document.getElementById('poly-ticker-locale')?.value.trim();
  const limit = parseInt(document.getElementById('poly-ticker-limit')?.value || '0', 10);
  const params = buildParams({
    search,
    market,
    locale,
    limit: Number.isNaN(limit) || limit <= 0 ? undefined : Math.min(limit, 1000),
  });
  await callEndpoint(`/polygon/tickers${params}`, 'poly-tickers-output', 'poly-tickers-error');
};

const fetchDetails = async () => {
  const symbol = document.getElementById('poly-details-symbol')?.value.trim().toUpperCase();
  if (!symbol) {
    return setError('poly-details-error', 'Ingresa símbolo');
  }
  await callEndpoint(`/polygon/ticker-details?symbol=${encodeURIComponent(symbol)}`, 'poly-details-output', 'poly-details-error');
};

const fetchExchanges = async () => {
  const asset = document.getElementById('poly-exchanges-asset')?.value.trim();
  const locale = document.getElementById('poly-exchanges-locale')?.value.trim();
  const params = buildParams({
    asset_class: asset,
    locale,
  });
  await callEndpoint(`/polygon/exchanges${params}`, 'poly-details-output', 'poly-details-error');
};

const fetchMarketStatus = async () => {
  await callEndpoint('/polygon/market-status', 'poly-details-output', 'poly-details-error');
};

const fetchLastQuote = async () => {
  const symbol = document.getElementById('poly-quote-symbol')?.value.trim().toUpperCase();
  if (!symbol) {
    return setError('poly-quote-error', 'Ingresa símbolo');
  }
  await callEndpoint(`/polygon/last-quote?symbol=${encodeURIComponent(symbol)}`, 'poly-quote-output', 'poly-quote-error');
};

const fetchLastTrade = async () => {
  const symbol = document.getElementById('poly-quote-symbol')?.value.trim().toUpperCase();
  if (!symbol) {
    return setError('poly-quote-error', 'Ingresa símbolo');
  }
  await callEndpoint(`/polygon/last-trade?symbol=${encodeURIComponent(symbol)}`, 'poly-quote-output', 'poly-quote-error');
};

const fetchSnapshot = async () => {
  const symbol = document.getElementById('poly-quote-symbol')?.value.trim().toUpperCase();
  const market = document.getElementById('poly-snapshot-market')?.value.trim();
  const locale = document.getElementById('poly-snapshot-locale')?.value.trim();
  if (!symbol) {
    return setError('poly-quote-error', 'Ingresa símbolo');
  }
  const params = buildParams({
    symbol,
    market,
    locale,
  });
  await callEndpoint(`/polygon/snapshot${params}`, 'poly-quote-output', 'poly-quote-error');
};

const fetchPrevClose = async () => {
  const symbol = document.getElementById('poly-prev-symbol')?.value.trim().toUpperCase();
  const adjusted = document.getElementById('poly-prev-adjusted')?.checked !== false;
  if (!symbol) {
    return setError('poly-prev-error', 'Ingresa símbolo');
  }
  const params = buildParams({ symbol, adjusted: adjusted ? '1' : '0' });
  await callEndpoint(`/polygon/previous-close${params}`, 'poly-prev-output', 'poly-prev-error');
};

const fetchOpenClose = async () => {
  const symbol = document.getElementById('poly-oc-symbol')?.value.trim().toUpperCase();
  const date = document.getElementById('poly-oc-date')?.value;
  const adjusted = document.getElementById('poly-oc-adjusted')?.checked !== false;
  if (!symbol || !date) {
    return setError('poly-prev-error', 'Ingresa símbolo y fecha');
  }
  const params = buildParams({ symbol, date, adjusted: adjusted ? '1' : '0' });
  await callEndpoint(`/polygon/daily-open-close${params}`, 'poly-prev-output', 'poly-prev-error');
};

const fetchAggregates = async () => {
  const symbol = document.getElementById('poly-agg-symbol')?.value.trim().toUpperCase();
  const multiplier = parseInt(document.getElementById('poly-agg-multiplier')?.value || '1', 10);
  const timespan = document.getElementById('poly-agg-timespan')?.value || 'day';
  const from = document.getElementById('poly-agg-from')?.value;
  const to = document.getElementById('poly-agg-to')?.value;
  const adjusted = document.getElementById('poly-agg-adjusted')?.checked !== false;
  if (!symbol || !from || !to) {
    return setError('poly-agg-error', 'Ingresa símbolo, desde y hasta');
  }
  const params = buildParams({
    symbol,
    multiplier: Number.isNaN(multiplier) ? 1 : multiplier,
    timespan,
    from,
    to,
    adjusted: adjusted ? '1' : '0',
  });
  await callEndpoint(`/polygon/aggregates${params}`, 'poly-agg-output', 'poly-agg-error');
};

const fetchGrouped = async () => {
  const date = document.getElementById('poly-group-date')?.value;
  const market = document.getElementById('poly-group-market')?.value.trim();
  const locale = document.getElementById('poly-group-locale')?.value.trim();
  const adjusted = document.getElementById('poly-group-adjusted')?.checked !== false;
  if (!date) {
    return setError('poly-group-error', 'Ingresa fecha');
  }
  const params = buildParams({
    date,
    market,
    locale,
    adjusted: adjusted ? '1' : '0',
  });
  await callEndpoint(`/polygon/grouped-daily${params}`, 'poly-group-output', 'poly-group-error');
};

const fetchNews = async () => {
  const symbol = document.getElementById('poly-news-symbol')?.value.trim().toUpperCase();
  const limit = parseInt(document.getElementById('poly-news-limit')?.value || '10', 10);
  if (!symbol) {
    return setError('poly-news-error', 'Ingresa símbolo');
  }
  const params = buildParams({ symbol, limit: Number.isNaN(limit) ? 10 : limit });
  await callEndpoint(`/polygon/news${params}`, 'poly-news-output', 'poly-news-error');
};

const fetchDividends = async () => {
  const symbol = document.getElementById('poly-div-symbol')?.value.trim().toUpperCase();
  const limit = parseInt(document.getElementById('poly-div-limit')?.value || '50', 10);
  if (!symbol) {
    return setError('poly-news-error', 'Ingresa símbolo');
  }
  const params = buildParams({ symbol, limit: Number.isNaN(limit) ? 50 : limit });
  await callEndpoint(`/polygon/dividends${params}`, 'poly-news-output', 'poly-news-error');
};

const fetchSplits = async () => {
  const symbol = document.getElementById('poly-split-symbol')?.value.trim().toUpperCase();
  const limit = parseInt(document.getElementById('poly-split-limit')?.value || '50', 10);
  if (!symbol) {
    return setError('poly-news-error', 'Ingresa símbolo');
  }
  const params = buildParams({ symbol, limit: Number.isNaN(limit) ? 50 : limit });
  await callEndpoint(`/polygon/splits${params}`, 'poly-news-output', 'poly-news-error');
};

const bindUi = () => {
  document.getElementById('poly-btn-tickers')?.addEventListener('click', fetchTickers);
  document.getElementById('poly-btn-details')?.addEventListener('click', fetchDetails);
  document.getElementById('poly-btn-exchanges')?.addEventListener('click', fetchExchanges);
  document.getElementById('poly-btn-status')?.addEventListener('click', fetchMarketStatus);
  document.getElementById('poly-btn-quote')?.addEventListener('click', fetchLastQuote);
  document.getElementById('poly-btn-trade')?.addEventListener('click', fetchLastTrade);
  document.getElementById('poly-btn-snapshot')?.addEventListener('click', fetchSnapshot);
  document.getElementById('poly-btn-prev')?.addEventListener('click', fetchPrevClose);
  document.getElementById('poly-btn-oc')?.addEventListener('click', fetchOpenClose);
  document.getElementById('poly-btn-agg')?.addEventListener('click', fetchAggregates);
  document.getElementById('poly-btn-group')?.addEventListener('click', fetchGrouped);
  document.getElementById('poly-btn-news')?.addEventListener('click', fetchNews);
  document.getElementById('poly-btn-divs')?.addEventListener('click', fetchDividends);
  document.getElementById('poly-btn-splits')?.addEventListener('click', fetchSplits);
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
