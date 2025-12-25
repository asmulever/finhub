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
    const response = await fetch('/api/me', {
      headers: authStore.getToken() ? { Authorization: `Bearer ${authStore.getToken()}` } : {},
      credentials: 'include',
    });
    if (!response.ok) throw new Error('no auth');
    const profile = await response.json();
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch {
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
