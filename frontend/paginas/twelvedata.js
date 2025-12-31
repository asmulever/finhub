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
  console.info(`[twelvedata] Error en ${context}`, error);
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
  ['td-quote','td-batch','td-stocks','td-usage','td-price','td-ts','td-exrate','td-conv','td-market','td-cryptoex','td-insttype','td-search','td-forex','td-cryptos','td-earliest','td-ti'].forEach((prefix) => {
    setError(`${prefix}-error`, 'Acceso solo admin');
  });
  return false;
};

const fetchQuote = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-quote-symbol')?.value.trim().toUpperCase();
  setError('td-quote-error', '');
  if (!symbol) return setError('td-quote-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/quote?symbol=${encodeURIComponent(symbol)}`));
    setOutput('td-quote-output', resp?.data ?? resp);
  } catch (error) {
    logError('quote', error);
    setError('td-quote-error', error?.error?.message ?? 'Error al consultar quote');
  }
};

const fetchBatch = async () => {
  if (!guardAdmin()) return;
  const raw = document.getElementById('td-batch-symbols')?.value ?? '';
  const symbols = raw.split(',').map((s) => s.trim().toUpperCase()).filter(Boolean);
  setError('td-batch-error', '');
  if (!symbols.length) return setError('td-batch-error', 'Ingresa símbolos separados por coma');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/quotes?symbols=${encodeURIComponent(symbols.join(','))}`));
    setOutput('td-batch-output', resp?.data ?? resp);
  } catch (error) {
    logError('batch', error);
    setError('td-batch-error', error?.error?.message ?? 'Error al consultar batch');
  }
};

const fetchStocks = async () => {
  if (!guardAdmin()) return;
  const exchange = document.getElementById('td-stocks-exchange')?.value.trim().toUpperCase() || 'XBUE';
  setError('td-stocks-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/stocks?exchange=${encodeURIComponent(exchange)}`));
    setOutput('td-stocks-output', resp?.data ?? resp);
  } catch (error) {
    logError('stocks', error);
    setError('td-stocks-error', error?.error?.message ?? 'Error al listar stocks');
  }
};

const fetchUsage = async () => {
  if (!guardAdmin()) return;
  setError('td-usage-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/usage'));
    setOutput('td-usage-output', resp?.data ?? resp);
  } catch (error) {
    logError('usage', error);
    setError('td-usage-error', error?.error?.message ?? 'Error al consultar uso');
  }
};

const fetchPrice = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-price-symbol')?.value.trim().toUpperCase();
  setError('td-price-error', '');
  if (!symbol) return setError('td-price-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/price?symbol=${encodeURIComponent(symbol)}`));
    setOutput('td-price-output', resp?.data ?? resp);
  } catch (error) {
    logError('price', error);
    setError('td-price-error', error?.error?.message ?? 'Error en price');
  }
};

const fetchTimeSeries = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-ts-symbol')?.value.trim().toUpperCase();
  const interval = document.getElementById('td-ts-interval')?.value || '1day';
  const size = document.getElementById('td-ts-size')?.value || '30';
  setError('td-ts-error', '');
  if (!symbol) return setError('td-ts-error', 'Ingresa símbolo');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/time_series?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&outputsize=${encodeURIComponent(size)}`));
    setOutput('td-ts-output', resp?.data ?? resp);
  } catch (error) {
    logError('time_series', error);
    setError('td-ts-error', error?.error?.message ?? 'Error en time series');
  }
};

const fetchExchangeRate = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-exrate-symbol')?.value.trim().toUpperCase();
  setError('td-exrate-error', '');
  if (!symbol) return setError('td-exrate-error', 'Ingresa par (ej. EUR/USD)');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/exchange_rate?symbol=${encodeURIComponent(symbol)}`));
    setOutput('td-exrate-output', resp?.data ?? resp);
  } catch (error) {
    logError('exchange_rate', error);
    setError('td-exrate-error', error?.error?.message ?? 'Error en exchange_rate');
  }
};

const fetchConversion = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-conv-symbol')?.value.trim().toUpperCase();
  const amount = parseFloat(document.getElementById('td-conv-amount')?.value || '0');
  setError('td-conv-error', '');
  if (!symbol) return setError('td-conv-error', 'Ingresa par (ej. EUR/USD)');
  if (!(amount > 0)) return setError('td-conv-error', 'Ingresa monto > 0');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/currency_conversion?symbol=${encodeURIComponent(symbol)}&amount=${encodeURIComponent(String(amount))}`));
    setOutput('td-conv-output', resp?.data ?? resp);
  } catch (error) {
    logError('currency_conversion', error);
    setError('td-conv-error', error?.error?.message ?? 'Error en currency_conversion');
  }
};

const fetchMarketState = async () => {
  if (!guardAdmin()) return;
  setError('td-market-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/market_state'));
    setOutput('td-market-output', resp?.data ?? resp);
  } catch (error) {
    logError('market_state', error);
    setError('td-market-error', error?.error?.message ?? 'Error en market_state');
  }
};

const fetchCryptoExchanges = async () => {
  if (!guardAdmin()) return;
  setError('td-cryptoex-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/cryptocurrency_exchanges'));
    setOutput('td-cryptoex-output', resp?.data ?? resp);
  } catch (error) {
    logError('crypto_exchanges', error);
    setError('td-cryptoex-error', error?.error?.message ?? 'Error en crypto exchanges');
  }
};

const fetchInstrumentTypes = async () => {
  if (!guardAdmin()) return;
  setError('td-insttype-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/instrument_type'));
    setOutput('td-insttype-output', resp?.data ?? resp);
  } catch (error) {
    logError('instrument_type', error);
    setError('td-insttype-error', error?.error?.message ?? 'Error en instrument type');
  }
};

const fetchSymbolSearch = async () => {
  if (!guardAdmin()) return;
  const keywords = document.getElementById('td-search-keywords')?.value.trim();
  setError('td-search-error', '');
  if (!keywords) return setError('td-search-error', 'Ingresa texto a buscar');
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/symbol_search?symbol=${encodeURIComponent(keywords)}`));
    setOutput('td-search-output', resp?.data ?? resp);
  } catch (error) {
    logError('symbol_search', error);
    setError('td-search-error', error?.error?.message ?? 'Error en symbol_search');
  }
};

