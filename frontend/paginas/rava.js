import { getJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const state = {
  items: [],
  meta: {},
  acciones: [],
  accionesMeta: {},
  bonos: [],
  bonosMeta: {},
  filter: '',
  accionesFilter: '',
  bonosFilter: '',
  historicosCache: {},
  historicoItems: [],
  historicoMeta: {},
  historicoSymbol: '',
  historicoError: '',
  profile: null,
  activeView: 'cedears',
};

const overlay = createLoadingOverlay();

const setText = (id, text) => {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
};

const setError = (message) => {
  const el = document.getElementById('rava-error');
  if (el) el.textContent = message || '';
};

const setHistoryError = (message) => {
  const el = document.getElementById('history-error');
  if (el) el.textContent = message || '';
  state.historicoError = message || '';
};

const setHistoryStatus = (text) => {
  const el = document.getElementById('history-status');
  if (el) el.textContent = text || '';
};

const numberFormatter = (digits = 2) => new Intl.NumberFormat('es-AR', {
  minimumFractionDigits: digits,
  maximumFractionDigits: digits,
});

const formatNumber = (value, digits = 2) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  const formatter = numberFormatter(digits);
  return formatter.format(Number(value));
};

const formatPercent = (value) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '–';
  return `${formatNumber(value, 2)}%`;
};

const formatSignedPercent = (value) => {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return { text: '–', className: '' };
  const num = Number(value);
  const className = num > 0 ? 'pos' : (num < 0 ? 'neg' : '');
  const text = `${num > 0 ? '+' : ''}${formatNumber(num, 2)}%`;
  return { text, className };
};

const renderMeta = () => {
  const meta = state.meta || {};
  const badgeCache = document.getElementById('badge-cache');
  const badgeStale = document.getElementById('badge-stale');
  if (badgeCache) {
    badgeCache.textContent = meta.cached ? 'Cache' : 'Live';
    badgeCache.classList.toggle('warn', Boolean(meta.stale));
  }
  if (badgeStale) {
    badgeStale.textContent = meta.stale ? 'Stale' : 'OK';
    badgeStale.classList.toggle('warn', Boolean(meta.stale));
  }
  setText('status-updated', `Actualizado: ${meta.as_of || meta.fetched_at || '--'}`);
  setText('status-count', `CEDEARs: ${state.items.length}`);
  const extras = [];
  if (meta.ttl_seconds) extras.push(`TTL ${meta.ttl_seconds}s`);
  if (meta.backoff_until) extras.push(`backoff hasta ${meta.backoff_until}`);
  if (meta.error) extras.push(`error: ${meta.error}`);
  setText('status-meta', extras.join(' · '));
};

const renderAccionesMeta = () => {
  const meta = state.accionesMeta || {};
  const badgeCache = document.getElementById('badge-acc-cache');
  const badgeStale = document.getElementById('badge-acc-stale');
  if (badgeCache) {
    badgeCache.textContent = meta.cached ? 'Cache' : 'Live';
    badgeCache.classList.toggle('warn', Boolean(meta.stale));
  }
  if (badgeStale) {
    badgeStale.textContent = meta.stale ? 'Stale' : 'OK';
    badgeStale.classList.toggle('warn', Boolean(meta.stale));
  }
  setText('status-acc-updated', `Actualizado: ${meta.as_of || meta.fetched_at || '--'}`);
  setText('status-acc-count', `Acciones: ${state.acciones.length}`);
  const extras = [];
  if (meta.ttl_seconds) extras.push(`TTL ${meta.ttl_seconds}s`);
  if (meta.backoff_until) extras.push(`backoff hasta ${meta.backoff_until}`);
  if (meta.error) extras.push(`error: ${meta.error}`);
  setText('status-acc-meta', extras.join(' · '));
};

