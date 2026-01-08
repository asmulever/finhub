import { getJson, postJson, deleteJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const state = {
  symbols: [],
  selectedSymbols: [],
  period: '1m',
  collecting: false,
  profile: null,
  catalog: [],
  filteredCatalog: [],
  selectedCatalog: null,
  snapshots: {},
  series: [],
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

const getLogArea = () => document.getElementById('ingestion-log');

const timestamp = () => new Date().toISOString();

const resetLog = (message = '') => {
  const log = getLogArea();
  if (!log) return;
  log.value = '';
  if (message) {
    log.value = `[${timestamp()}] ${message}`;
  }
};

const appendLog = (message, at = null) => {
  const log = getLogArea();
  if (!log) return;
  const ts = at ?? timestamp();
  const prefix = log.value === '' ? '' : '\n';
  log.value = `${log.value}${prefix}[${ts}] ${message}`;
  log.scrollTop = log.scrollHeight;
};

const formatStepLine = (step) => {
  if (!step || typeof step !== 'object') return '';
  const progress = step.progress ? ` (${step.progress.current}/${step.progress.total})` : '';
  const symbol = step.symbol ? `[${step.symbol}] ` : '';
  const stage = step.stage ? `${step.stage}: ` : '';
  const status = step.status ? `${String(step.status).toUpperCase()} ` : '';
  const message = step.message ?? '';
  return `${symbol}${stage}${status}${message}${progress}`.trim();
};

const renderStepLog = (steps = []) => {
  if (!Array.isArray(steps)) return;
  steps.forEach((step) => {
    const line = formatStepLine(step);
    if (line !== '') {
      appendLog(line, step.at ?? null);
    }
  });
};

const overlay = createLoadingOverlay();

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  return new Intl.NumberFormat('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const renderTabs = () => {
  const tabs = document.querySelectorAll('.tab-btn');
  tabs.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-tab');
      document.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach((p) => p.classList.remove('active'));
      btn.classList.add('active');
      const panel = document.getElementById(`tab-${tab}`);
      if (panel) panel.classList.add('active');
    });
  });
};

const renderCatalog = () => {
  const tbody = document.getElementById('catalog-body');
  if (!tbody) return;
  if (!state.filteredCatalog.length) {
    tbody.innerHTML = '<tr><td class="muted" colspan="8">Sin resultados</td></tr>';
    return;
  }
  tbody.innerHTML = state.filteredCatalog.map((row) => `
    <tr data-symbol="${row.symbol}">
      <td>${row.symbol}</td>
      <td>${row.name ?? '—'}</td>
      <td>${row.tipo ?? '—'}</td>
      <td>${row.mercado ?? row.panel ?? '—'}</td>
      <td>${row.currency ?? '—'}</td>
      <td>${formatNumber(row.price, 2)}</td>
      <td>${row.as_of ? String(row.as_of).slice(0, 16) : '—'}</td>
      <td><button type="button" data-edit="${row.symbol}">Editar</button></td>
    </tr>
  `).join('');
  tbody.querySelectorAll('button[data-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const symbol = btn.getAttribute('data-edit');
      const item = state.catalog.find((r) => r.symbol === symbol);
      if (!item) return;
      state.selectedCatalog = item;
      document.getElementById('form-symbol').value = item.symbol;
      document.getElementById('form-name').value = item.name ?? '';
      document.getElementById('form-type').value = item.tipo ?? '';
      document.getElementById('form-market').value = item.mercado ?? item.panel ?? '';
      document.getElementById('form-currency').value = item.currency ?? '';
      document.getElementById('form-price').value = item.price ?? '';
      document.getElementById('form-asof').value = item.as_of ?? '';
    });
  });
};

const applyCatalogFilter = () => {
  const search = (document.getElementById('catalog-search')?.value || '').toLowerCase();
  const type = document.getElementById('catalog-type')?.value || 'all';
  state.filteredCatalog = state.catalog.filter((row) => {
    const okType = type === 'all' ? true : (row.tipo === type);
    const okSearch = search === '' || row.symbol.toLowerCase().includes(search) || (row.name ?? '').toLowerCase().includes(search);
    return okType && okSearch;
  });
  renderCatalog();
  const metaSymbols = document.getElementById('meta-symbols');
  if (metaSymbols) metaSymbols.textContent = `Símbolos: ${state.filteredCatalog.length}`;
};

