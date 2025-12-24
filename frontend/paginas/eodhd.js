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
  selectedSymbol: '',
  symbolsLoadedExchange: '',
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

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
  const filtered = state.selectedSymbol
    ? state.exchangeSymbols.filter((s) => (s.Code ?? s.code ?? '') === state.selectedSymbol)
    : state.exchangeSymbols;
  if (!filtered.length) {
    container.innerHTML = '<p class="muted">No hay símbolos para el filtro seleccionado.</p>';
    return;
  }
  const rows = filtered.slice(0, 50).map((s) => `
    <tr>
      <td>${s.Code ?? s.code ?? ''}</td>
      <td>${s.Name ?? s.name ?? ''}</td>
      <td>${s.Exchange ?? s.exchange ?? ''}</td>
      <td>${s.Type ?? s.type ?? ''}</td>
    </tr>
  `).join('');
  container.innerHTML = `
    <p class="muted">Mostrando ${Math.min(50, filtered.length)} de ${filtered.length} símbolos.</p>
    <div style="max-height:320px; overflow:auto;">
      <table>
        <thead><tr><th>Code</th><th>Nombre</th><th>Exchange</th><th>Tipo</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
  `;
};

const fetchEod = async () => {
  const input = document.getElementById('symbol-input');
  const symbol = input?.value.trim();
  state.eod = null;
  state.eodError = '';
  renderEod();
  if (!symbol) {
    state.eodError = 'Ingresa un símbolo (ej: AAPL.US)';
    renderEod();
    return;
  }
  try {
    const data = await getJson(`/eodhd/eod?symbol=${encodeURIComponent(symbol)}`);
    state.eod = data;
  } catch (error) {
    state.eodError = error?.error?.message ?? 'No se pudo obtener el EOD';
  }
  renderEod();
};

const fetchExchangeSymbols = async () => {
  const input = document.getElementById('exchange-input');
  const exch = state.selectedExchange || input?.value.trim() || '';
  state.exchangeSymbols = [];
  state.exchangeError = '';
  state.selectedSymbol = '';
  renderExchange();
  if (!exch) {
    state.exchangeError = 'Selecciona un exchange o un símbolo.';
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
  const select = document.getElementById('symbol-select');
  if (!select) return;
  select.innerHTML = ['<option value="">seleccionar</option>'].concat(
    state.exchangeSymbols.map((s) => {
      const code = s.Code ?? s.code ?? '';
      const name = s.Name ?? s.name ?? '';
      return `<option value="${code}">${code} | ${name}</option>`;
    })
  ).join('');
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
    document.getElementById('eod-btn')?.setAttribute('disabled', 'disabled');
    document.getElementById('exchange-btn')?.setAttribute('disabled', 'disabled');
    document.getElementById('exchange-select')?.setAttribute('disabled', 'disabled');
    document.getElementById('symbol-select')?.setAttribute('disabled', 'disabled');
    return;
  }
  await fetchExchangesList();
  document.getElementById('eod-btn')?.addEventListener('click', fetchEod);
  document.getElementById('exchange-btn')?.addEventListener('click', fetchExchangeSymbols);
  document.getElementById('exchange-select')?.addEventListener('change', (event) => {
    const code = event.target.value;
    state.selectedExchange = code;
    state.selectedSymbol = '';
    const input = document.getElementById('exchange-input');
    if (input) input.value = code;
    const symbolSelect = document.getElementById('symbol-select');
    if (symbolSelect) symbolSelect.value = '';
    if (code) {
      fetchExchangeSymbols().then(renderSymbolSelect);
    } else {
      state.exchangeSymbols = [];
      renderSymbolSelect();
      state.exchangeError = 'Selecciona un exchange o un símbolo.';
      renderExchange();
    }
  });
  document.getElementById('symbol-select')?.addEventListener('change', (event) => {
    const sym = event.target.value;
    state.selectedSymbol = sym;
    state.selectedExchange = '';
    state.exchangeError = '';
    const exchSelect = document.getElementById('exchange-select');
    if (exchSelect) exchSelect.value = '';
    const input = document.getElementById('exchange-input');
    if (input) input.value = '';
    if (!sym) {
      state.exchangeError = 'Selecciona un exchange o un símbolo.';
    }
    renderExchange();
  });
  renderEod();
  renderExchange();
};

document.addEventListener('DOMContentLoaded', init);
