// Frontend/auth/authStore.js
var TOKEN_KEY = "finhub_token";
var TOKEN_EXP_KEY = "finhub_token_exp";
var PROFILE_KEY = "finhub_profile";
var storage = sessionStorage;
var nowSeconds = () => Math.floor(Date.now() / 1e3);
var authStore = {
  getToken() {
    const exp = Number(storage.getItem(TOKEN_EXP_KEY) || 0);
    if (exp && nowSeconds() >= exp) {
      this.clearToken();
      return null;
    }
    return storage.getItem(TOKEN_KEY);
  },
  setToken(token, expiresInSeconds = 3600) {
    storage.setItem(TOKEN_KEY, token);
    storage.setItem(TOKEN_EXP_KEY, String(nowSeconds() + expiresInSeconds));
  },
  clearToken() {
    storage.removeItem(TOKEN_KEY);
    storage.removeItem(TOKEN_EXP_KEY);
    storage.removeItem(PROFILE_KEY);
  },
  setProfile(profile) {
    storage.setItem(PROFILE_KEY, JSON.stringify(profile));
  },
  getProfile() {
    const value = storage.getItem(PROFILE_KEY);
    return value ? JSON.parse(value) : null;
  }
};

// Frontend/apicliente.js
var getBaseUrl = /* @__PURE__ */ (() => {
  let cached = "";
  return () => {
    if (cached !== "") {
      return cached;
    }
    const envBase = window.__ENV?.API_BASE_URL;
    if (!envBase) {
      throw new Error("API_BASE_URL no est\xE1 configurada en window.__ENV");
    }
    cached = envBase.endsWith("/") ? envBase.slice(0, -1) : envBase;
    return cached;
  };
})();
var buildUrl = (path) => path.startsWith("http") ? path : `${getBaseUrl()}${path}`;
var handleUnauthorized = () => {
  authStore.clearToken();
  const target = "/";
  if (window.top && window.top !== window) {
    window.top.location.href = target;
  } else {
    window.location.href = target;
  }
};
var buildHeaders = (extraHeaders) => ({
  "Content-Type": "application/json",
  ...extraHeaders
});
var parsePayload = async (response) => response.json().catch(() => null);
var apiClient = async (path, options = {}) => {
  const { method = "GET", body, headers = {}, ...rest } = options;
  const composedHeaders = buildHeaders(headers);
  const token = authStore.getToken() ?? localStorage.getItem("jwt");
  if (token) {
    composedHeaders.Authorization = `Bearer ${token}`;
  }
  const response = await fetch(buildUrl(path), {
    method,
    credentials: "include",
    headers: composedHeaders,
    body: body === void 0 ? void 0 : JSON.stringify(body),
    ...rest
  });
  const payload = await parsePayload(response);
  if (!response.ok) {
    if (response.status === 401) {
      handleUnauthorized();
    }
    const err = payload?.error ?? {
      code: `http.${response.status}`,
      message: response.statusText
    };
    throw { status: response.status, error: err };
  }
  return payload;
};
var withBody = (method) => (path, body) => apiClient(path, { method, body });
var withoutBody = (method) => (path) => apiClient(path, { method });
var postJson = withBody("POST");
var patchJson = withBody("PATCH");
var deleteJson = withoutBody("DELETE");
var getJson = withoutBody("GET");