const renderBonosMeta = () => {
  const meta = state.bonosMeta || {};
  const badgeCache = document.getElementById('badge-bonos-cache');
  const badgeStale = document.getElementById('badge-bonos-stale');
  if (badgeCache) {
    badgeCache.textContent = meta.cached ? 'Cache' : 'Live';
    badgeCache.classList.toggle('warn', Boolean(meta.stale));
  }
  if (badgeStale) {
    badgeStale.textContent = meta.stale ? 'Stale' : 'OK';
    badgeStale.classList.toggle('warn', Boolean(meta.stale));
  }
  setText('status-bonos-updated', `Actualizado: ${meta.as_of || meta.fetched_at || '--'}`);
  setText('status-bonos-count', `Bonos: ${state.bonos.length}`);
  const extras = [];
  if (meta.ttl_seconds) extras.push(`TTL ${meta.ttl_seconds}s`);
  if (meta.backoff_until) extras.push(`backoff hasta ${meta.backoff_until}`);
  if (meta.error) extras.push(`error: ${meta.error}`);
  setText('status-bonos-meta', extras.join(' · '));
};

const setActiveView = (view) => {
  const allowed = ['cedears', 'acciones', 'bonos'];
  state.activeView = allowed.includes(view) ? view : 'cedears';
  const cedearsSection = document.getElementById('view-cedears');
  const accionesSection = document.getElementById('view-acciones');
  const bonosSection = document.getElementById('view-bonos');
  if (cedearsSection) cedearsSection.classList.toggle('hidden', state.activeView !== 'cedears');
  if (accionesSection) accionesSection.classList.toggle('hidden', state.activeView !== 'acciones');
  if (bonosSection) bonosSection.classList.toggle('hidden', state.activeView !== 'bonos');
  document.querySelectorAll('.submenu .subitem').forEach((btn) => {
    const v = btn.getAttribute('data-view');
    btn.classList.toggle('active', v === state.activeView);
  });
};

const renderTable = () => {
  const body = document.getElementById('cedears-body');
  if (!body) return;
  const filter = state.filter.trim().toUpperCase();
  const rows = (state.items || [])
    .filter((item) => {
      if (!filter) return true;
      const haystack = [
        item.symbol ?? '',
        item.especie ?? '',
        item.panel ?? '',
        item.mercado ?? '',
      ].join(' ').toUpperCase();
      return haystack.includes(filter);
    })
    .sort((a, b) => (a.symbol || '').localeCompare(b.symbol || ''))
    .map((item) => {
      const variation = formatSignedPercent(item.variacion);
      const mtd = formatPercent(item.var_mtd);
      const ytd = formatPercent(item.var_ytd);
      return `
        <tr class="clickable-row" data-symbol="${item.symbol ?? ''}" data-especie="${item.especie ?? ''}" data-source="cedears">
          <td>
            <div class="pill">${item.symbol ?? '-'}</div>
            <div class="small">${item.panel ?? ''}</div>
          </td>
          <td>${formatNumber(item.ultimo, 2)}</td>
          <td class="${variation.className}">${variation.text}</td>
          <td>${mtd} / ${ytd}</td>
          <td>${formatNumber(item.apertura, 2)}</td>
          <td>${formatNumber(item.minimo, 2)} / ${formatNumber(item.maximo, 2)}</td>
          <td>${formatNumber(item.ccl_ultimo, 2)}</td>
          <td>${formatNumber(item.volumen_nominal, 0)}</td>
          <td>${formatNumber(item.volumen_efectivo, 0)}</td>
          <td>${item.hora ?? '–'}</td>
          <td>${item.ratio ?? '–'}</td>
        </tr>
      `;
    })
    .join('');
  body.innerHTML = rows || '<tr><td colspan="11" class="muted">Sin resultados</td></tr>';
};

