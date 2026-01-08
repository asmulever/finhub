import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const state = { profile: null, markets: [] };
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

const populateMarkets = (selectId, markets) => {
  const select = document.getElementById(selectId);
  if (!select) return;
  select.innerHTML = '<option value=\"\">Mercado</option>';
  markets.forEach((m) => {
    const opt = document.createElement('option');
    opt.value = m;
    opt.textContent = m.toUpperCase();
    select.appendChild(opt);
  });
};

const loadMarkets = async () => {
  if (!guardAdmin()) return;
  try {
    const resp = await overlay.withLoader(() => getJson('/stooq/markets'));
    const markets = Array.isArray(resp?.data) ? resp.data : [];
    state.markets = markets;
    populateMarkets('stooq-quotes-market', markets);
    populateMarkets('stooq-history-market', markets);
    setError('stooq-quotes-error', '');
  } catch (error) {
    setError('stooq-quotes-error', error?.error?.message ?? 'No se pudieron cargar mercados');
  }
};

const buildSymbolsWithMarket = (rawSymbols, market) => {
  const tokens = (rawSymbols || '')
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter((s) => s !== '');
  if (!market) return tokens;
  return tokens.map((t) => `${t}.${market.toLowerCase()}`);
};

const fetchQuotes = async () => {
  if (!guardAdmin()) return;
  const raw = document.getElementById('stooq-quotes-symbols')?.value;
  const market = document.getElementById('stooq-quotes-market')?.value;
  const symbols = buildSymbolsWithMarket(raw, market);
  if (!symbols.length) {
    return setError('stooq-quotes-error', 'Ingresa símbolos');
  }
  const params = new URLSearchParams({ symbols: symbols.join(',') });
  try {
    const resp = await overlay.withLoader(() => getJson(`/stooq/quotes?${params.toString()}`));
    setOutput('stooq-quotes-output', resp?.data ?? resp);
  } catch (error) {
    setError('stooq-quotes-error', error?.error?.message ?? 'Error al consultar quotes');
  }
};

const fetchHistory = async () => {
  if (!guardAdmin()) return;
  const symbol = document.getElementById('stooq-history-symbol')?.value.trim().toLowerCase();
  const market = document.getElementById('stooq-history-market')?.value;
  const interval = document.getElementById('stooq-history-interval')?.value || 'd';
  if (!symbol) {
    return setError('stooq-history-error', 'Ingresa símbolo');
  }
  const params = new URLSearchParams({
    symbol,
    market,
    interval,
  });
  try {
    const resp = await overlay.withLoader(() => getJson(`/stooq/history?${params.toString()}`));
    setOutput('stooq-history-output', resp?.data ?? resp);
  } catch (error) {
    setError('stooq-history-error', error?.error?.message ?? 'Error al consultar histórico');
  }
};

const bindUi = () => {
  document.getElementById('stooq-btn-markets')?.addEventListener('click', loadMarkets);
  document.getElementById('stooq-btn-quotes')?.addEventListener('click', fetchQuotes);
  document.getElementById('stooq-btn-history')?.addEventListener('click', fetchHistory);
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
  await loadMarkets();
});
