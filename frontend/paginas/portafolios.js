import { getJson, postJson, deleteJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  profile: null,
  catalog: [],
  portfolio: [],
  filtered: [],
  counts: {
    portfolio: 0,
    acciones_argentinas: 0,
    cedears: 0,
    bonos: 0,
    mercados_globales: 0,
  },
};

const setError = (msg) => {
  const el = document.getElementById('portfolio-error');
  if (el) el.textContent = msg || '';
};

const formatPrice = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  const num = Number(value);
  if (Number.isNaN(num)) return String(value);
  return num.toLocaleString('es-AR', { maximumFractionDigits: 4 });
};

const ensureAuthenticated = () => {
  const token = authStore.getToken() ?? localStorage.getItem('jwt');
  if (!token) {
    const target = '/';
    if (window.top && window.top !== window) {
      window.top.location.href = target;
    } else {
      window.location.href = target;
    }
  }
};

const CATEGORY_LABELS = {
  acciones_argentinas: 'Acciones Argentinas',
  cedears: 'CEDEARs',
  bonos: 'Bonos',
  mercados_globales: 'Mercados Globales',
};

const CATEGORY_TYPES = {
  acciones_argentinas: 'ACCION_AR',
  cedears: 'CEDEAR',
  bonos: 'BONO',
  mercados_globales: 'MERCADOS_GLOBALES',
};

const normalizeCatalogItem = (row) => {
  const especie = String(row.especie ?? row.ticker ?? row.symbol ?? '').toUpperCase();
  if (!especie) return null;
  const symbol = String(row.symbol ?? '').toUpperCase();
  const category = String(row.category ?? '').trim();
  if (!category) return null;
  return {
    especie,
    symbol,
    name: row.nombre ?? row.name ?? especie,
    category,
    panel: row.panel ?? '',
    segment: row.segment ?? '',
    ultimo: row.ultimo ?? null,
    mercado: row.mercado ?? '',
  };
};

const normalizePortfolioItem = (row) => {
  const especie = String(row.especie ?? row.symbol ?? '').toUpperCase();
  if (!especie) return null;
  const symbol = String(row.symbol ?? '').toUpperCase();
  return {
    especie,
    symbol,
    name: row.name ?? row.nombre ?? especie,
    category: row.type ?? row.tipo ?? '',
    panel: row.exchange ?? row.mercado ?? '',
  };
};

const rebuildCounts = () => {
  const counts = {
    portfolio: state.portfolio.length,
    acciones_argentinas: 0,
    cedears: 0,
    bonos: 0,
    mercados_globales: 0,
  };
  state.catalog.forEach((item) => {
    if (!item?.category) return;
    const key = item.category;
    if (key === 'acciones_argentinas') counts.acciones_argentinas += 1;
    if (key === 'cedears') counts.cedears += 1;
    if (key === 'bonos') counts.bonos += 1;
    if (key === 'mercados_globales') counts.mercados_globales += 1;
  });
  state.counts = counts;
  const setBadge = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };
  setBadge('badge-portfolio', `En cartera: ${counts.portfolio}`);
  setBadge('badge-acciones', `Acciones AR: ${counts.acciones_argentinas}`);
  setBadge('badge-cedears', `CEDEARs: ${counts.cedears}`);
  setBadge('badge-bonos', `Bonos: ${counts.bonos}`);
  setBadge('badge-globales', `Mercados Globales: ${counts.mercados_globales}`);
};

const loadPortfolio = async () => {
  setError('');
  try {
    const resp = await getJson('/portfolio/instruments');
    const items = Array.isArray(resp?.data) ? resp.data : [];
    state.portfolio = items.map(normalizePortfolioItem).filter(Boolean);
  } catch (error) {
    state.portfolio = [];
    setError(error?.error?.message ?? 'No se pudo cargar tu portafolio');
  }
};

const loadCatalog = async () => {
  setError('');
  try {
    const resp = await getJson('/rava/catalog');
    const items = Array.isArray(resp?.data) ? resp.data : [];
    state.catalog = items.map(normalizeCatalogItem).filter(Boolean);
  } catch (error) {
    state.catalog = [];
    setError(error?.error?.message ?? 'No se pudo cargar el catálogo de Rava');
  }
};