const renderAccionesTable = () => {
  const body = document.getElementById('acciones-body');
  if (!body) return;
  const filter = state.accionesFilter.trim().toUpperCase();
  const rows = (state.acciones || [])
    .filter((item) => {
      if (!filter) return true;
      const haystack = [
        item.symbol ?? '',
        item.especie ?? '',
        item.panel ?? '',
        item.segment ?? '',
      ].join(' ').toUpperCase();
      return haystack.includes(filter);
    })
    .sort((a, b) => (a.symbol || '').localeCompare(b.symbol || ''))
    .map((item) => {
      const variation = formatSignedPercent(item.variacion);
      const mtd = formatPercent(item.var_mtd);
      const ytd = formatPercent(item.var_ytd);
      return `
        <tr class="clickable-row" data-symbol="${item.symbol ?? ''}" data-especie="${item.especie ?? ''}" data-source="acciones">
          <td>
            <div class="pill">${item.symbol ?? '-'}</div>
            <div class="small">${item.segment || item.panel || ''}</div>
          </td>
          <td>${formatNumber(item.ultimo, 2)}</td>
          <td class="${variation.className}">${variation.text}</td>
          <td>${mtd} / ${ytd}</td>
          <td>${formatNumber(item.apertura, 2)}</td>
          <td>${formatNumber(item.minimo, 2)} / ${formatNumber(item.maximo, 2)}</td>
          <td>${formatNumber(item.precio_compra, 2)} x ${formatNumber(item.cantidad_compra, 0)}</td>
          <td>${formatNumber(item.precio_venta, 2)} x ${formatNumber(item.cantidad_venta, 0)}</td>
          <td>${formatNumber(item.volumen_nominal, 0)}</td>
          <td>${formatNumber(item.volumen_efectivo, 0)}</td>
          <td>${item.hora ?? '–'}</td>
          <td>${item.panel ?? '-'}</td>
        </tr>
      `;
    })
    .join('');
  body.innerHTML = rows || '<tr><td colspan="12" class="muted">Sin resultados</td></tr>';
};

const renderBonosTable = () => {
  const body = document.getElementById('bonos-body');
  if (!body) return;
  const filter = state.bonosFilter.trim().toUpperCase();
  const rows = (state.bonos || [])
    .filter((item) => {
      if (!filter) return true;
      const haystack = [
        item.symbol ?? '',
        item.especie ?? '',
        item.panel ?? '',
      ].join(' ').toUpperCase();
      return haystack.includes(filter);
    })
    .sort((a, b) => (a.symbol || '').localeCompare(b.symbol || ''))
    .map((item) => {
      const variation = formatSignedPercent(item.variacion);
      const mtd = formatPercent(item.var_mtd);
      const ytd = formatPercent(item.var_ytd);
      return `
        <tr class="clickable-row" data-symbol="${item.symbol ?? ''}" data-especie="${item.especie ?? ''}" data-source="bonos">
          <td>
            <div class="pill">${item.symbol ?? '-'}</div>
            <div class="small">${item.panel || ''}</div>
          </td>
          <td>${formatNumber(item.ultimo, 2)}</td>
          <td class="${variation.className}">${variation.text}</td>
          <td>${mtd} / ${ytd}</td>
          <td>${formatNumber(item.apertura, 2)}</td>
          <td>${formatNumber(item.minimo, 2)} / ${formatNumber(item.maximo, 2)}</td>
          <td>${formatNumber(item.precio_compra, 2)} x ${formatNumber(item.cantidad_compra, 0)}</td>
          <td>${formatNumber(item.precio_venta, 2)} x ${formatNumber(item.cantidad_venta, 0)}</td>
          <td>${formatNumber(item.volumen_nominal, 0)}</td>
          <td>${formatNumber(item.volumen_efectivo, 0)}</td>
          <td>${item.hora ?? '–'}</td>
          <td>${item.panel ?? '-'}</td>
        </tr>
      `;
    })
    .join('');
  body.innerHTML = rows || '<tr><td colspan="12" class="muted">Sin resultados</td></tr>';
};

