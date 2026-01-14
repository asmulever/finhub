import { authStore } from '../auth/authStore.js';

const toolbarTemplate = `
  <header class="toolbar">
    <div class="toolbar-logo">
      <a href="/Frontend/app.php">
        <img src="/logo/full_logoweb.png" alt="FinHub" style="height: 88px;" />
      </a>
    </div>
  <nav class="toolbar-menu">
      <button type="button" data-menu="portfolios" data-link="/Frontend/Portafolios.html">Portafolios</button>
      <button type="button" data-menu="heatmap" data-link="/Frontend/heatmap.html">Heatmap</button>
      <button type="button" id="stats-menu" data-menu="stats" data-link="/Frontend/estadistica.html">Estadística</button>
      <button type="button" id="datalake-menu" data-menu="datalake" data-link="/Frontend/datalake.html">DataLake</button>
      <select id="providers-select" aria-label="Proveedores">
        <option value="">Proveedores</option>
        <option value="/Frontend/eodhd.html">EODHD</option>
        <option value="/Frontend/twelvedata.html">TwelveData</option>
        <option value="/Frontend/alphavantage.html">Alpha Vantage</option>
        <option value="/Frontend/polygon.html">Polygon</option>
        <option value="/Frontend/tiingo.html">Tiingo</option>
        <option value="/Frontend/stooq.html">Stooq</option>
      </select>
      <button type="button" id="rava-menu" data-menu="rava" data-link="/Frontend/rava.html">RAVA</button>
      <button type="button" id="signals-menu" data-menu="signals" data-link="/Frontend/trader-consejero.html">Trader Consejero</button>
    </nav>
    <div class="toolbar-user">
      <button id="user-menu-button" type="button">
        <span id="user-name">Usuario</span>
        <span class="chevron">▾</span>
      </button>
    </div>
    <button class="toolbar-toggle" type="button" aria-expanded="true" aria-label="Ocultar barra" title="Ocultar barra">▲</button>
  </header>
  <div class="user-dropdown" id="user-dropdown">
    <button id="admin-users-action" type="button">Admin Usuarios</button>
    <button id="logout-action" type="button">Cerrar sesión</button>
  </div>
`;

let toolbarMounted = false;
const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

const ensureToolbarStyles = () => {
  if (document.getElementById('toolbar-collapsible-style')) return;
  const style = document.createElement('style');
  style.id = 'toolbar-collapsible-style';
  style.textContent = `
    .toolbar.has-toggle { position: sticky; top: 0; z-index: 15; padding-bottom: 28px; position: relative; }
    .toolbar-toggle {
      position: absolute;
      right: 12px;
      bottom: 6px;
      width: 36px;
      height: 36px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.45);
      background: rgba(15, 23, 42, 0.85);
      color: #e2e8f0;
      cursor: pointer;
      display: grid;
      place-items: center;
      box-shadow: 0 10px 30px rgba(0,0,0,0.35);
    }
    .toolbar-toggle:focus-visible { outline: 2px solid rgba(14, 165, 233, 0.6); outline-offset: 2px; }
    .toolbar.collapsed .toolbar-menu,
    .toolbar.collapsed .toolbar-user,
    .toolbar.collapsed .toolbar-logo { display: none !important; }
    .toolbar.collapsed { padding-bottom: 16px; }
  `;
  document.head.appendChild(style);
};

export const setAdminMenuVisibility = (profile) => {
  const adminButton = document.getElementById('admin-users-action');
  const datalakeButton = document.getElementById('datalake-menu');
  const signalsButton = document.getElementById('signals-menu');
  const ravaButton = document.getElementById('rava-menu');
  const providersSelect = document.getElementById('providers-select');
  const isAdmin = isAdminProfile(profile);
  if (adminButton) adminButton.hidden = !isAdmin;
  if (datalakeButton) datalakeButton.hidden = !isAdmin;
  if (ravaButton) ravaButton.hidden = !isAdmin;
  if (signalsButton) signalsButton.hidden = false;
  if (providersSelect) providersSelect.hidden = !isAdmin;
};

export const renderToolbar = () => {
  const container = document.getElementById('toolbar-root');
  if (!container || toolbarMounted) {
    return;
  }
  ensureToolbarStyles();
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

const updateToggleVisual = (toolbar, toggle) => {
  const collapsed = toolbar.classList.contains('collapsed');
  toggle.textContent = collapsed ? '▼' : '▲';
  toggle.setAttribute('aria-expanded', String(!collapsed));
  toggle.setAttribute('aria-label', collapsed ? 'Mostrar barra' : 'Ocultar barra');
  toggle.title = collapsed ? 'Mostrar barra' : 'Ocultar barra';
};

const bindToolbarToggle = () => {
  const toolbar = document.querySelector('.toolbar');
  const toggle = document.querySelector('.toolbar-toggle');
  if (!toolbar || !toggle) return;
  toolbar.classList.add('has-toggle');
  updateToggleVisual(toolbar, toggle);
  toggle.addEventListener('click', () => {
    toolbar.classList.toggle('collapsed');
    updateToggleVisual(toolbar, toggle);
  });
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
  bindToolbarToggle();
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
