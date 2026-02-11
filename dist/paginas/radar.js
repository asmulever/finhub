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

// Frontend/paginas/radar.js
var overlay = createLoadingOverlay();
var state = {
  models: [],
  model: "",
  analysis: []
};
var $ = (id) => document.getElementById(id);
var getId = (m) => typeof m === "string" ? m : m.id ?? "";
var setError = (msg, targetId = "analyze-error") => {
  const el = $(targetId);
  if (el)
    el.textContent = msg || "";
};
var renderModels = () => {
  const select = $("model-select");
  if (!select)
    return;
  const errorEl = $("models-error");
  if (errorEl)
    errorEl.textContent = "";
  select.innerHTML = state.models.map((m) => {
    const id = getId(m);
    const label = id;
    const selected = id === state.model ? "selected" : "";
    return `<option value="${id}" ${selected}>${label}</option>`;
  }).join("") || `<option value="${state.model || "auto"}" selected>${state.model || "auto"}</option>`;
  $("model-pill").textContent = `modelo: ${state.model || "auto"}`;
};
var normalizeRows = (result) => {
  if (!result)
    return [];
  if (Array.isArray(result))
    return result;
  if (Array.isArray(result.analysis))
    return result.analysis;
  if (result.analysis && Array.isArray(result.analysis.analysis))
    return result.analysis.analysis;
  return [];
};
var renderAnalysis = (rows) => {
  const body = $("analysis-body");
  if (!body)
    return;
  if (!rows.length) {
    body.innerHTML = '<tr><td class="muted" colspan="7">Sin resultados</td></tr>';
    return;
  }
  body.innerHTML = rows.map((row) => {
    const decision = (row.decision ?? row.action ?? "").toString().toLowerCase();
    const decisionClass = decision === "buy" ? "decision-buy" : decision === "sell" ? "decision-sell" : "decision-hold";
    const conf = row.confidence_pct ?? row.confidence ?? null;
    const horizon = row.horizon_days ?? row.horizon ?? null;
    return `
      <tr>
        <td>${row.symbol ?? ""}</td>
        <td class="${decisionClass}">${decision || "\u2014"}</td>
        <td>${row.thesis ?? row.summary ?? "\u2014"}</td>
        <td>${row.catalysts ?? row.drivers ?? "\u2014"}</td>
        <td>${row.risks ?? "\u2014"}</td>
        <td>${conf !== null ? `${Number(conf).toFixed(0)}%` : "\u2014"}</td>
        <td>${horizon ?? "\u2014"}</td>
      </tr>
    `;
  }).join("");
};
var loadModels = async () => {
  try {
    const data = await getJson("/llm/models/openrouter");
    const items = Array.isArray(data?.data) ? data.data : [];
    if (items.length) {
      state.models = items;
      state.model = getId(items[0]);
    } else {
      state.model = "openrouter/auto";
    }
  } catch (e) {
    console.warn("No se pudo cargar modelos, uso auto", e);
    const errorEl = $("models-error");
    if (errorEl)
      errorEl.textContent = "No se pudieron listar modelos; se usa auto.";
    state.model = "openrouter/auto";
  }
  renderModels();
};
var loadOrAnalyze = async () => {
  setError("");
  try {
    const result = await postJson("/llm/radar/analyze", { model: state.model, risk_profile: $("risk-select")?.value || "moderado", note: $("note")?.value || "" });
    $("model-pill").textContent = `modelo: ${result.model || state.model || "auto"}`;
    const rows = normalizeRows(result);
    renderAnalysis(rows);
  } catch (err) {
    console.error("load/analyze failed", err);
    const message = err?.error?.message || err?.message || "Fallo la consulta al LLM.";
    setError(message);
  }
};
var analyze = async () => {
  const note = $("note")?.value || "";
  const risk = $("risk-select")?.value || "moderado";
  const select = $("model-select");
  state.model = select?.value || state.model;
  setError("");
  try {
    const result = await postJson("/llm/radar/analyze", {
      model: state.model,
      risk_profile: risk,
      note
    });
    $("model-pill").textContent = `modelo: ${state.model || "auto"}`;
    const rows = normalizeRows(result);
    renderAnalysis(rows);
    setError("");
  } catch (err) {
    console.error("analyze failed", err);
    const message = err?.error?.message || err?.message || "Fallo la consulta al LLM.";
    setError(message);
  }
};
var init = async () => {
  await overlay.withLoader(loadModels);
  await overlay.withLoader(loadOrAnalyze);
  $("analyze-btn")?.addEventListener("click", () => overlay.withLoader(analyze));
};
document.addEventListener("DOMContentLoaded", init);
//# sourceMappingURL=radar.js.map