const renderHistoricosMeta = () => {
  const meta = state.historicoMeta || {};
  setText('history-symbol', `Símbolo: ${state.historicoSymbol || '--'}`);
  setText('history-count', `Registros: ${state.historicoItems.length || 0}`);
  const extras = [];
  if (meta.from) extras.push(`desde ${meta.from}`);
  if (meta.to) extras.push(`hasta ${meta.to}`);
  if (meta.source) extras.push(meta.source);
  setText('history-meta', extras.join(' · '));
};

const renderHistoricosTable = () => {
  const body = document.getElementById('historicos-body');
  if (!body) return;
  if (!state.historicoSymbol) {
    body.innerHTML = '<tr><td colspan="8" class="muted">Sin selección</td></tr>';
    return;
  }
  const ordered = [...(state.historicoItems || [])].sort((a, b) => {
    const fa = a.fecha ?? '';
    const fb = b.fecha ?? '';
    return fb.localeCompare(fa);
  });
  const rows = ordered
    .map((item) => {
      const variation = formatSignedPercent(item.variacion);
      return `
        <tr>
          <td>${item.fecha ?? '–'}</td>
          <td>${formatNumber(item.apertura, 2)}</td>
          <td>${formatNumber(item.maximo, 2)}</td>
          <td>${formatNumber(item.minimo, 2)}</td>
          <td>${formatNumber(item.cierre, 2)}</td>
          <td class="${variation.className}">${variation.text}</td>
          <td>${formatNumber(item.volumen, 0)}</td>
          <td>${formatNumber(item.ajuste, 2)}</td>
        </tr>
      `;
    })
    .join('');
  body.innerHTML = rows || '<tr><td colspan="8" class="muted">Sin datos</td></tr>';
};

const buildDebugPayload = () => ({
  cedears: { data: state.items, meta: state.meta },
  acciones: state.debugAcciones ?? null,
  bonos: state.debugBonos ?? null,
  historicos: state.debugHistoricos ?? null,
});

const setDebugPayload = (payload) => {
  const el = document.getElementById('debug-output');
  if (el) el.textContent = JSON.stringify(payload ?? buildDebugPayload(), null, 2);
};

const fetchCedears = async () => {
  setError('');
  try {
    const resp = await overlay.withLoader(() => getJson('/rava/cedears'));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    state.items = Array.isArray(items) ? items : [];
    state.meta = resp?.meta ?? {};
    renderMeta();
    renderTable();
    setDebugPayload();
  } catch (error) {
    console.info('[rava] error', error);
    setError(error?.error?.message ?? 'No se pudo obtener CEDEARs');
  }
};

const fetchAcciones = async () => {
  const errEl = document.getElementById('acciones-error');
  if (errEl) errEl.textContent = '';
  try {
    const resp = await overlay.withLoader(() => getJson('/rava/acciones'));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    state.acciones = Array.isArray(items) ? items : [];
    state.accionesMeta = resp?.meta ?? {};
    renderAccionesMeta();
    renderAccionesTable();
    state.debugAcciones = resp;
    setDebugPayload();
  } catch (error) {
    console.info('[rava] acciones error', error);
    if (errEl) errEl.textContent = error?.error?.message ?? 'No se pudo obtener Acciones Argentinas';
  }
};

const fetchBonos = async () => {
  const errEl = document.getElementById('bonos-error');
  if (errEl) errEl.textContent = '';
  try {
    const resp = await overlay.withLoader(() => getJson('/rava/bonos'));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    state.bonos = Array.isArray(items) ? items : [];
    state.bonosMeta = resp?.meta ?? {};
    renderBonosMeta();
    renderBonosTable();
    state.debugBonos = resp;
    setDebugPayload();
  } catch (error) {
    console.info('[rava] bonos error', error);
    if (errEl) errEl.textContent = error?.error?.message ?? 'No se pudo obtener Bonos';
  }
};

