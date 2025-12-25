import { authStore } from '../auth/authStore.js';
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
    return;
    const cached = authStore.getProfile();
    setToolbarUserName(cached?.email ?? '');
    setAdminMenuVisibility(cached);
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
