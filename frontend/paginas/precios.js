import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';

const symbols = ['AAPL', 'MSFT', 'GOOGL', 'TSLA', 'AMZN'];
const state = {
  profile: null,
  quotes: [],
};

const formatCurrency = (value) => {
  if (typeof value !== 'number') {
    return '---';
  }
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(value);
};

const buildTile = (quote) => {
  if (quote.error) {
    return `
      <article class="price-tile">
        <div class="price-badge">${quote.symbol}</div>
        <strong>${quote.symbol}</strong>
        <span class="price-error">${quote.error.message ?? 'No disponible'}</span>
      </article>
    `;
  }
  return `
    <article class="price-tile">
      <div class="price-badge">${quote.source ?? 'Mercado'}</div>
      <strong>${quote.symbol}</strong>
      <div class="price-meta">
        <span>${formatCurrency(quote.close)}</span>
        <small>${new Date(quote.asOf).toLocaleString()}</small>
      </div>
      <div class="price-meta">
        <span>Máx ${formatCurrency(quote.high ?? quote.close)}</span>
        <span>Mín ${formatCurrency(quote.low ?? quote.close)}</span>
      </div>
    </article>
  `;
};

const renderQuotes = () => {
  const container = document.getElementById('prices-content');
  if (!container) return;
  if (state.quotes.length === 0) {
    container.innerHTML = '<p class="muted">Aún no hay cotizaciones disponibles</p>';
    return;
  }
  container.innerHTML = state.quotes.map(buildTile).join('');
};

const fetchQuotes = async () => {
  const results = await Promise.allSettled(
    symbols.map((symbol) => getJson(`/quotes?symbol=${symbol}`))
  );
  state.quotes = results.map((result, index) => {
    if (result.status === 'fulfilled') {
      return result.value;
    }
    return {
      symbol: symbols[index],
      error: {
        message: result.reason?.error?.message ?? 'No disponible',
      },
    };
  });
};

const refreshPrices = async () => {
  const container = document.getElementById('prices-content');
  if (container) {
    container.innerHTML = '<p class="muted">Actualizando cotizaciones...</p>';
  }
  try {
    await fetchQuotes();
  } catch (error) {
    state.quotes = symbols.map((symbol) => ({
      symbol,
      error: { message: 'No se pudo obtener el precio' },
    }));
  }
  renderQuotes();
};

const updateUserMenu = () => {
  const nameField = document.getElementById('user-name');
  if (nameField && state.profile) {
    nameField.textContent = `${state.profile.email}`;
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

const setupUserMenu = () => {
  const button = document.getElementById('user-menu-button');
  const dropdown = document.getElementById('user-dropdown');
  button?.addEventListener('click', () => {
    dropdown?.classList.toggle('visible');
  });
  document.addEventListener('click', (event) => {
    if (!button?.contains(event.target) && !dropdown?.contains(event.target)) {
      dropdown?.classList.remove('visible');
    }
  });
  document.getElementById('logout-action')?.addEventListener('click', () => {
    dropdown?.classList.remove('visible');
    handleLogout();
  });
  document.getElementById('abm-clientes-action')?.addEventListener('click', () => {
    dropdown?.classList.remove('visible');
    window.location.href = '/Frontend/Dashboard.html';
  });
};

const highlightToolbar = () => {
  const path = window.location.pathname;
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    button.classList.toggle('active', button.getAttribute('data-link') === path);
  });
};

const setupToolbarNavigation = () => {
  document.querySelectorAll('.toolbar-menu button').forEach((button) => {
    button.addEventListener('click', () => {
      const link = button.getAttribute('data-link');
      if (!link) return;
      if (link === window.location.pathname) {
        highlightToolbar();
        return;
      }
      window.location.href = link;
    });
  });
};

const loadProfile = async () => {
  try {
    state.profile = await getJson('/me');
    updateUserMenu();
  } catch (error) {
    state.profile = null;
  }
};

const init = () => {
  setupUserMenu();
  setupToolbarNavigation();
  highlightToolbar();
  refreshPrices();
  loadProfile();
  document.getElementById('refresh-prices')?.addEventListener('click', refreshPrices);
};

document.addEventListener('DOMContentLoaded', init);