const loadCatalog = async () => {
  const status = document.getElementById('catalog-status');
  if (status) status.textContent = 'Cargando catálogo...';
  try {
    const resp = await overlay.withLoader(() => getJson('/datalake/catalog'));
    const items = Array.isArray(resp?.data) ? resp.data : [];
    state.catalog = items;
    applyCatalogFilter();
    if (status) status.textContent = `Catálogo cargado (${items.length})`;
  } catch (error) {
    if (status) status.textContent = error?.error?.message ?? 'No se pudo cargar catálogo';
  }
};

const syncCatalog = async () => {
  const status = document.getElementById('catalog-status');
  if (status) status.textContent = 'Sincronizando con RAVA...';
  try {
    await overlay.withLoader(() => postJson('/datalake/catalog/sync', {}));
    await loadCatalog();
    if (status) status.textContent = 'Sincronización completa';
  } catch (error) {
    if (status) status.textContent = error?.error?.message ?? 'Fallo al sincronizar';
  }
};

const saveCatalogItem = async () => {
  const status = document.getElementById('catalog-status');
  const symbol = (document.getElementById('form-symbol')?.value || '').toUpperCase();
  if (symbol === '') {
    if (status) status.textContent = 'Símbolo obligatorio';
    return;
  }
  const payload = {
    symbol,
    name: document.getElementById('form-name')?.value || '',
    tipo: document.getElementById('form-type')?.value || '',
    mercado: document.getElementById('form-market')?.value || '',
    currency: document.getElementById('form-currency')?.value || '',
    price: document.getElementById('form-price')?.value || '',
    as_of: document.getElementById('form-asof')?.value || '',
  };
  if (!window.confirm(`Confirmas guardar ${symbol} en catálogo?`)) {
    return;
  }
  try {
    await overlay.withLoader(() => postJson('/datalake/catalog/item', payload));
    await loadCatalog();
    if (status) status.textContent = `Guardado ${symbol}`;
  } catch (error) {
    if (status) status.textContent = error?.error?.message ?? 'No se pudo guardar';
  }
};

const deleteCatalogItem = async () => {
  const status = document.getElementById('catalog-status');
  const symbol = (document.getElementById('form-symbol')?.value || '').toUpperCase();
  if (symbol === '') {
    if (status) status.textContent = 'Símbolo obligatorio para eliminar';
    return;
  }
  if (!window.confirm(`Eliminar ${symbol} del catálogo? Esta acción es irreversible.`)) {
    return;
  }
  try {
    await overlay.withLoader(() => deleteJson(`/datalake/catalog/item/${encodeURIComponent(symbol)}`));
    await loadCatalog();
    if (status) status.textContent = `Eliminado ${symbol}`;
  } catch (error) {
    if (status) status.textContent = error?.error?.message ?? 'No se pudo eliminar';
  }
};

const resetForm = () => {
  state.selectedCatalog = null;
  document.getElementById('form-symbol').value = '';
  document.getElementById('form-name').value = '';
  document.getElementById('form-type').value = '';
  document.getElementById('form-market').value = '';
  document.getElementById('form-currency').value = '';
  document.getElementById('form-price').value = '';
  document.getElementById('form-asof').value = '';
};

const loadSymbols = async () => {
  const response = await getJson('/datalake/prices/symbols');
  state.symbols = Array.isArray(response?.symbols) ? response.symbols : [];
  const selects = [document.getElementById('snapshot-symbol'), document.getElementById('series-symbol')];
  selects.forEach((sel) => {
    if (!sel) return;
    sel.innerHTML = state.symbols.map((s) => `<option value="${s}">${s}</option>`).join('');
  });
};

const loadLatestSnapshot = async () => {
  const symbol = document.getElementById('snapshot-symbol')?.value || '';
  if (!symbol) return;
  const meta = document.getElementById('snapshot-meta');
  const body = document.getElementById('snapshot-body');
  if (meta) meta.textContent = 'Cargando...';
  if (body) body.innerHTML = '<tr><td class="muted">Cargando...</td></tr>';
  try {
    const resp = await overlay.withLoader(() => getJson(`/datalake/prices/latest?symbol=${encodeURIComponent(symbol)}`));
    state.snapshots[symbol] = resp;
    if (meta) meta.textContent = `Fuente: ${resp?.source ?? 'N/D'} · As of: ${resp?.asOf ?? '--'}`;
    if (body) {
      body.innerHTML = `
        <tr><td>Símbolo</td><td>${resp?.symbol ?? symbol}</td></tr>
        <tr><td>Precio</td><td>${formatNumber(resp?.close, 4)}</td></tr>
        <tr><td>Open</td><td>${formatNumber(resp?.open, 4)}</td></tr>
        <tr><td>High</td><td>${formatNumber(resp?.high, 4)}</td></tr>
        <tr><td>Low</td><td>${formatNumber(resp?.low, 4)}</td></tr>
        <tr><td>Prev Close</td><td>${formatNumber(resp?.previous_close, 4)}</td></tr>
        <tr><td>Moneda</td><td>${resp?.currency ?? 'N/D'}</td></tr>
      `;
    }
  } catch (error) {
    if (meta) meta.textContent = error?.error?.message ?? 'No se pudo cargar snapshot';
    if (body) body.innerHTML = '<tr><td class="muted">Error al cargar</td></tr>';
  }
};