// Frontend/components/toolbar.js
var toolbarTemplate = `
  <header class="toolbar">
    <div class="toolbar-logo">
      <a href="/Frontend/app.php">
        <img src="/logo/full_logoweb.png" alt="FinHub" style="height: 88px;" />
      </a>
    </div>
  <nav class="toolbar-menu">
      <button type="button" class="icon-btn" data-menu="portfolios" data-link="/Frontend/Portafolios.html" aria-label="Portafolios">
        <span class="circle-icon">\u{1F45C}</span>
      </button>
      <button type="button" class="icon-btn" data-menu="heatmap" data-link="/Frontend/heatmap.html" aria-label="Heatmap">
        <span class="circle-icon">\u{1F321}\uFE0F</span>
      </button>
      <button type="button" class="icon-btn" id="stats-menu" data-menu="stats" data-link="/Frontend/estadistica.html" aria-label="Estad\xEDstica">
        <span class="circle-icon">\u{1F4CA}</span>
      </button>
      <button type="button" class="icon-btn" id="signals-menu" data-menu="signals" data-link="/Frontend/trader-consejero.html" aria-label="Trader Consejero">
        <span class="circle-icon">\u{1F4B9}</span>
      </button>
      <button type="button" class="icon-btn" id="oracle-menu" data-menu="oracle" data-link="/Frontend/oraculo.html" aria-label="Or\xE1culo">
        <span class="circle-icon">\u{1F52E}</span>
      </button>
      <button type="button" class="icon-btn" id="radar-menu" data-menu="radar" data-link="/Frontend/radar.html" aria-label="Radar">
        <span class="circle-icon">\u{1F4E1}</span>
      </button>
    </nav>
    <div class="toolbar-user">
      <button id="user-menu-button" type="button">
        <span id="user-name">Usuario</span>
        <span class="chevron">\u25BE</span>
      </button>
    </div>
    <button class="toolbar-toggle" type="button" aria-expanded="true" aria-label="Ocultar barra" title="Ocultar barra">\u25B2</button>
  </header>
  <div class="user-dropdown" id="user-dropdown">
    <button id="admin-users-action" type="button">Admin Usuarios</button>
    <button id="logout-action" type="button">Cerrar sesi\xF3n</button>
  </div>
`;
var toolbarMounted = false;
var isAdminProfile = (profile) => String(profile?.role ?? "").toLowerCase() === "admin";
var ensureToolbarStyles = () => {
  if (document.getElementById("toolbar-collapsible-style"))
    return;
  const style = document.createElement("style");
  style.id = "toolbar-collapsible-style";
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
    /* Cuando la barra est\xE1 colapsada, dejamos el bot\xF3n sobresalir >50% hacia abajo */
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
var setAdminMenuVisibility = (profile) => {
  const adminButton = document.getElementById("admin-users-action");
  const signalsButton = document.getElementById("signals-menu");
  const isAdmin = isAdminProfile(profile);
  if (adminButton)
    adminButton.hidden = !isAdmin;
  if (signalsButton)
    signalsButton.hidden = false;
};
var renderToolbar = () => {
  const container = document.getElementById("toolbar-root");
  if (!container || toolbarMounted) {
    return;
  }
  ensureToolbarStyles();
  container.innerHTML = toolbarTemplate;
  toolbarMounted = true;
  const cachedProfile = authStore.getProfile();
  setAdminMenuVisibility(cachedProfile);
};
var highlightToolbar = () => {
  const path = window.location.pathname;
  document.querySelectorAll(".toolbar-menu button").forEach((button) => {
    const link = button.getAttribute("data-link");
    button.classList.toggle("active", link === path);
  });
};
var updateToggleVisual = (toolbar, toggle) => {
  const collapsed = toolbar.classList.contains("collapsed");
  toggle.textContent = collapsed ? "\u25BC" : "\u25B2";
  toggle.setAttribute("aria-expanded", String(!collapsed));
  toggle.setAttribute("aria-label", collapsed ? "Mostrar barra" : "Ocultar barra");
  toggle.title = collapsed ? "Mostrar barra" : "Ocultar barra";
};
var bindToolbarToggle = () => {
  const toolbar = document.querySelector(".toolbar");
  const toggle = document.querySelector(".toolbar-toggle");
  if (!toolbar || !toggle)
    return;
  toolbar.classList.add("has-toggle");
  updateToggleVisual(toolbar, toggle);
  toggle.addEventListener("click", () => {
    toolbar.classList.toggle("collapsed");
    updateToggleVisual(toolbar, toggle);
  });
};
var bindToolbarNavigation = () => {
  const frame = document.getElementById("app-frame");
  document.querySelectorAll(".toolbar-menu button").forEach((button) => {
    button.addEventListener("click", (event) => {
      const link = button.getAttribute("data-link");
      if (!link)
        return;
      if (frame) {
        event.preventDefault();
        if (frame.src !== link) {
          frame.src = link;
        }
        document.querySelectorAll(".toolbar-menu button").forEach((b) => b.classList.remove("active"));
        button.classList.add("active");
        return;
      }
      event.preventDefault();
      window.location.href = link;
    });
  });
  bindToolbarToggle();
};
var setToolbarUserName = (name) => {
  const label = document.getElementById("user-name");
  if (!label)
    return;
  label.textContent = name && name.length ? name : "Usuario";
};
var bindUserMenu = ({ onLogout, onAdmin, profile } = {}) => {
  const menuButton = document.getElementById("user-menu-button");
  const dropdown = document.getElementById("user-dropdown");
  if (!menuButton || !dropdown)
    return;
  setAdminMenuVisibility(profile ?? authStore.getProfile());
  menuButton.addEventListener("click", () => {
    dropdown.classList.toggle("visible");
  });
  document.addEventListener("click", (event) => {
    if (!menuButton.contains(event.target) && !dropdown.contains(event.target)) {
      dropdown.classList.remove("visible");
    }
  });
  const adminButton = document.getElementById("admin-users-action");
  const logoutButton = document.getElementById("logout-action");
  adminButton?.addEventListener("click", (event) => {
    event.preventDefault();
    dropdown.classList.remove("visible");
    if (typeof onAdmin === "function") {
      onAdmin();
    }
  });
  logoutButton?.addEventListener("click", (event) => {
    event.preventDefault();
    dropdown.classList.remove("visible");
    if (typeof onLogout === "function") {
      onLogout();
    }
  });
};

// Frontend/components/loadingOverlay.js
var STYLE_ID = "app-loading-overlay-style";
var injectStyles = () => {
  if (document.getElementById(STYLE_ID))
    return;
  const style = document.createElement("style");
  style.id = STYLE_ID;
  style.textContent = `
    .app-loading-overlay {
      position: fixed;
      inset: 0;
      background: rgba(3, 7, 18, 0.78);
      backdrop-filter: blur(2px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.18s ease;
    }
    .app-loading-overlay.visible {
      opacity: 1;
      pointer-events: all;
    }
    .app-loading-spinner {
      width: 70px;
      height: 70px;
      border-radius: 50%;
      border: 6px solid rgba(148, 163, 184, 0.35);
      border-top-color: #22d3ee;
      animation: app-spin 0.9s linear infinite;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    }
    @keyframes app-spin {
      to { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);
};
var createLoadingOverlay = () => {
  injectStyles();
  const overlay2 = document.createElement("div");
  overlay2.className = "app-loading-overlay";
  const spinner = document.createElement("div");
  spinner.className = "app-loading-spinner";
  overlay2.appendChild(spinner);
  const state2 = { counter: 0 };
  const show = () => {
    try {
      state2.counter += 1;
      if (!overlay2.isConnected) {
        document.body.appendChild(overlay2);
      }
      overlay2.classList.add("visible");
    } catch (error) {
      console.info("[loadingOverlay] No se pudo mostrar overlay", error);
    }
  };
  const hide = () => {
    try {
      state2.counter = Math.max(0, state2.counter - 1);
      if (state2.counter === 0) {
        overlay2.classList.remove("visible");
      }
    } catch (error) {
      console.info("[loadingOverlay] No se pudo ocultar overlay", error);
    }
  };
  const withLoader = async (fn) => {
    show();
    try {
      return await fn();
    } catch (error) {
      console.info("[loadingOverlay] Error durante operaci\xF3n envuelta", error);
      throw error;
    } finally {
      hide();
    }
  };
  return { show, hide, withLoader };
};

// Frontend/paginas/heatmap.js
var overlay = createLoadingOverlay();
var state = {
  data: null,
  currency: "ARS"
};
var setError = (msg) => {
  const el = document.getElementById("heatmap-error");
  if (el)
    el.textContent = msg || "";
};
var colorForChange = (pct) => {
  const clamped = Math.max(-5, Math.min(5, Number.isFinite(pct) ? pct : 0));
  const norm = (clamped + 5) / 10;
  const r = clamped < 0 ? 239 : Math.round(15 + (34 - 15) * norm);
  const g = clamped < 0 ? 68 : Math.round(23 + (197 - 23) * norm);
  const b = clamped < 0 ? 68 : Math.round(42 + (94 - 42) * norm);
  return `rgb(${r},${g},${b})`;
};
var formatNumber = (value, digits = 2) => {
  if (!Number.isFinite(Number(value)))
    return "\u2014";
  return new Intl.NumberFormat("es-AR", { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
};
var formatPercent = (value) => {
  if (!Number.isFinite(Number(value)))
    return "\u2014";
  return `${Number(value) >= 0 ? "+" : ""}${formatNumber(value, 2)}%`;
};
var renderGrid = () => {
  const grid = document.getElementById("heatmap-grid");
  const meta = document.getElementById("heatmap-meta");
  if (!grid || !state.data) {
    console.info("[heatmap] sin grid o sin datos a\xFAn");
    return;
  }
  const groups = Array.isArray(state.data.groups) ? state.data.groups : [];
  if (groups.length === 0) {
    console.warn("[heatmap] groups vac\xEDo", state.data);
    grid.innerHTML = '<p class="muted">Sin datos para el heatmap.</p>';
    if (meta)
      meta.textContent = "Sin datos";
    return;
  }
  try {
    console.table(groups.map((g) => ({
      sector: g.sector,
      industry: g.industry,
      items: (g.items ?? []).length
    })));
  } catch {
  }
  const items = groups.flatMap((g) => (g.items ?? []).map((i) => ({
    ...i,
    sector: g.sector,
    industry: g.industry
  })));
  try {
    console.table(items.map((i) => ({
      symbol: i.symbol,
      mv: i.market_value,
      change: i.change_pct_d,
      sector: i.sector,
      industry: i.industry
    })));
  } catch {
  }
  const maxWeight = Math.max(...items.map((i) => Number(i.market_value) || 0), 1e-4);
  const tiles = items.map((i) => {
    const weight = Number(i.market_value) || 0;
    const size = Math.max(1.5, Math.sqrt(weight / maxWeight) * 4);
    const change = Number(i.change_pct_d);
    const bg = colorForChange(change);
    return `
      <div class="tile" style="background:${bg};flex:${size} 1 ${Math.round(120 * size)}px;">
        <div style="display:flex;justify-content:space-between;gap:6px;">
          <strong>${i.symbol}</strong>
          <small>${formatPercent(change)}</small>
        </div>
        <small class="muted">${i.sector ?? "Sin sector"} \xB7 ${i.industry ?? "Sin industry"}</small><br />
        <div class="weight">Valor: ${formatNumber(i.market_value, 2)} ${state.data.base_currency}</div>
      </div>
    `;
  }).join("");
  grid.innerHTML = tiles;
  if (meta)
    meta.textContent = `Cuenta: ${state.data.account_id ?? "--"} \xB7 Base: ${state.data.base_currency ?? "--"} \xB7 As of: ${state.data.as_of ?? "--"}`;
};
var loadHeatmap = async () => {
  setError("");
  try {
    console.info("[heatmap] solicitando /portfolio/heatmap");
    const resp = await overlay.withLoader(() => getJson("/portfolio/heatmap"));
    console.info("[heatmap] respuesta", resp);
    state.data = resp;
    renderGrid();
    try {
      const instruments = await getJson("/portfolio/instruments");
      console.table((instruments?.data ?? []).map((i) => ({
        symbol: i.symbol,
        name: i.name,
        currency: i.currency
      })));
    } catch (err) {
      console.info("[heatmap] no se pudo obtener /portfolio/instruments", err);
    }
    try {
      const summary = await getJson("/portfolio/summary");
      console.table((summary?.data ?? []).map((s) => ({
        symbol: s.symbol,
        price: s.price,
        prev_close: s.previous_close,
        currency: s.currency
      })));
    } catch (err) {
      console.info("[heatmap] no se pudo obtener /portfolio/summary", err);
    }
  } catch (error) {
    console.error("[heatmap] load error", error);
    setError(error?.error?.message ?? "No se pudo cargar el heatmap");
  }
};
var bindUi = () => {
  document.getElementById("heatmap-reload")?.addEventListener("click", loadHeatmap);
  document.getElementById("heatmap-currency")?.addEventListener("change", async (event) => {
    state.currency = (event.target?.value || "ARS").toUpperCase();
    await loadHeatmap();
  });
};
var init = async () => {
  renderToolbar();
  bindToolbarNavigation();
  setToolbarUserName("");
  bindUserMenu({
    onLogout: async () => {
      try {
        await getJson("/auth/logout");
      } finally {
        authStore.clearToken();
        window.location.href = "/";
      }
    },
    onAdmin: () => window.location.href = "/Frontend/usuarios.html"
  });
  highlightToolbar();
  try {
    const profile = await getJson("/me");
    setToolbarUserName(profile?.email ?? "");
    setAdminMenuVisibility(profile);
  } catch {
    const cachedProfile = authStore.getProfile();
    setToolbarUserName(cachedProfile?.email ?? "");
    setAdminMenuVisibility(cachedProfile);
  }
  await loadHeatmap();
  bindUi();
};
document.addEventListener("DOMContentLoaded", init);
//# sourceMappingURL=heatmap.js.map
