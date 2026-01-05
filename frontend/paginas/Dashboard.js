import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  portfolios: [],
  quote: null,
  metrics: null,
};

const METRICS_COOKIE = 'metrics_providers_v1';

const todayKey = () => new Date().toISOString().slice(0, 10);

const readCookie = (name) => {
  const parts = document.cookie.split(';').map((c) => c.trim());
  const found = parts.find((c) => c.startsWith(`${name}=`));
  if (!found) return null;
  return found.substring(name.length + 1);
};

const writeSessionCookie = (name, value) => {
  document.cookie = `${name}=${value}; path=/; SameSite=Lax`;
};

const loadMetricsFromCookie = () => {
  try {
    const raw = readCookie(METRICS_COOKIE);
    if (!raw) return null;
    const parsed = JSON.parse(decodeURIComponent(raw));
    if (parsed?.date !== todayKey()) return null;
    return parsed.payload ?? null;
  } catch {
    return null;
  }
};

const saveMetricsToCookie = (payload) => {
  try {
    const wrapped = { date: todayKey(), payload };
    writeSessionCookie(METRICS_COOKIE, encodeURIComponent(JSON.stringify(wrapped)));
  } catch {
    // ignorar fallos de cookie
  }
};

const createCard = (title, body) => `
  <section class="card">
    <h2>${title}</h2>
    ${body}
  </section>
`;

const renderOverview = () => {
  const app = document.getElementById('app');
  if (!app) return;
  const sections = [];
  if (state.profile) {
    sections.push(createCard('Perfil', `
      <div class="profile">
        <strong>${state.profile.email}</strong>
        <span>Rol: ${state.profile.role}</span>
        <span>Estado: ${state.profile.status}</span>
      </div>
    `));
  }
  if (state.metrics) {
    const td = state.metrics.providers?.twelvedata ?? {};
    const eod = state.metrics.providers?.eodhd ?? {};
    const av = state.metrics.providers?.alphavantage ?? {};
    sections.push(createCard('Consumo de APIs', `
      <div class="controls">
        <div>
          <strong>Twelve Data</strong><br/>
          <small>Usadas: ${td.used ?? 0} / ${td.allowed ?? 'N/D'}</small><br/>
          <small>Éxitos: ${td.success ?? 0} | Fallos: ${td.failed ?? 0}</small><br/>
          <small>Restantes: ${td.remaining ?? 0}</small>
        </div>
        <div>
          <strong>EODHD</strong><br/>
          <small>Usadas: ${eod.used ?? 0} / ${eod.allowed ?? 'N/D'}</small><br/>
          <small>Éxitos: ${eod.success ?? 0} | Fallos: ${eod.failed ?? 0}</small><br/>
          <small>Restantes: ${eod.remaining ?? 0}</small>
        </div>
        <div>
          <strong>Alpha Vantage</strong><br/>
          <small>Usadas: ${av.used ?? 0} / ${av.allowed ?? 'N/D'}</small><br/>
          <small>Éxitos: ${av.success ?? 0} | Fallos: ${av.failed ?? 0}</small><br/>
          <small>Restantes: ${av.remaining ?? 0}</small>
        </div>
      </div>
    `));
  }
  app.innerHTML = sections.join('');
};

const renderPortfoliosView = () => {
  const app = document.getElementById('app');
  if (!app) return;
  const list = state.portfolios
    .map((p) => `
      <li>
        <strong>${p.name}</strong>
        <span>${p.baseCurrency}</span>
      </li>
    `)
    .join('');
  app.innerHTML = createCard('Portafolios', `<ul class="simple-list">${list || '<li>No hay portafolios registrados</li>'}</ul>`);
};

const renderPricesView = () => {
  const app = document.getElementById('app');
  if (!app) return;
  if (!state.quote) {
    app.innerHTML = createCard('Precios', '<p>No hay cotizaciones disponibles</p>');
    return;
  }
  app.innerHTML = createCard('Precios', `
    <div class="controls">
      <strong>${state.quote.symbol}</strong>
      <span>Cierre: ${state.quote.close}</span>
      <span>Fuente: ${state.quote.source}</span>
      <small>${new Date(state.quote.asOf).toLocaleString()}</small>
    </div>
  `);
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const render = async () => {
  const app = document.getElementById('app');
  if (!app) {
    return;
  }
  app.innerHTML = '<section class="card"><h2>Cargando dashboard...</h2></section>';
  try {
    state.profile = await getJson('/me');
  } catch (error) {
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
    return;
  }
  try {
    state.portfolios = await getJson('/portfolios');
  } catch (error) {
    state.portfolios = [];
  }
  const cachedMetrics = loadMetricsFromCookie();
  if (cachedMetrics) {
    state.metrics = cachedMetrics;
  } else {
    try {
      state.metrics = await getJson('/metrics/providers');
      if (state.metrics) {
        saveMetricsToCookie(state.metrics);
      }
    } catch {
      state.metrics = null;
    }
  }
  syncUserName();
  renderOverview();
};

const syncUserName = () => {
  setToolbarUserName(state.profile?.email ?? '');
  setAdminMenuVisibility(state.profile);
};

document.addEventListener('DOMContentLoaded', () => {
  renderToolbar();
  setToolbarUserName('');
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
  });
  bindToolbarNavigation();
  highlightToolbar();
  render();
});
