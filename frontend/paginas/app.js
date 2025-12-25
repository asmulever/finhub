import { authStore } from '../auth/authStore.js';
import { getJson } from '../apicliente.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const setFrameSrc = (src) => {
  const frame = document.getElementById('app-frame');
  if (frame && frame.src !== src) {
    frame.src = src;
  }
};

const loadProfile = async () => {
  try {
    const profile = await getJson('/me');
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const init = async () => {
  renderToolbar();
  bindToolbarNavigation();
  bindUserMenu({
    onLogout: () => {
      authStore.clearToken();
      window.location.href = '/';
    },
    onAdmin: () => setFrameSrc('/Frontend/usuarios.html'),
    profile: authStore.getProfile(),
  });
  highlightToolbar();
  await loadProfile();
  setFrameSrc('/Frontend/dashboard.html');
};

document.addEventListener('DOMContentLoaded', init);
