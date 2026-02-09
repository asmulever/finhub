import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  profile: null,
  dolares: [],
  fetchedAt: null,
  error: '',
};

const createCard = (title, body) => `
  <section class="card">
    <h2>${title}</h2>
    ${body}
  </section>
`;

const formatNumber = (value) => {
  if (value === null || value === undefined || value === '') return '—';
  const num = Number(value);
  if (Number.isNaN(num)) return String(value);
  return num.toLocaleString('es-AR', { maximumFractionDigits: 4 });
};

const renderDolares = () => {
  const app = document.getElementById('app');
  if (!app) return;

  if (state.error) {
    app.innerHTML = createCard('Dólares', `<p>${state.error}</p>`);
    return;
  }

  const rows = state.dolares.map((item) => `
    <tr>
      <td><strong>${item.especie ?? '—'}</strong></td>
      <td>${formatNumber(item.ultimo)}</td>
      <td>${formatNumber(item.variacion)}</td>
      <td>${item.descripcion ?? '—'}</td>
      <td>${item.as_of ?? '—'}</td>
    </tr>
  `).join('');

  const table = `
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Referencia</th>
            <th>Último</th>
            <th>% Día</th>
            <th>Descripción</th>
            <th>As of</th>
          </tr>
        </thead>
        <tbody>
          ${rows || '<tr><td class="muted" colspan="5">Sin datos</td></tr>'}
        </tbody>
      </table>
    </div>
  `;

  const fetched = state.fetchedAt ? `<p class="muted">Actualizado: ${new Date(state.fetchedAt).toLocaleString()}</p>` : '';
  app.innerHTML = createCard('Dólares (Rava)', `${fetched}${table}`);
};

const loadDolares = async () => {
  try {
    const resp = await getJson('/rava/dolares');
    state.dolares = Array.isArray(resp?.data) ? resp.data : [];
    state.fetchedAt = resp?.fetched_at ?? null;
    state.error = '';
  } catch (error) {
    state.dolares = [];
    state.fetchedAt = null;
    state.error = error?.error?.message ?? 'No se pudieron cargar las cotizaciones de dólares';
  }
  renderDolares();
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
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
  });
  bindToolbarNavigation();
  highlightToolbar();

  try {
    state.profile = await getJson('/me');
  } catch {
    state.profile = authStore.getProfile();
  }
  setToolbarUserName(state.profile?.email ?? '');
  setAdminMenuVisibility(state.profile);

  await loadDolares();
  setInterval(loadDolares, 60000);
};

document.addEventListener('DOMContentLoaded', init);
