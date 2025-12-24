import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { bindToolbarNavigation, bindUserMenu, highlightToolbar, renderToolbar, setAdminMenuVisibility, setToolbarUserName } from '../components/toolbar.js';

const state = {
  symbols: [],
  selectedSymbols: [],
  period: '1m',
  chart: null,
  collecting: false,
  profile: null,
};

const isAdminProfile = (profile) => String(profile?.role ?? '').toLowerCase() === 'admin';

const colors = [
  '#0ea5e9', '#22d3ee', '#a78bfa', '#f472b6', '#f59e0b',
  '#10b981', '#ef4444', '#6366f1', '#14b8a6', '#f97316',
];

const renderSymbols = () => {
  const select = document.getElementById('symbol-select');
  if (!select) return;
  select.innerHTML = state.symbols.map((s) => `<option value="${s}" ${state.selectedSymbols.includes(s) ? 'selected' : ''}>${s}</option>`).join('');
};

const updateChart = (series) => {
  const ctx = document.getElementById('prices-chart');
  if (!ctx) return;
  const datasets = series.map((serie, idx) => ({
    label: serie.symbol,
    data: serie.points.map((p) => ({ x: p.t, y: p.price })),
    borderColor: colors[idx % colors.length],
    backgroundColor: colors[idx % colors.length],
    tension: 0.2,
  }));

  if (state.chart) {
    state.chart.data.datasets = datasets;
    state.chart.update();
    return;
  }

  state.chart = new Chart(ctx, {
    type: 'line',
    data: { datasets },
    options: {
      responsive: true,
      scales: {
        x: { type: 'time', time: { tooltipFormat: 'yyyy-MM-dd HH:mm' }, ticks: { color: '#cbd5f5' } },
        y: { ticks: { color: '#cbd5f5' } },
      },
      plugins: {
        legend: { labels: { color: '#cbd5f5' } },
      },
    },
  });
};

const fetchSymbols = async () => {
  const response = await getJson('/datalake/prices/symbols');
  state.symbols = Array.isArray(response?.symbols) ? response.symbols : [];
  state.selectedSymbols = [...state.symbols];
  renderSymbols();
};

const fetchSeries = async () => {
  if (!state.selectedSymbols.length) {
    updateChart([]);
    return;
  }
  const series = [];
  for (const symbol of state.selectedSymbols) {
    // eslint-disable-next-line no-await-in-loop
    const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(symbol)}&period=${encodeURIComponent(state.period)}`);
    series.push(resp);
  }
  updateChart(series);
};

const handleCollect = async () => {
  if (state.collecting) return;
  state.collecting = true;
  const btn = document.getElementById('collect-btn');
  const result = document.getElementById('collect-result');
  if (btn) btn.disabled = true;
  if (result) result.textContent = 'Recolectando...';
  try {
    const response = await postJson('/datalake/prices/collect', {});
    if (result) {
      result.textContent = `OK: ${response.ok} | Fallidos: ${response.failed} | Total símbolos: ${response.total_symbols}`;
    }
    await fetchSeries();
  } catch (error) {
    if (result) {
      result.textContent = `Error al recolectar: ${error?.error?.message ?? 'Desconocido'}`;
    }
  } finally {
    state.collecting = false;
    if (btn) btn.disabled = false;
  }
};

const handleSymbolChange = (event) => {
  const options = Array.from(event.target.selectedOptions || []);
  state.selectedSymbols = options.map((opt) => opt.value);
  fetchSeries();
};

const handlePeriodChange = (event) => {
  state.period = event.target.value;
  fetchSeries();
};

const handleLogout = async () => {
  try {
    await postJson('/auth/logout');
  } finally {
    authStore.clearToken();
    window.location.href = '/';
  }
};

const loadProfile = async () => {
  try {
    const profile = await getJson('/me');
    state.profile = profile;
    setToolbarUserName(profile?.email ?? '');
    setAdminMenuVisibility(profile);
  } catch (error) {
    state.profile = null;
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? '');
    setAdminMenuVisibility(cachedProfile);
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
    profile: authStore.getProfile(),
  });
  bindToolbarNavigation();
  highlightToolbar();
  await loadProfile();
  if (!isAdminProfile(state.profile ?? authStore.getProfile())) {
    const result = document.getElementById('collect-result');
    if (result) result.textContent = 'Acceso restringido: solo Admin puede usar DataLake.';
    document.getElementById('collect-btn')?.setAttribute('disabled', 'disabled');
    return;
  }
  await fetchSymbols();
  await fetchSeries();

  document.getElementById('collect-btn')?.addEventListener('click', handleCollect);
  document.getElementById('symbol-select')?.addEventListener('change', handleSymbolChange);
  document.getElementById('period-select')?.addEventListener('change', handlePeriodChange);
};

document.addEventListener('DOMContentLoaded', init);