const applyFilter = () => {
  const category = document.getElementById('ticker-category')?.value || 'all';
  const search = (document.getElementById('ticker-search')?.value || '').toLowerCase();
  const portfolioSet = new Set(state.portfolio.map((p) => p.especie));
  const source = category === 'selected' ? state.portfolio : state.catalog;
  state.filtered = source.filter((item) => {
    if (!item) return false;
    const matchesSearch = search === ''
      || item.especie.toLowerCase().includes(search)
      || (item.symbol && item.symbol.toLowerCase().includes(search))
      || String(item.name ?? '').toLowerCase().includes(search);
    if (!matchesSearch) return false;
    if (category === 'selected') return true;
    if (category === 'all') return true;
    if (category === 'acciones_argentinas' && item.category === 'acciones_argentinas') return true;
    if (category === 'cedears' && item.category === 'cedears') return true;
    if (category === 'bonos' && item.category === 'bonos') return true;
    if (category === 'mercados_globales' && item.category === 'mercados_globales') return true;
    return false;
  }).map((item) => ({
    ...item,
    inPortfolio: portfolioSet.has(item.especie),
  }));
  renderList();
};

const renderList = () => {
  const body = document.getElementById('ticker-list');
  if (!body) return;
  if (state.filtered.length === 0) {
    body.innerHTML = '<tr><td class="muted" colspan="6">Sin resultados</td></tr>';
    return;
  }
  const category = document.getElementById('ticker-category')?.value || 'all';
  body.innerHTML = state.filtered.map((item) => {
    const isSelected = category === 'selected' || item.inPortfolio;
    const actionLabel = isSelected ? 'Quitar' : 'Agregar';
    const actionClass = isSelected ? 'btn-warn' : 'btn-secondary';
    const categoryLabel = CATEGORY_LABELS[item.category] ?? item.category ?? '—';
    const displaySymbol = item.symbol || item.especie;
    return `
      <tr data-especie="${item.especie}">
        <td><strong>${displaySymbol}</strong></td>
        <td>${item.name ?? '—'}</td>
        <td>${formatPrice(item.ultimo)}</td>
        <td>${categoryLabel}</td>
        <td>${item.panel || item.segment || '—'}</td>
        <td><button type="button" class="${actionClass}" data-action="${isSelected ? 'remove' : 'add'}" data-especie="${item.especie}">${actionLabel}</button></td>
      </tr>
    `;
  }).join('');

  body.querySelectorAll('button[data-action]').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      const especie = btn.getAttribute('data-especie');
      if (!especie) return;
      const action = btn.getAttribute('data-action');
      const current = state.catalog.find((row) => row.especie === especie)
        || state.portfolio.find((row) => row.especie === especie);
      try {
        if (action === 'remove') {
          await overlay.withLoader(() => deleteJson(`/portfolio/instruments/${encodeURIComponent(especie)}`));
        } else {
          const category = current?.category ?? '';
          const payload = {
            especie,
            name: current?.name ?? '',
            type: CATEGORY_TYPES[category] ?? category,
            exchange: current?.mercado ?? current?.panel ?? '',
            currency: current?.currency ?? '',
          };
          await overlay.withLoader(() => postJson('/portfolio/instruments', payload));
        }
        await loadPortfolio();
        rebuildCounts();
        applyFilter();
      } catch (error) {
        setError(error?.error?.message ?? 'No se pudo actualizar el portafolio');
      }
    });
  });
};

const bindUi = () => {
  document.getElementById('catalog-reload')?.addEventListener('click', () => {
    overlay.withLoader(async () => {
      await loadCatalog();
      rebuildCounts();
      applyFilter();
    });
  });
  document.getElementById('ticker-category')?.addEventListener('change', applyFilter);
  let searchTimer;
  document.getElementById('ticker-search')?.addEventListener('input', () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
      applyFilter();
    }, 300);
  });
};

const init = async () => {
  ensureAuthenticated();
  renderToolbar();
  bindToolbarNavigation();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: async () => {
      try { await getJson('/auth/logout'); } finally { authStore.clearToken(); window.location.href = '/'; }
    },
    onAdmin: () => window.location.href = '/Frontend/usuarios.html',
  });
  highlightToolbar();
  try {
    state.profile = await getJson('/me');
  } catch {
    state.profile = authStore.getProfile();
  }
  setAdminMenuVisibility(state.profile);
  setToolbarUserName(state.profile?.email ?? '');
  await overlay.withLoader(async () => {
    await loadPortfolio();
    await loadCatalog();
    rebuildCounts();
    const categorySelect = document.getElementById('ticker-category');
    if (categorySelect) {
      categorySelect.value = state.portfolio.length > 0 ? 'selected' : 'all';
    }
    applyFilter();
  });
  bindUi();
};

document.addEventListener('DOMContentLoaded', init);
