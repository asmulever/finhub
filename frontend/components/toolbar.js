import { authStore } from '../auth/authStore.js';

const toolbarTemplate = `
  <header class="toolbar">
    <div class="toolbar-logo">
      <a href="/Frontend/app.html">
        <img src="/logo/full_logoweb.png" alt="FinHub" style="height: 88px;" />
      </a>
    </div>
  <nav class="toolbar-menu">
      <button type="button" data-menu="portfolios" data-link="/Frontend/Portafolios.php">Portafolios</button>
      <button type="button" id="datalake-menu" data-menu="datalake" data-link="/Frontend/datalake.php">DataLake</button>
      <select id="providers-select" aria-label="Proveedores">
        <option value="">Proveedores</option>
        <option value="/Frontend/eodhd.html">EODHD</option>
        <option value="/Frontend/twelvedata.html">TwelveData</option>
        <option value="/Frontend/alphavantage.html">Alpha Vantage</option>
        <option value="/Frontend/polygon.php">Polygon</option>
        <option value="/Frontend/tiingo.php">Tiingo</option>
        <option value="/Frontend/stooq.php">Stooq</option>
      </select>
      <button type="button" id="rava-menu" data-menu="rava" data-link="/Frontend/rava.php">RAVA</button>
      <button type="button" id="analysis-menu" data-menu="analysis" data-link="/Frontend/analisis_indicadores.php">Análisis</button>
    </nav>
    <div class="toolbar-user">
      <button id="user-menu-button" type="button">
        <span id="user-name">Usuario</span>
        <span class="chevron">▾</span>
      </button>
    </div>
  </header>
  <div class="user-dropdown" id="user-dropdown">
    <button id="admin-users-action" type="button">Admin Usuarios</button>
    <button id="logout-action" type="button">Cerrar sesión</button>
  </div>
`;

let toolbarMounted = false;
const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

export const setAdminMenuVisibility = (profile) => {
  const adminButton = document.getElementById('admin-users-action');
  const datalakeButton = document.getElementById('datalake-menu');
  const analysisButton = document.getElementById('analysis-menu');
  const providersSelect = document.getElementById('providers-select');
  const isAdmin = isAdminProfile(profile);
  if (adminButton) adminButton.hidden = !isAdmin;
  if (datalakeButton) datalakeButton.hidden = !isAdmin;
  if (analysisButton) analysisButton.hidden = !isAdmin;
  if (providersSelect) providersSelect.hidden = !isAdmin;
};

export const renderToolbar = () => {
  const container = document.getElementById('toolbar-root');
  if (!container || toolbarMounted) {
    return;
  }
  container.innerHTML = toolbarTemplate;
  toolbarMounted = true;
  const cachedProfile = authStore.getProfile();
  setAdminMenuVisibility(cachedProfile);
};

export const highlightToolbar = () => {
  const path = window.location.pathname;
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    const link = button.getAttribute('data-link');
    button.classList.toggle('active', link === path);
  });
  const providersSelect = document.getElementById('providers-select');
  if (providersSelect) {
    let matched = '';
    Array.from(providersSelect.options).forEach((opt) => {
      if (opt.value === path) {
        matched = opt.value;
      }
    });
    providersSelect.value = matched;
  }
};

export const bindToolbarNavigation = () => {
  const frame = document.getElementById('app-frame');
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    button.addEventListener('click', (event) => {
      const link = button.getAttribute('data-link');
      if (!link) return;
      if (frame) {
        event.preventDefault();
        if (frame.src !== link) {
          frame.src = link;
        }
        document.querySelectorAll('.toolbar-menu button').forEach((b) => b.classList.remove('active'));
        button.classList.add('active');
        return;
      }
      event.preventDefault();
      window.location.href = link;
    });
  });
  const providersSelect = document.getElementById('providers-select');
  if (providersSelect) {
    providersSelect.addEventListener('change', (event) => {
      const link = providersSelect.value;
      if (!link) return;
      if (frame) {
        event.preventDefault();
        if (frame.src !== link) {
          frame.src = link;
        }
        document.querySelectorAll('.toolbar-menu button').forEach((b) => b.classList.remove('active'));
        providersSelect.blur();
        return;
      }
      window.location.href = link;
    });
  }
};

export const setToolbarUserName = (name) => {
  const label = document.getElementById('user-name');
  if (!label) return;
  label.textContent = name && name.length ? name : 'Usuario';
};

export const bindUserMenu = ({ onLogout, onAdmin, profile } = {}) => {
  const menuButton = document.getElementById('user-menu-button');
  const dropdown = document.getElementById('user-dropdown');
  if (!menuButton || !dropdown) return;
  setAdminMenuVisibility(profile ?? authStore.getProfile());

  menuButton.addEventListener('click', () => {
    dropdown.classList.toggle('visible');
  });

  document.addEventListener('click', (event) => {
    if (!menuButton.contains(event.target) && !dropdown.contains(event.target)) {
      dropdown.classList.remove('visible');
    }
  });

  const adminButton = document.getElementById('admin-users-action');
  const logoutButton = document.getElementById('logout-action');
  adminButton?.addEventListener('click', (event) => {
    event.preventDefault();
    dropdown.classList.remove('visible');
    if (typeof onAdmin === 'function') {
      onAdmin();
    }
  });
  logoutButton?.addEventListener('click', (event) => {
    event.preventDefault();
    dropdown.classList.remove('visible');
    if (typeof onLogout === 'function') {
      onLogout();
    }
  });
};
