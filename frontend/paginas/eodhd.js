import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  eod: null,
  eodError: '',
  exchangeSymbols: [],
  exchangeError: '',
  exchangesList: [],
  selectedExchange: '',
  symbolsLoadedExchange: '',
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';
const cookieKey = (profile) => {
  const email = profile?.email ? String(profile.email).toLowerCase().replace(/[^a-z0-9._-]/g, '') : 'default';
  return `eodhd_exchange_${email}`;
};
const setCookie = (name, value) => {
  document.cookie = `${name}=${encodeURIComponent(value || '')}; path=/; expires=Fri, 31 Dec 9999 23:59:59 GMT`;
};
const getCookie = (name) => {
  const cookies = document.cookie ? document.cookie.split(';') : [];
  for (const raw of cookies) {
    const [k, ...rest] = raw.trim().split('=');
    if (k === name) {
      return decodeURIComponent(rest.join('='));
    }
  }
  return '';
};

const renderEod = () => {
  const container = document.getElementById('eod-result');
  if (!container) return;
  if (state.eodError) {
    container.innerHTML = `<p class="price-error">${state.eodError}</p>`;
    return;
  }
  if (!state.eod) {
    container.innerHTML = '<p class="muted">Ingresa un símbolo y consulta EOD.</p>';
    return;
  }
  const item = Array.isArray(state.eod.data) ? state.eod.data[0] : state.eod.data;
  if (!item) {
    container.innerHTML = '<p class="muted">Sin datos.</p>';
    return;
  }
  container.innerHTML = `
    <article class="tile">
      <div class="price-badge">${state.eod.symbol}</div>
      <strong>${item.code ?? state.eod.symbol}</strong>
      <div>Fecha: ${item.date ?? 'N/D'}</div>
      <div>Close: ${item.close ?? item.adjusted_close ?? 'N/D'}</div>
      <div>Open: ${item.open ?? 'N/D'} • High: ${item.high ?? 'N/D'} • Low: ${item.low ?? 'N/D'}</div>
      <div>Volumen: ${item.volume ?? 'N/D'}</div>
      <small class="muted">${item.exchange ?? ''}</small>
    </article>
  `;
};

const renderExchange = () => {
  const container = document.getElementById('exchange-result');
  if (!container) return;
  if (state.exchangeError) {
    container.innerHTML = `<p class="price-error">${state.exchangeError}</p>`;
    return;
  }
  if (!state.exchangeSymbols.length) {
    container.innerHTML = '<p class="muted">Sin resultados.</p>';
    return;
  }
  const rows = state.exchangeSymbols.slice(0, 50).map((s) => `
    <tr>
      <td data-symbol="${s.Code ?? s.code ?? ''}">${s.Code ?? s.code ?? ''}</td>
      <td>${s.Name ?? s.name ?? ''}</td>
      <td>${s.Exchange ?? s.exchange ?? ''}</td>
      <td>${s.Type ?? s.type ?? ''}</td>
    </tr>
  `).join('');
  container.innerHTML = `
    <p class="muted">Mostrando ${Math.min(50, state.exchangeSymbols.length)} de ${state.exchangeSymbols.length} símbolos (doble clic para EOD).</p>
    <div style="max-height:320px; overflow:auto;">
      <table id="symbols-table">
        <thead><tr><th>Code</th><th>Nombre</th><th>Exchange</th><th>Tipo</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
};

const fetchExchangeSymbols = async () => {
  const input = document.getElementById('exchange-input');
  const exch = state.selectedExchange || input?.value.trim() || '';
  state.exchangeSymbols = [];
  state.exchangeError = '';
  renderExchange();
  if (!exch) {
    state.exchangeError = 'Selecciona un exchange.';
    renderExchange();
    return;
  }
  try {
    const data = await getJson(`/eodhd/exchange-symbols?exchange=${encodeURIComponent(exch)}`);
    state.exchangeSymbols = Array.isArray(data?.data) ? data.data : [];
    state.symbolsLoadedExchange = exch;
  } catch (error) {
    state.exchangeError = error?.error?.message ?? 'No se pudo obtener la lista';
  }
  renderExchange();
};

const renderExchangeSelect = () => {
  const select = document.getElementById('exchange-select');
  if (!select) return;
  select.innerHTML = ['<option value="">seleccionar</option>'].concat(
    state.exchangesList.map((ex) => {
      const code = ex.Code ?? ex.code ?? '';
      const country = ex.Country ?? ex.country ?? '';
      const name = ex.Name ?? ex.name ?? '';
      return `<option value="${code}">${country ? country + ' - ' : ''}${code} | ${name}</option>`;
    })
  ).join('');
};

const renderSymbolSelect = () => {
  // Eliminado: ya no se usa selector de símbolos independiente
};

const fetchExchangesList = async () => {
  try {
    const data = await getJson('/eodhd/exchanges-list');
    state.exchangesList = Array.isArray(data?.data) ? data.data : [];
  } catch (error) {
    state.exchangesList = [];
    const container = document.getElementById('exchange-result');
    container?.insertAdjacentHTML('afterbegin', `<p class="price-error">${error?.error?.message ?? 'No se pudo obtener exchanges'}</p>`);
  }
  renderExchangeSelect();
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
    const profile = await getJson('/me');
    state.profile = profile;
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
  }
};

const init = async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
    profile: authStore.getProfile(),
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  if (!isAdminProfile(state.profile ?? authStore.getProfile())) {
    const eod = document.getElementById('eod-result');
    if (eod) eod.innerHTML = '<p class="price-error">Acceso restringido: solo Admin.</p>';
    document.getElementById('exchange-select')?.setAttribute('disabled', 'disabled');
    return;
  }
  await fetchExchangesList();
  // restaurar selección previa del usuario desde cookie
  const lastExchange = getCookie(cookieKey(state.profile ?? authStore.getProfile()));
  if (lastExchange) {
    state.selectedExchange = lastExchange;
    const sel = document.getElementById('exchange-select');
    const input = document.getElementById('exchange-input');
    if (sel) sel.value = lastExchange;
    if (input) input.value = lastExchange;
    await fetchExchangeSymbols();
  }
  document.getElementById('exchange-select')?.addEventListener('change', (event) => {
    const code = event.target.value;
    state.selectedExchange = code;
    setCookie(cookieKey(state.profile ?? authStore.getProfile()), code || '');
    const input = document.getElementById('exchange-input');
    if (input) input.value = code;
    if (code) {
      fetchExchangeSymbols();
    } else {
      state.exchangeSymbols = [];
      state.exchangeError = 'Selecciona un exchange.';
      renderExchange();
    }
  });
  document.getElementById('exchange-result')?.addEventListener('dblclick', (event) => {
    const cell = event.target.closest('[data-symbol]');
    const symbol = cell?.dataset?.symbol;
    if (!symbol) return;
    fetchEodWithSymbol(symbol);
  });
  renderEod();
  renderExchange();
};

// Helper to fetch EOD by symbol (used on double click)
const fetchEodWithSymbol = async (symbol) => {
  state.eod = null;
  state.eodError = '';
  renderEod();
  try {
    const data = await getJson(`/eodhd/eod?symbol=${encodeURIComponent(symbol)}`);
    state.eod = data;
  } catch (error) {
    state.eodError = error?.error?.message ?? 'No se pudo obtener el EOD';
  }
  renderEod();
};

document.addEventListener('DOMContentLoaded', init);
