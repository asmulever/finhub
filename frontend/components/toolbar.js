import { authStore } from '../auth/authStore.js';

const toolbarTemplate = `
  <header class="toolbar">
    <div class="toolbar-logo">
      <a href="/Frontend/app.php">
        <img src="/logo/full_logoweb.png" alt="FinHub" style="height: 88px;" />
      </a>
    </div>
  <nav class="toolbar-menu">
      <button type="button" class="icon-btn" data-menu="portfolios" data-link="/Frontend/Portafolios.html" aria-label="Portafolios">
        <span class="circle-icon">👜</span>
      </button>
      <button type="button" class="icon-btn" data-menu="heatmap" data-link="/Frontend/heatmap.html" aria-label="Heatmap">
        <span class="circle-icon">🌡️</span>
      </button>
      <button type="button" class="icon-btn" id="stats-menu" data-menu="stats" data-link="/Frontend/estadistica.html" aria-label="Estadística">
        <span class="circle-icon">📊</span>
      </button>
      <button type="button" class="icon-btn" id="signals-menu" data-menu="signals" data-link="/Frontend/trader-consejero.html" aria-label="Trader Consejero">
        <span class="circle-icon">💹</span>
      </button>
      <button type="button" class="icon-btn" id="oracle-menu" data-menu="oracle" data-link="/Frontend/oraculo.html" aria-label="Oráculo">
        <span class="circle-icon">🔮</span>
      </button>
      <button type="button" class="icon-btn" id="radar-menu" data-menu="radar" data-link="/Frontend/radar.html" aria-label="Radar">
        <span class="circle-icon">📡</span>
      </button>
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
    #toolbar-root > header.toolbar {
      padding: 10px 56px 10px 12px;
      gap: 10px;
    }
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
    /* Cuando la barra está colapsada, dejamos el botón sobresalir >50% hacia abajo */
    .toolbar.collapsed .toolbar-toggle {
      bottom: -20px;
    }
    .toolbar-toggle:focus-visible { outline: 2px solid rgba(14, 165, 233, 0.6); outline-offset: 2px; }
    .toolbar.collapsed .toolbar-menu,
    .toolbar.collapsed .toolbar-user,
    .toolbar.collapsed .toolbar-logo { display: none !important; }
    .toolbar.collapsed { padding-bottom: 16px; }
    .toolbar-logo img { border: none; outline: none; box-shadow: none; margin: 0; padding: 0; height: 72px; }
    .toolbar-menu .icon-btn {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      border: none;
      box-shadow: none;
      background: rgba(15, 23, 42, 0.9);
    }
    .circle-icon { font-size: 1.1rem; line-height: 1; }
    .toolbar-menu button,
    .toolbar-menu select {
      margin: 0;
      border: none;
      box-shadow: none;
    }
    @media (max-width: 768px) {
      .toolbar {
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-auto-rows: auto;
        align-items: center;
        gap: 6px 10px;
        padding: 8px 56px 28px 12px;
      }
      .toolbar-logo {
        grid-column: 1 / 2;
        grid-row: 1;
        display: flex;
        align-items: center;
        margin: 0;
      }
      .toolbar-user {
        grid-column: 2 / 3;
        grid-row: 1;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        min-width: 0;
        margin: 0;
      }
      .toolbar-menu {
        grid-column: 1 / 3;
        grid-row: 2;
        width: 100%;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        justify-content: flex-start;
        margin-top: 4px;
      }
      .toolbar-menu button,
      .toolbar-menu select {
        padding: 8px 10px;
        min-width: auto;
        margin: 0;
        border: none;
        box-shadow: none;
      }
    }
  `;
  document.head.appendChild(style);
};

export const setAdminMenuVisibility = (profile) => {
  const adminButton = document.getElementById('admin-users-action');
  const signalsButton = document.getElementById('signals-menu');
  const isAdmin = isAdminProfile(profile);
  if (adminButton) adminButton.hidden = !isAdmin;
  if (signalsButton) signalsButton.hidden = false;
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
};

const updateToggleVisual = (toolbar, toggle) => {
  const collapsed = toolbar.classList.contains('collapsed');
  toggle.textContent = collapsed ? '▼' : '▲';
  toggle.setAttribute('aria-expanded', String(!collapsed));
  toggle.setAttribute('aria-label', collapsed ? 'Mostrar barra' : 'Ocultar barra');
  toggle.title = collapsed ? 'Mostrar barra' : 'Ocultar barra';
};

export const collapseToolbar = () => {
  const toolbar = document.querySelector('.toolbar');
  if (!toolbar) return;
  toolbar.classList.add('collapsed');
  const toggle = document.querySelector('.toolbar-toggle');
  if (toggle) {
    updateToggleVisual(toolbar, toggle);
  }
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
