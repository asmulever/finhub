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

// Frontend/components/toolbar.js
var updateToggleVisual = (toolbar, toggle) => {
  const collapsed = toolbar.classList.contains("collapsed");
  toggle.textContent = collapsed ? "\u25BC" : "\u25B2";
  toggle.setAttribute("aria-expanded", String(!collapsed));
  toggle.setAttribute("aria-label", collapsed ? "Mostrar barra" : "Ocultar barra");
  toggle.title = collapsed ? "Mostrar barra" : "Ocultar barra";
};
var collapseToolbar = () => {
  const toolbar = document.querySelector(".toolbar");
  if (!toolbar)
    return;
  toolbar.classList.add("collapsed");
  const toggle = document.querySelector(".toolbar-toggle");
  if (toggle) {
    updateToggleVisual(toolbar, toggle);
  }
};

// Frontend/paginas/estadistica.js
var overlay = createLoadingOverlay();
var state = {
  grouped: {},
  run: null,
  status: "idle",
  instruments: [],
  lastChartSymbol: "",
  snapWarning: ""
};
var guessCategory = (item) => {
  const type = String(item?.type ?? "").toUpperCase();
  const country = String(item?.country ?? "").toUpperCase();
  const currency = String(item?.currency ?? "").toUpperCase();
  const symbol = String(item?.symbol ?? item?.especie ?? "").toUpperCase();
  if (type.includes("BOND") || type.includes("BONO"))
    return "BONO";
  if (type.includes("CEDEAR") || symbol.endsWith("D"))
    return "CEDEAR";
  if (country === "AR" || currency === "ARS")
    return "ACCIONES_AR";
  return "MERCADO_GLOBAL";
};
var providerOrder = (category) => {
  if (category === "MERCADO_GLOBAL")
    return ["twelvedata", "alphavantage", "rava"];
  return ["twelvedata", "alphavantage", "rava"];
};
var fetchPortfolioInstruments = async () => {
  const resp = await getJson("/portfolio/instruments");
  const items = Array.isArray(resp?.data) ? resp.data : [];
  state.instruments = items;
  return items;
};
var ensureSnapshots = async (symbolsInput) => {
  const items = state.instruments.length ? state.instruments : await fetchPortfolioInstruments();
  const symbolsSet = new Set((symbolsInput && symbolsInput.length ? symbolsInput : items.map((i) => i.symbol || i.especie || "")).map((s) => String(s).toUpperCase()).filter(Boolean));
  if (!symbolsSet.size)
    return { ok: false, details: [] };
  const byCategory = {};
  items.forEach((it) => {
    const sym = String(it.symbol || it.especie || "").toUpperCase();
    if (!symbolsSet.has(sym))
      return;
    const cat = guessCategory(it);
    byCategory[cat] = byCategory[cat] || [];
    byCategory[cat].push(sym);
  });
  const details = [];
  let warning = "";
  for (const [category, symbols] of Object.entries(byCategory)) {
    if (!symbols.length)
      continue;
    const providers = providerOrder(category);
    let success = false;
    for (const provider of providers) {
      const qs = encodeURIComponent(symbols.join(","));
      try {
        const res = await getJson(`/r2lite/${provider}/daily?symbols=${qs}&category=${encodeURIComponent(category)}`);
        details.push({ category, provider, count: res?.count ?? 0, symbols });
        success = (res?.count ?? 0) > 0 || provider === "rava";
        if (provider === "rava" && warning === "" && (res?.count ?? 0) <= 1) {
          warning = "Solo se obtuvo \xFAltimo precio (Rava), sin hist\xF3rico suficiente para indicadores.";
        }
        if (success && provider !== "rava") {
          break;
        }
      } catch (error) {
        console.info("[estadistica] R2Lite fallo", category, provider, error);
        details.push({ category, provider, error: error?.error?.message ?? "error", symbols });
      }
    }
    if (!success && warning === "") {
      warning = "No se pudo obtener hist\xF3rico; se intent\xF3 m\xFAltiple proveedor y fall\xF3.";
    }
  }
  state.snapWarning = warning;
  const ok = details.every((d) => !d.error);
  return { ok, details };
};
var setStatus = (msg, type = "") => {
  const el = document.getElementById("status-box");
  if (!el)
    return;
  el.textContent = msg || "";
  el.className = `status ${type}`;
};
var setBadges = (statusLabel, runLabel, dotColor = "#94a3b8") => {
  const badgeRun = document.getElementById("badge-run");
  const badgeStatus = document.getElementById("badge-status");
  if (badgeRun)
    badgeRun.textContent = `\xDAltimo run: ${runLabel}`;
  if (badgeStatus) {
    badgeStatus.innerHTML = `<span class="badge-dot" style="background:${dotColor};"></span> Estado: ${statusLabel}`;
  }
};
var formatMoney = (value) => {
  if (!Number.isFinite(value))
    return "N/D";
  return value >= 1 ? value.toFixed(2) : value.toPrecision(3);
};
var renderTable = () => {
  const tbody = document.getElementById("pred-table-body");
  if (!tbody)
    return;
  const symbols = Object.keys(state.grouped).sort();
  if (symbols.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos a\xFAn.</td></tr>';
    return;
  }
  const formatProjection = (item) => {
    if (!item)
      return '<span class="muted">\u2014</span>';
    const dir = item.prediction;
    const cls = dir === "up" ? "signal-up" : dir === "down" ? "signal-down" : "signal-neutral";
    const label = dir === "up" ? "Alza" : dir === "down" ? "Baja" : "Neutral";
    const delta = Number.isFinite(item.change_pct) ? `${(item.change_pct * 100).toFixed(2)}%` : "N/D";
    return `<span class="${cls}">${label} \xB7 ${delta}</span>`;
  };
  tbody.innerHTML = symbols.map((symbol) => {
    const row = state.grouped[symbol];
    const price = formatMoney(row.price);
    const confidence = row.selected?.confidence !== null && row.selected?.confidence !== void 0 ? `${(row.selected.confidence * 100).toFixed(1)}%` : "N/D";
    return `
      <tr>
        <td>${row.especie || symbol}</td>
        <td>${price}</td>
        <td>${formatProjection(row.selected)}</td>
        <td>${confidence}</td>
        <td><button class="icon-button" data-symbol="${symbol}" data-especie="${row.especie || symbol}" aria-label="Ver gr\xE1fico de ${symbol}">\u{1F4C8} Gr\xE1fico</button></td>
      </tr>
    `;
  }).join("");
  tbody.querySelectorAll("button[data-symbol]").forEach((btn) => {
    btn.addEventListener("click", (event) => {
      const symbol = btn.getAttribute("data-symbol");
      if (symbol) {
        overlay.withLoader(() => openChart(symbol)).catch((err) => {
          console.info("[estadistica] No se pudo abrir gr\xE1fico", err);
          setStatus("No se pudo cargar el gr\xE1fico", "error");
        });
      }
    });
  });
};
var groupPredictions = (items) => {
  const grouped = {};
  items.forEach((item) => {
    const symbol = String(item.symbol ?? "").toUpperCase();
    const especie = String(item.especie ?? item.symbol ?? "").toUpperCase();
    if (!symbol)
      return;
    if (!grouped[symbol]) {
      grouped[symbol] = {
        symbol,
        especie,
        price: item.price ?? null,
        price_as_of: item.price_as_of ?? null,
        horizons: {},
        confidenceAvg: null
      };
    }
    grouped[symbol].horizons[item.horizon] = {
      prediction: item.prediction,
      confidence: item.confidence,
      change_pct: item.change_pct ?? null
    };
  });
  Object.keys(grouped).forEach((key) => {
    const horizons = grouped[key].horizons;
    const preferred = horizons[90] ?? horizons[60] ?? horizons[30] ?? null;
    grouped[key].selected = preferred;
  });
  state.grouped = grouped;
};
var showAnalysisOverlay = (message, redirect = false) => {
  const overlayEl = document.getElementById("analysis-overlay");
  const titleEl = document.getElementById("analysis-overlay-title");
  const textEl = document.getElementById("analysis-overlay-text");
  if (!overlayEl || !titleEl || !textEl)
    return;
  titleEl.textContent = "An\xE1lisis en curso";
  textEl.textContent = message;
  overlayEl.classList.add("visible");
  overlayEl.setAttribute("aria-hidden", "false");
  setTimeout(() => {
    overlayEl.classList.remove("visible");
    overlayEl.setAttribute("aria-hidden", "true");
    if (redirect) {
      window.location.href = "/Frontend/Portafolios.html";
    }
  }, 5e3);
};
var fetchLatest = async () => {
  setStatus("Preparando datos (R2Lite)...", "info");
  const ingest = await ensureSnapshots();
  if (!ingest.ok) {
    setStatus("No se pudieron preparar snapshots (R2Lite)", "error");
    setBadges("Error ingestando", "n/a", "#ef4444");
    return;
  }
  if (state.snapWarning) {
    setStatus(state.snapWarning, "info");
  }
  setBadges("Snapshots OK", "n/a", "#22c55e");
  setStatus("Cargando predicciones...", "info");
  const resp = await getJson("/analytics/predictions/latest");
  const status = resp?.status ?? "unknown";
  state.status = status;
  if (status === "running") {
    setBadges("En progreso", "n/a", "#eab308");
    setStatus("Hay un an\xE1lisis en curso, se redirigir\xE1 a Portafolios.", "info");
    showAnalysisOverlay("Ya hay un an\xE1lisis ejecut\xE1ndose. Evitamos lanzarlo de nuevo.", true);
    return;
  }
  if (status === "empty") {
    setBadges("Pendiente", "n/a", "#eab308");
    setStatus("Sin an\xE1lisis previo, iniciando c\xE1lculo...", "info");
    await triggerRun(true);
    return;
  }
  if (status !== "ready") {
    setStatus("No se pudo obtener el estado de predicciones", "error");
    return;
  }
  const predictions = Array.isArray(resp.predictions) ? resp.predictions : [];
  groupPredictions(predictions);
  state.run = resp.run ?? null;
  const runLabel = resp.run?.finished_at ?? resp.run?.started_at ?? "--";
  setBadges("Listo", runLabel, "#22c55e");
  setStatus("", "");
  renderTable();
};
var triggerRun = async (redirectAfter = false) => {
  setStatus("Preparando snapshots (R2Lite)...", "info");
  const ingest = await ensureSnapshots();
  if (!ingest.ok) {
    setStatus("No se pudieron preparar snapshots (R2Lite)", "error");
    setBadges("Error ingestando", "n/a", "#ef4444");
    return;
  }
  if (state.snapWarning) {
    setStatus(state.snapWarning, "info");
  }
  setBadges("Snapshots OK", "n/a", "#22c55e");
  const resp = await postJson("/analytics/predictions/run/me", {});
  const status = resp?.status ?? "unknown";
  const runId = resp?.run_id ?? resp?.run?.id ?? null;
  const label = runId ? `run ${runId}` : "n/a";
  if (status === "running") {
    setBadges("En progreso", label, "#eab308");
    showAnalysisOverlay("Analizando instrumentos seleccionados...", redirectAfter);
    return;
  }
  if (status === "skipped") {
    setBadges("Pendiente", label, "#eab308");
    setStatus("No hay instrumentos en el portafolio para analizar.", "info");
    return;
  }
  if (status === "success" || status === "partial") {
    setBadges("Completado", label, "#22c55e");
    if (redirectAfter) {
      showAnalysisOverlay("An\xE1lisis ejecutado. Te redirigimos a Portafolios en segundos.", true);
      return;
    }
    setStatus("An\xE1lisis finalizado, recargando tabla...", "info");
    await fetchLatest();
    return;
  }
  setStatus("No se pudo lanzar el an\xE1lisis", "error");
};
var rsiSeries = (closes, period = 14) => {
  const result = [];
  for (let i = 0; i < closes.length; i++) {
    if (i < period) {
      result.push(null);
      continue;
    }
    let gains = 0;
    let losses = 0;
    for (let j = i - period + 1; j <= i; j++) {
      const change = closes[j] - closes[j - 1];
      if (change > 0)
        gains += change;
      else
        losses += Math.abs(change);
    }
    if (losses === 0) {
      result.push(70);
      continue;
    }
    const rs = gains / losses;
    result.push(100 - 100 / (1 + rs));
  }
  return result;
};
var bollinger = (closes, window2 = 20, multiplier = 2) => {
  const bands = [];
  for (let i = 0; i < closes.length; i++) {
    if (i + 1 < window2) {
      bands.push({ mid: null, upper: null, lower: null });
      continue;
    }
    const slice = closes.slice(i - window2 + 1, i + 1);
    const avg = slice.reduce((a, b) => a + b, 0) / slice.length;
    const variance = slice.reduce((acc, v) => acc + (v - avg) ** 2, 0) / slice.length;
    const std = Math.sqrt(variance);
    bands.push({ mid: avg, upper: avg + multiplier * std, lower: avg - multiplier * std });
  }
  return bands;
};
var filterByRange = (points, days) => {
  if (!Number.isFinite(days) || days <= 0)
    return points;
  const cutoff = /* @__PURE__ */ new Date();
  cutoff.setDate(cutoff.getDate() - days);
  return points.filter((p) => p.t >= cutoff);
};
var buildTicks = (min, max, count = 4) => {
  const ticks = [];
  if (!Number.isFinite(min) || !Number.isFinite(max))
    return ticks;
  const step = (max - min) / Math.max(1, count);
  for (let i = 0; i <= count; i++) {
    ticks.push(min + step * i);
  }
  return ticks;
};
var drawAxes = (ctx, margin, width, height, minX, maxX, minY, maxY) => {
  ctx.strokeStyle = "rgba(148,163,184,0.35)";
  ctx.beginPath();
  ctx.moveTo(margin.left, margin.top);
  ctx.lineTo(margin.left, margin.top + height);
  ctx.lineTo(margin.left + width, margin.top + height);
  ctx.stroke();
  const yTicks = buildTicks(minY, maxY, 4);
  ctx.fillStyle = "#94a3b8";
  ctx.font = "11px Inter, system-ui, sans-serif";
  yTicks.forEach((val) => {
    const y = margin.top + height - (val - minY) / Math.max(1e-6, maxY - minY) * height;
    ctx.fillText(val.toFixed(2), 6, y + 4);
    ctx.strokeStyle = "rgba(148,163,184,0.15)";
    ctx.beginPath();
    ctx.moveTo(margin.left, y);
    ctx.lineTo(margin.left + width, y);
    ctx.stroke();
  });
  const xTicks = buildTicks(minX, maxX, 3);
  xTicks.forEach((val) => {
    const x = margin.left + (val - minX) / Math.max(1, maxX - minX) * width;
    const d = new Date(val);
    const label = `${String(d.getDate()).padStart(2, "0")}/${String(d.getMonth() + 1).padStart(2, "0")}`;
    ctx.fillText(label, x - 14, margin.top + height + 16);
    ctx.strokeStyle = "rgba(148,163,184,0.15)";
    ctx.beginPath();
    ctx.moveTo(x, margin.top);
    ctx.lineTo(x, margin.top + height);
    ctx.stroke();
  });
};
var drawPriceChart = (points, bands) => {
  const canvas = document.getElementById("price-canvas");
  if (!canvas)
    return;
  const ctx = canvas.getContext("2d");
  if (!ctx)
    return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!points.length) {
    ctx.fillStyle = "#94a3b8";
    ctx.fillText("Sin datos para graficar", 20, 30);
    return;
  }
  const margin = { top: 20, right: 20, bottom: 30, left: 70 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const xs = points.map((p) => p.t.getTime());
  const prices = points.map((p) => p.v).filter((v) => Number.isFinite(v));
  const uppers = bands.map((b) => b.upper).filter((v) => Number.isFinite(v));
  const lowers = bands.map((b) => b.lower).filter((v) => Number.isFinite(v));
  const allY = prices.concat(uppers).concat(lowers).filter(Number.isFinite);
  if (!allY.length) {
    ctx.fillStyle = "#94a3b8";
    ctx.fillText("Sin datos para graficar", 20, 30);
    return;
  }
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = Math.min(...allY);
  const maxY = Math.max(...allY);
  const scaleX = (t) => margin.left + (t - minX) / Math.max(1, maxX - minX) * width;
  const scaleY = (v) => margin.top + height - (v - minY) / Math.max(1e-6, maxY - minY) * height;
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  ctx.strokeStyle = "rgba(14,165,233,0.5)";
  ctx.beginPath();
  bands.forEach((b, idx) => {
    if (!Number.isFinite(b.upper) || !Number.isFinite(b.lower))
      return;
    const x = scaleX(xs[idx]);
    const yUpper = scaleY(b.upper);
    const yLower = scaleY(b.lower);
    if (idx === 0) {
      ctx.moveTo(x, yUpper);
    } else {
      ctx.lineTo(x, yUpper);
    }
  });
  ctx.stroke();
  ctx.beginPath();
  bands.forEach((b, idx) => {
    if (!Number.isFinite(b.lower))
      return;
    const x = scaleX(xs[idx]);
    const yLower = scaleY(b.lower);
    if (idx === 0)
      ctx.moveTo(x, yLower);
    else
      ctx.lineTo(x, yLower);
  });
  ctx.stroke();
  ctx.strokeStyle = "#22d3ee";
  ctx.beginPath();
  points.forEach((p, idx) => {
    if (!Number.isFinite(p.v))
      return;
    const x = scaleX(p.t.getTime());
    const y = scaleY(p.v);
    if (idx === 0)
      ctx.moveTo(x, y);
    else
      ctx.lineTo(x, y);
  });
  ctx.stroke();
  ctx.fillStyle = "#22d3ee";
  ctx.font = "12px Inter, system-ui, sans-serif";
  ctx.fillText("Precio + Bandas Bollinger", margin.left + 8, margin.top + 14);
};
var drawRsiChart = (points) => {
  const canvas = document.getElementById("rsi-canvas");
  if (!canvas)
    return;
  const ctx = canvas.getContext("2d");
  if (!ctx)
    return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!points.length) {
    ctx.fillStyle = "#94a3b8";
    ctx.fillText("Sin RSI disponible", 20, 30);
    return;
  }
  const margin = { top: 16, right: 20, bottom: 24, left: 60 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const xs = points.map((p) => p.t.getTime());
  const ys = points.map((p) => p.v).filter((v) => Number.isFinite(v));
  if (!ys.length) {
    ctx.fillStyle = "#94a3b8";
    ctx.fillText("Sin RSI disponible", 20, 30);
    return;
  }
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = 0;
  const maxY = 100;
  const scaleX = (t) => margin.left + (t - minX) / Math.max(1, maxX - minX) * width;
  const scaleY = (v) => margin.top + height - (v - minY) / Math.max(1e-6, maxY - minY) * height;
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  ctx.strokeStyle = "rgba(248, 113, 113, 0.4)";
  ctx.beginPath();
  ctx.moveTo(margin.left, scaleY(70));
  ctx.lineTo(margin.left + width, scaleY(70));
  ctx.stroke();
  ctx.strokeStyle = "rgba(34, 197, 94, 0.4)";
  ctx.beginPath();
  ctx.moveTo(margin.left, scaleY(30));
  ctx.lineTo(margin.left + width, scaleY(30));
  ctx.stroke();
  ctx.strokeStyle = "#a855f7";
  ctx.beginPath();
  points.forEach((p, idx) => {
    if (!Number.isFinite(p.v))
      return;
    const x = scaleX(p.t.getTime());
    const y = scaleY(p.v);
    if (idx === 0)
      ctx.moveTo(x, y);
    else
      ctx.lineTo(x, y);
  });
  ctx.stroke();
  ctx.fillStyle = "#a855f7";
  ctx.font = "12px Inter, system-ui, sans-serif";
  ctx.fillText("RSI 14", margin.left + 8, margin.top + 14);
};
var openChart = async (symbol) => {
  collapseToolbar();
  const overlayEl = document.getElementById("chart-overlay");
  if (!overlayEl)
    return;
  const rangeSelect = document.getElementById("chart-range");
  const rangeVal = rangeSelect?.value || "all";
  const days = rangeVal === "all" ? null : Number(rangeVal);
  state.lastChartSymbol = symbol;
  const entry = state.grouped[symbol] ?? {};
  const especie = (entry.especie || state.speciesMap[symbol] || symbol).toUpperCase();
  const period = days === null ? "12m" : days <= 30 ? "1m" : days <= 90 ? "3m" : "6m";
  await ensureSnapshots([especie]);
  const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(especie)}&period=${encodeURIComponent(period)}`);
  const points = Array.isArray(resp?.points) ? resp.points : [];
  const parsed = points.map((p) => {
    const candidates = [p.close, p.price, p.cierre, p.ajuste, p.ultimo];
    const close = candidates.find((v) => Number.isFinite(v));
    if (!Number.isFinite(close))
      return null;
    const rawTime = p.t ?? p.as_of ?? p.fecha ?? p.date ?? null;
    const t = rawTime ? new Date(rawTime) : /* @__PURE__ */ new Date();
    return { t, v: Number(close) };
  }).filter(Boolean).sort((a, b) => a.t.getTime() - b.t.getTime());
  const filtered = days ? filterByRange(parsed, days) : parsed;
  if (!filtered.length) {
    const title2 = document.getElementById("chart-title");
    const subtitle2 = document.getElementById("chart-subtitle");
    if (title2)
      title2.textContent = especie;
    if (subtitle2)
      subtitle2.textContent = "Sin datos en el rango seleccionado";
    overlayEl.classList.add("visible");
    overlayEl.setAttribute("aria-hidden", "false");
    document.body.classList.add("no-scroll");
    return;
  }
  const closes = filtered.map((p) => p.v);
  const bands = bollinger(closes);
  drawPriceChart(filtered, bands);
  const rsiValues = rsiSeries(closes);
  const rsiPoints = rsiValues.map((v, idx) => ({ t: filtered[idx]?.t ?? /* @__PURE__ */ new Date(), v }));
  drawRsiChart(rsiPoints);
  const title = document.getElementById("chart-title");
  const subtitle = document.getElementById("chart-subtitle");
  if (title)
    title.textContent = especie;
  if (subtitle)
    subtitle.textContent = days ? `Hist\xF3rico ${days}d + RSI/Bandas de Bollinger` : "Hist\xF3rico completo + RSI/Bandas de Bollinger";
  overlayEl.classList.add("visible");
  overlayEl.setAttribute("aria-hidden", "false");
  document.body.classList.add("no-scroll");
};
var bindOverlayClose = () => {
  const overlayEl = document.getElementById("chart-overlay");
  const closeBtn = document.getElementById("chart-close-btn");
  if (!overlayEl || !closeBtn)
    return;
  const close = () => {
    overlayEl.classList.remove("visible");
    overlayEl.setAttribute("aria-hidden", "true");
    document.body.classList.remove("no-scroll");
  };
  closeBtn.addEventListener("click", close);
  overlayEl.addEventListener("click", (event) => {
    if (event.target === overlayEl) {
      close();
    }
  });
};
var init = async () => {
  bindOverlayClose();
  document.getElementById("chart-range")?.addEventListener("change", () => {
    if (state.lastChartSymbol) {
      overlay.withLoader(() => openChart(state.lastChartSymbol)).catch(() => setStatus("No se pudo recargar el gr\xE1fico", "error"));
    }
  });
  document.getElementById("btn-refresh")?.addEventListener("click", () => overlay.withLoader(fetchLatest));
  document.getElementById("btn-run")?.addEventListener("click", () => overlay.withLoader(() => triggerRun(false)));
  await overlay.withLoader(fetchLatest);
};
document.addEventListener("DOMContentLoaded", init);
//# sourceMappingURL=estadistica.js.map