const fetchForexPairs = async () => {
  if (!guardAdmin()) return;
  setError('td-forex-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/forex_pairs'));
    setOutput('td-forex-output', resp?.data ?? resp);
  } catch (error) {
    logError('forex_pairs', error);
    setError('td-forex-error', error?.error?.message ?? 'Error en forex_pairs');
  }
};

const fetchCryptocurrencies = async () => {
  if (!guardAdmin()) return;
  setError('td-cryptos-error', '');
  try {
    const resp = await overlay.withLoader(() => getJson('/twelvedata/cryptocurrencies'));
    setOutput('td-cryptos-output', resp?.data ?? resp);
  } catch (error) {
    logError('cryptocurrencies', error);
    setError('td-cryptos-error', error?.error?.message ?? 'Error en cryptocurrencies');
  }
};

const fetchEarliestTimestamp = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('td-earliest-symbol')?.value.trim().toUpperCase();
  const exchange = document.getElementById('td-earliest-exchange')?.value || '';
  const interval = document.getElementById('td-earliest-interval')?.value || '1day';
  setError('td-earliest-error', '');
  if (!symbol) return setError('td-earliest-error', 'Ingresa símbolo');
  const qs = new URLSearchParams({ symbol, interval });
  if (exchange) qs.set('exchange', exchange);
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/earliest_timestamp?${qs.toString()}`));
    setOutput('td-earliest-output', resp?.data ?? resp);
  } catch (error) {
    logError('earliest_timestamp', error);
    setError('td-earliest-error', error?.error?.message ?? 'Error en earliest_timestamp');
  }
};

const fetchTechnicalIndicator = async () => {
  if (!guardAdmin()) return;
  const func = document.getElementById('td-ti-function')?.value.trim();
  const symbol = document.getElementById('td-ti-symbol')?.value.trim().toUpperCase();
  const interval = document.getElementById('td-ti-interval')?.value || '1day';
  const timePeriod = document.getElementById('td-ti-period')?.value || '';
  const seriesType = document.getElementById('td-ti-series')?.value || '';
  setError('td-ti-error', '');
  if (!func || !symbol) return setError('td-ti-error', 'Ingresa function y symbol');
  const params = new URLSearchParams({ function: func, symbol, interval });
  if (timePeriod) params.set('time_period', timePeriod);
  if (seriesType) params.set('series_type', seriesType);
  try {
    const resp = await overlay.withLoader(() => getJson(`/twelvedata/technical_indicator?${params.toString()}`));
    setOutput('td-ti-output', resp?.data ?? resp);
  } catch (error) {
    logError('technical_indicator', error);
    setError('td-ti-error', error?.error?.message ?? 'Error en technical_indicator');
  }
};

const bindUi = () => {
  document.getElementById('td-btn-quote')?.addEventListener('click', fetchQuote);
  document.getElementById('td-btn-batch')?.addEventListener('click', fetchBatch);
  document.getElementById('td-btn-stocks')?.addEventListener('click', fetchStocks);
  document.getElementById('td-btn-usage')?.addEventListener('click', fetchUsage);
  document.getElementById('td-btn-price')?.addEventListener('click', fetchPrice);
  document.getElementById('td-btn-ts')?.addEventListener('click', fetchTimeSeries);
  document.getElementById('td-btn-exrate')?.addEventListener('click', fetchExchangeRate);
  document.getElementById('td-btn-conv')?.addEventListener('click', fetchConversion);
  document.getElementById('td-btn-market')?.addEventListener('click', fetchMarketState);
  document.getElementById('td-btn-cryptoex')?.addEventListener('click', fetchCryptoExchanges);
  document.getElementById('td-btn-insttype')?.addEventListener('click', fetchInstrumentTypes);
  document.getElementById('td-btn-search')?.addEventListener('click', fetchSymbolSearch);
  document.getElementById('td-btn-forex')?.addEventListener('click', fetchForexPairs);
  document.getElementById('td-btn-cryptos')?.addEventListener('click', fetchCryptocurrencies);
  document.getElementById('td-btn-earliest')?.addEventListener('click', fetchEarliestTimestamp);
  document.getElementById('td-btn-ti')?.addEventListener('click', fetchTechnicalIndicator);
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
  await loadExchangeOptions();
  bindUi();
});
