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

const setText = (id, text) => {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
};

const setOutput = (id, payload) => {
  const el = document.getElementById(id);
  if (el) el.textContent = JSON.stringify(payload ?? {}, null, 2);
};

const requireAdmin = () => String(state.profile?.role ?? '').toLowerCase() === 'admin';

const logError = (context, error) => {
  console.info(`[analisis] Error en ${context}`, error);
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
  setError('an-error', 'Acceso solo admin');
  return false;
};

const loadExchanges = async () => {
  const select = document.getElementById('an-exchange');
  if (!select) return;
  try {
    const resp = await getJson('/eodhd/exchanges-list');
    const list = Array.isArray(resp?.data) ? resp.data : [];
    const options = ['<option value="">Exchange (opcional)</option>'].concat(
      list.map((ex) => {
        const code = ex.Code ?? ex.code ?? '';
        const name = ex.Name ?? ex.name ?? '';
        if (!code) return '';
        return `<option value="${code}">${code}${name ? ' - ' + name : ''}</option>`;
      }).filter(Boolean)
    );
    select.innerHTML = options.join('');
  } catch (error) {
    logError('exchanges', error);
  }
};

const normalizeTimeSeries = (data) => {
  const series = data?.values ?? data?.data ?? data;
  if (!Array.isArray(series)) return [];
  const parsed = series.map((row) => {
    const close = parseFloat(row.close ?? row.adjusted_close ?? row.price ?? row.value ?? row.close_price ?? NaN);
    const date = row.datetime ?? row.date ?? row.timestamp ?? row.Time ?? null;
    return (!Number.isFinite(close) || !date) ? null : { date, close };
  }).filter(Boolean);
  return parsed;
};

const computeSma = (series, window) => {
  if (!Array.isArray(series) || series.length < window) return null;
  const subset = series.slice(0, window);
  const sum = subset.reduce((acc, p) => acc + p.close, 0);
  return sum / window;
};

const pctChange = (from, to) => (from === 0 ? 0 : ((to - from) / Math.abs(from)) * 100);

const buildRecommendation = ({ rsi, smaShort, smaLong }) => {
  const rec = { short: 'Neutral', mid: 'Neutral', long: 'Neutral' };
  if (rsi !== null) {
    if (rsi < 30) rec.short = 'Comprar (sobrevendido)';
    else if (rsi > 70) rec.short = 'Vender (sobrecomprado)';
  }
  if (smaShort !== null && smaLong !== null) {
    if (smaShort > smaLong * 1.01) rec.mid = 'Comprar (cruce alcista)';
    else if (smaShort < smaLong * 0.99) rec.mid = 'Vender (cruce bajista)';
  }
  if (smaLong !== null && smaShort !== null) {
    rec.long = smaLong <= smaShort ? 'Mantener/Largo plazo' : 'Revisar tendencia';
  }
  return rec;
};

const fetchRsi = async (symbol, interval) => {
  try {
    const params = new URLSearchParams({ function: 'rsi', symbol, interval, time_period: '14' });
    const resp = await getJson(`/twelvedata/technical_indicator?${params.toString()}`);
    const values = resp?.data?.values ?? resp?.values ?? [];
    if (Array.isArray(values) && values.length) {
      const val = parseFloat(values[0]?.rsi ?? values[0]?.value ?? NaN);
      return Number.isFinite(val) ? val : null;
    }
  } catch (error) {
    logError('rsi', error);
  }
  return null;
};

const fetchExchangeRateArs = async (currency) => {
  if (!currency || currency.toUpperCase() === 'ARS') return 1;
  try {
    const pair = `${currency.toUpperCase()}/ARS`;
    const resp = await getJson(`/twelvedata/exchange_rate?symbol=${encodeURIComponent(pair)}`);
    const rate = parseFloat(resp?.data?.rate ?? resp?.rate ?? NaN);
    return Number.isFinite(rate) ? rate : 1;
  } catch (error) {
    logError('exchange_rate', error);
    return 1;
  }
};

const fetchHistorical = async (provider, symbol, interval, outputsize) => {
  if (provider === 'twelvedata') {
    return overlay.withLoader(() => getJson(`/twelvedata/time_series?symbol=${encodeURIComponent(symbol)}&interval=${encodeURIComponent(interval)}&outputsize=${encodeURIComponent(outputsize)}`));
  }
  return overlay.withLoader(() => getJson(`/eodhd/eod?symbol=${encodeURIComponent(symbol)}`));
};

const analyze = async () => {
  if (!guardAdmin()) return;
  const symbolRaw = document.getElementById('an-symbol')?.value.trim();
  const provider = document.getElementById('an-provider')?.value || 'twelvedata';
  const interval = document.getElementById('an-interval')?.value || '1day';
  const outputsize = document.getElementById('an-outputsize')?.value || '365';
  const exchange = (document.getElementById('an-exchange')?.value || '').toUpperCase();
  const currencyInput = document.getElementById('an-currency')?.value.trim().toUpperCase();
  setError('an-error', '');
  if (!symbolRaw) return setError('an-error', 'Ingresa símbolo');
  const symbol = exchange && !symbolRaw.includes('.') ? `${symbolRaw}.${exchange}` : symbolRaw;

  try {
    const histResp = await fetchHistorical(provider, symbol, interval, outputsize);
    setOutput('an-hist-output', histResp?.data ?? histResp);
    const series = normalizeTimeSeries(histResp?.data ?? histResp);
    if (!series.length) throw new Error('Sin histórico disponible');

    const rsi = provider === 'twelvedata' ? await fetchRsi(symbol, interval) : null;
    const smaShort = computeSma(series, 10);
    const smaLong = computeSma(series, 50);
    const last = series[0]?.close ?? null;
    const change30 = series.length > 30 ? pctChange(series[30].close, series[0].close) : null;

    const rec = buildRecommendation({ rsi, smaShort, smaLong });
    setText('m-last', last !== null ? last.toFixed(2) : '-');
    setText('m-chg30', change30 !== null ? `${change30.toFixed(2)}%` : '-');
    setText('m-rsi', rsi !== null ? rsi.toFixed(2) : '-');
    setText('m-sma', (smaShort !== null && smaLong !== null) ? `${smaShort.toFixed(2)} / ${smaLong.toFixed(2)}` : '-');
    setText('m-rec-short', rec.short);
    setText('m-rec-mid', rec.mid);
    setText('m-rec-long', rec.long);

    const currency = currencyInput || histResp?.data?.meta?.currency || 'USD';
    const rate = await fetchExchangeRateArs(currency);
    const lastArs = last !== null ? last * rate : null;
    setText('m-ars', lastArs !== null ? `${lastArs.toFixed(2)} ARS` : '-');
  } catch (error) {
    logError('analizar', error);
    setError('an-error', error?.error?.message ?? error?.message ?? 'Error al analizar');
    setOutput('an-hist-output', {});
    setText('m-last', '-');
    setText('m-chg30', '-');
    setText('m-rsi', '-');
    setText('m-sma', '-');
    setText('m-rec-short', '-');
    setText('m-rec-mid', '-');
    setText('m-rec-long', '-');
    setText('m-ars', '-');
  }
};

const bindUi = () => {
  document.getElementById('btn-analizar')?.addEventListener('click', analyze);
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
  await loadExchanges();
  bindUi();
});