const loadSeries = async () => {
  const symbol = document.getElementById('series-symbol')?.value || '';
  const period = document.getElementById('series-period')?.value || '1m';
  const body = document.getElementById('series-body');
  const meta = document.getElementById('series-meta');
  if (!symbol) return;
  if (body) body.innerHTML = '<tr><td class="muted" colspan="5">Cargando...</td></tr>';
  if (meta) meta.textContent = '';
  try {
    const resp = await overlay.withLoader(() => getJson(`/datalake/prices/series?symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(period)}`));
    const points = Array.isArray(resp?.points) ? resp.points : [];
    state.series = points;
    if (body) {
      body.innerHTML = points.map((p) => `
        <tr>
          <td>${p.t ? String(p.t).slice(0, 16) : '—'}</td>
          <td>${formatNumber(p.open ?? p.price, 4)}</td>
          <td>${formatNumber(p.high ?? p.price, 4)}</td>
          <td>${formatNumber(p.low ?? p.price, 4)}</td>
          <td>${formatNumber(p.close ?? p.price, 4)}</td>
        </tr>
      `).join('') || '<tr><td class="muted" colspan="5">Sin datos</td></tr>';
    }
    if (meta) meta.textContent = `${points.length} puntos · Período ${period} · Serie ${symbol}`;
  } catch (error) {
    if (body) body.innerHTML = '<tr><td class="muted" colspan="5">Error al cargar</td></tr>';
    if (meta) meta.textContent = error?.error?.message ?? 'No se pudo cargar serie';
  }
};

const handleCollect = async () => {
  if (state.collecting) return;
  state.collecting = true;
  resetLog('Iniciando ingesta de precios...');
  try {
    appendLog('Solicitando ingesta al servidor...');
    const response = await overlay.withLoader(() => postJson('/datalake/prices/collect', {}));
    renderStepLog(response?.steps);
    appendLog(`Resultado final -> OK: ${response.ok} | Fallidos: ${response.failed} | Total: ${response.total_symbols}`);
  } catch (error) {
    appendLog(`Error al recolectar: ${error?.error?.message ?? error?.message ?? 'Desconocido'}`);
  } finally {
    state.collecting = false;
  }
};

const loadProfile = async () => {
  try {
    const profile = await getJson('/me');
    state.profile = profile;
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch (error) {
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
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
    profile: authStore.getProfile(),
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  if (!isAdminProfile(state.profile ?? authStore.getProfile())) {
    const status = document.getElementById('catalog-status');
    if (status) status.textContent = 'Acceso restringido: solo Admin.';
    document.querySelectorAll('button, input, select').forEach((el) => {
      if (el?.id?.startsWith('form-') || el?.id?.includes('catalog') || el?.id?.includes('collect') || el?.id?.includes('snapshot') || el?.id?.includes('series')) {
        el.setAttribute('disabled', 'disabled');
      }
    });
    return;
  }
  renderTabs();
  await loadCatalog();
  await loadSymbols();
  await loadLatestSnapshot();
  await loadSeries();

  document.getElementById('catalog-search')?.addEventListener('input', applyCatalogFilter);
  document.getElementById('catalog-type')?.addEventListener('change', applyCatalogFilter);
  document.getElementById('catalog-sync')?.addEventListener('click', syncCatalog);
  document.getElementById('form-save')?.addEventListener('click', saveCatalogItem);
  document.getElementById('form-delete')?.addEventListener('click', deleteCatalogItem);
  document.getElementById('form-reset')?.addEventListener('click', resetForm);
  document.getElementById('snapshot-load')?.addEventListener('click', loadLatestSnapshot);
  document.getElementById('collect-btn')?.addEventListener('click', () => {
    if (window.confirm('Recolectar precios ahora? Puede tardar varios segundos.')) {
      handleCollect();
    }
  });
  document.getElementById('series-load')?.addEventListener('click', loadSeries);
  resetLog('Listo para iniciar ingesta.');
};

document.addEventListener('DOMContentLoaded', init);