const loadHistoricos = async (especie, displaySymbol) => {
  const symbol = displaySymbol || especie;
  const normalizedEspecie = (especie || '').trim();
  if (!normalizedEspecie) return;
  setHistoryError('');
  setHistoryStatus(`Cargando histórico para ${symbol || especie}...`);
  state.historicoSymbol = symbol || especie;
  const cacheKey = normalizedEspecie.toUpperCase();
  const cached = state.historicosCache[cacheKey];
  if (cached) {
    state.historicoItems = cached.data ?? cached.items ?? [];
    state.historicoMeta = cached.meta ?? {};
    renderHistoricosMeta();
    renderHistoricosTable();
    setHistoryStatus(`Histórico en caché para ${symbol || especie}`);
    setDebugPayload();
    return;
  }
  try {
    const resp = await overlay.withLoader(() => getJson(`/rava/historicos?especie=${encodeURIComponent(normalizedEspecie)}`));
    const items = Array.isArray(resp?.data) ? resp.data : (resp?.items ?? []);
    const meta = resp?.meta ?? {};
    state.historicoItems = Array.isArray(items) ? items : [];
    state.historicoMeta = meta;
    state.historicosCache[cacheKey] = { data: state.historicoItems, meta };
    state.debugHistoricos = { especie: normalizedEspecie, data: state.historicoItems, meta };
    renderHistoricosMeta();
    renderHistoricosTable();
    setHistoryStatus(`Histórico cargado para ${symbol || especie}`);
    setDebugPayload();
  } catch (error) {
    console.info('[rava] historicos error', error);
    setHistoryError(error?.error?.message ?? 'No se pudo obtener histórico');
    renderHistoricosMeta();
    renderHistoricosTable();
    setHistoryStatus(`Fallo al cargar ${symbol || especie}`);
  }
};

const bindHistoryClicks = () => {
  const attach = (bodyId) => {
    const body = document.getElementById(bodyId);
    if (!body) return;
    body.addEventListener('click', (event) => {
      const target = event.target;
      if (!target || typeof target.closest !== 'function') {
        return;
      }
      const row = target.closest('tr[data-symbol]');
      if (!row) return;
      const symbol = row.getAttribute('data-symbol') || '';
      const especie = row.getAttribute('data-especie') || symbol;
      if (!symbol && !especie) {
        return;
      }
      loadHistoricos(especie, symbol);
    });
  };
  attach('cedears-body');
  attach('acciones-body');
  attach('bonos-body');
};

const bindUi = () => {
  document.getElementById('reload-btn')?.addEventListener('click', fetchCedears);
  document.getElementById('filter-input')?.addEventListener('input', (event) => {
    state.filter = event.target.value || '';
    renderTable();
  });
  document.querySelectorAll('.submenu .subitem').forEach((btn) => {
    btn.addEventListener('click', () => {
      const view = btn.getAttribute('data-view');
      setActiveView(view);
    });
  });
  document.getElementById('acciones-reload-btn')?.addEventListener('click', fetchAcciones);
  document.getElementById('acciones-filter-input')?.addEventListener('input', (event) => {
    state.accionesFilter = event.target.value || '';
    renderAccionesTable();
  });
  document.getElementById('bonos-reload-btn')?.addEventListener('click', fetchBonos);
  document.getElementById('bonos-filter-input')?.addEventListener('input', (event) => {
    state.bonosFilter = event.target.value || '';
    renderBonosTable();
  });
  document.getElementById('btn-copy-raw')?.addEventListener('click', () => {
    const el = document.getElementById('debug-output');
    if (!el) return;
    const text = el.textContent || '';
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).catch(() => {});
    }
  });
  bindHistoryClicks();
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

document.addEventListener('DOMContentLoaded', async () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await getJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
    profile: state.profile,
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  bindUi();
  renderHistoricosMeta();
  renderHistoricosTable();
  fetchCedears();
  fetchAcciones();
  fetchBonos();
  setActiveView('cedears');
});
