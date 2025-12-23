const toolbarTemplate = `
  <header class="toolbar">
    <div class="toolbar-logo">
      <img src="/logo/full_logoweb.png" alt="FinHub" />
    </div>
    <nav class="toolbar-menu">
      <button type="button" data-menu="overview" data-link="/Frontend/Dashboard.html">Resumen</button>
      <button type="button" data-menu="portfolios" data-link="/Frontend/Portafolios.html">Portafolios</button>
      <button type="button" data-menu="prices" data-link="/Frontend/precios.html">Precios</button>
    </nav>
    <div class="toolbar-user">
      <button id="user-menu-button" type="button">
        <span id="user-name">Usuario</span>
        <span class="chevron">▾</span>
      </button>
    </div>
  </header>
  <div class="user-dropdown" id="user-dropdown">
    <button id="abm-clientes-action" type="button">ABM de clientes</button>
    <button id="logout-action" type="button">Cerrar sesión</button>
  </div>
`;

let toolbarMounted = false;

export const renderToolbar = () => {
  const container = document.getElementById('toolbar-root');
  if (!container || toolbarMounted) {
    return;
  }
  container.innerHTML = toolbarTemplate;
  toolbarMounted = true;
};

export const highlightToolbar = () => {
  const path = window.location.pathname;
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    const link = button.getAttribute('data-link');
    button.classList.toggle('active', link === path);
  });
};

export const bindToolbarNavigation = () => {
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    button.addEventListener('click', (event) => {
      const link = button.getAttribute('data-link');
      if (!link) return;
      event.preventDefault();
      if (link === window.location.pathname) {
        highlightToolbar();
        return;
      }
      window.location.href = link;
    });
  });
};

export const setToolbarUserName = (name) => {
  const label = document.getElementById('user-name');
  if (!label) return;
  label.textContent = name && name.length ? name : 'Usuario';
};

export const bindUserMenu = ({ onLogout, onAbm } = {}) => {
  const menuButton = document.getElementById('user-menu-button');
  const dropdown = document.getElementById('user-dropdown');
  if (!menuButton || !dropdown) return;

  menuButton.addEventListener('click', () => {
    dropdown.classList.toggle('visible');
  });

  document.addEventListener('click', (event) => {
    if (!menuButton.contains(event.target) && !dropdown.contains(event.target)) {
      dropdown.classList.remove('visible');
    }
  });

  const abmButton = document.getElementById('abm-clientes-action');
  const logoutButton = document.getElementById('logout-action');
  abmButton?.addEventListener('click', (event) => {
    event.preventDefault();
    dropdown.classList.remove('visible');
    if (typeof onAbm === 'function') {
      onAbm();
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
