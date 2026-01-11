import { deleteJson, getJson, patchJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setToolbarUserName } from '../components/toolbar.js';

const state = {
  users: [],
  loading: false,
  error: '',
  profile: null,
  isAdmin: false,
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

const normalizeUsers = (payload) => {
  if (Array.isArray(payload)) {
    return payload;
  }
  if (Array.isArray(payload?.data)) {
    return payload.data;
  }
  return [];
};

const buildOptions = (current, options) => {
  const unique = new Set([current, ...options].filter(Boolean));
  return Array.from(unique).map((value) => `
    <option value="${value}" ${value === current ? 'selected' : ''}>${value}</option>
  `).join('');
};

const renderUsers = () => {
  const container = document.getElementById('users-container');
  if (!container) return;
  if (!state.isAdmin) {
    container.innerHTML = '<p class="price-error">Acceso restringido: usuario sin permisos de administrador.</p>';
    return;
  }
  if (state.loading) {
    container.innerHTML = '<p class="muted">Cargando usuarios...</p>';
    return;
  }
  if (state.error) {
    container.innerHTML = `<p class="price-error">${state.error}</p>`;
    return;
  }
  if (state.users.length === 0) {
    container.innerHTML = '<p class="muted">No hay usuarios registrados.</p>';
    return;
  }
  const roles = ['admin', 'user'];
  const statuses = ['active', 'inactive', 'disabled'];
  const rows = state.users.map((user) => `
    <article class="user-row" data-id="${user.id}">
      <div class="user-main">
        <strong>${user.email ?? 'Sin email'}</strong>
        <span class="user-meta">ID ${user.id ?? 'N/D'}</span>
      </div>
      <div class="user-controls">
        <label>
          Rol
          <select data-field="role">
            ${buildOptions(user.role, roles)}
          </select>
        </label>
        <label>
          Estado
          <select data-field="status">
            ${buildOptions(user.status, statuses)}
          </select>
        </label>
        <div class="user-actions">
          <button class="ghost" type="button" data-action="save">Guardar</button>
          <button class="danger" type="button" data-action="delete">Eliminar</button>
        </div>
      </div>
    </article>
  `);
  container.innerHTML = rows.join('');
};

const fetchUsers = async () => {
  state.loading = true;
  state.error = '';
  renderUsers();
  try {
    const payload = await getJson('/users');
    state.users = normalizeUsers(payload);
  } catch (error) {
    state.users = [];
    state.error = error?.error?.message ?? 'No se pudo obtener la lista de usuarios';
  } finally {
    state.loading = false;
    renderUsers();
  }
};

const setFormMessage = (message, isError = false) => {
  const messageNode = document.getElementById('user-form-message');
  if (!messageNode) return;
  messageNode.textContent = message;
  messageNode.classList.toggle('price-error', isError);
  messageNode.classList.toggle('muted', !isError);
};

const handleCreateUser = async (event) => {
  event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);
  const payload = {
    email: String(formData.get('email') ?? '').trim(),
    password: String(formData.get('password') ?? ''),
    role: String(formData.get('role') ?? '').trim(),
    status: String(formData.get('status') ?? '').trim(),
  };
  if (!payload.email || !payload.password || !payload.role) {
    setFormMessage('Completa email, contraseña y rol.', true);
    return;
  }
  try {
    setFormMessage('Creando usuario...');
    await postJson('/users', payload);
    form.reset();
    setFormMessage('Usuario creado correctamente.');
    await fetchUsers();
  } catch (error) {
    setFormMessage(error?.error?.message ?? 'No se pudo crear el usuario', true);
  }
};

const handleRowAction = async (event) => {
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const row = button.closest('.user-row');
  if (!row) return;
  const userId = row.getAttribute('data-id');
  if (!userId) return;
  const roleSelect = row.querySelector('select[data-field="role"]');
  const statusSelect = row.querySelector('select[data-field="status"]');
  if (button.dataset.action === 'save') {
    try {
      button.disabled = true;
      await patchJson(`/users/${userId}`, {
        role: roleSelect?.value ?? '',
        status: statusSelect?.value ?? '',
      });
      await fetchUsers();
    } catch (error) {
      state.error = error?.error?.message ?? 'No se pudo actualizar el usuario';
      renderUsers();
    } finally {
      button.disabled = false;
    }
  }
  if (button.dataset.action === 'delete') {
    const confirmed = window.confirm('¿Eliminar este usuario y todos sus datos (portafolios e instrumentos)?');
    if (!confirmed) return;
    try {
      button.disabled = true;
      await deleteJson(`/users/${userId}`);
      await fetchUsers();
    } catch (error) {
      state.error = error?.error?.message ?? 'No se pudo eliminar en cascada';
      renderUsers();
    } finally {
      button.disabled = false;
    }
  }
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const init = () => {
  renderToolbar();
  bindToolbarNavigation();
  highlightToolbar();
  bindUserMenu({
    onLogout: handleLogout,
    onAdmin: () => {
      window.location.href = '/Frontend/usuarios.html';
    },
  });

  state.profile = authStore.getProfile();
  state.isAdmin = isAdminProfile(state.profile);
  setToolbarUserName(state.profile?.email ?? '');

  const form = document.getElementById('user-form');
  if (!state.isAdmin) {
    form?.remove();
    renderUsers();
    return;
  }
  form?.addEventListener('submit', handleCreateUser);

  const container = document.getElementById('users-container');
  container?.addEventListener('click', handleRowAction);
  fetchUsers();
};

document.addEventListener('DOMContentLoaded', init);
