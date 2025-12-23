import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  portfolios: [],
  quote: null,
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
  const list = state.portfolios
    .map((p) => `<li>${p.name} (${p.baseCurrency})</li>`)
    .join('');
  sections.push(createCard('Portafolios', `<ul>${list || '<li>No hay portafolios</li>'}</ul>`));
  if (state.quote) {
    sections.push(createCard('Última cotización', `
      <div class="controls">
        <strong>${state.quote.symbol}</strong>
        <span>${state.quote.close}</span>
        <small>${new Date(state.quote.asOf).toLocaleString()}</small>
      </div>
    `));
  } else {
    sections.push(createCard('Última cotización', '<p>Datos no disponibles</p>'));
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
  try {
    state.quote = await getJson('/quotes?symbol=AAPL');
  } catch (error) {
    state.quote = null;
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
