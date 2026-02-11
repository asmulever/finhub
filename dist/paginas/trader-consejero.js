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

// Frontend/paginas/trader-consejero.js
var overlay = createLoadingOverlay();
var state = {
  signals: [],
  selected: null,
  lastChartSymbol: "",
  instruments: [],
  chartControls: {
    start: null,
    end: null,
    preset: "1y",
    touchZoom: false
  }
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
var providerFor = (category) => {
  if (category === "MERCADO_GLOBAL")
    return "twelvedata";
  return "rava";
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
  for (const [category, symbols] of Object.entries(byCategory)) {
    const provider = providerFor(category);
    if (!symbols.length)
      continue;
    const qs = encodeURIComponent(symbols.join(","));
    try {
      const res = await getJson(`/r2lite/${provider}/daily?symbols=${qs}&category=${encodeURIComponent(category)}`);
      details.push({ category, provider, count: res?.count ?? 0, symbols });
    } catch (error) {
      console.info("[trader-consejero] R2Lite fallo", category, error);
      details.push({ category, provider, error: error?.error?.message ?? "error", symbols });
    }
  }
  const ok = details.every((d) => !d.error);
  return { ok, details };
};
var formatPct = (v, digits = 2) => Number.isFinite(v) ? `${(v * 100).toFixed(digits)}%` : "\u2014";
var formatNum = (v, digits = 2) => Number.isFinite(v) ? v.toFixed(digits) : "\u2014";
var formatAction = (a) => {
  const act = String(a ?? "").toUpperCase();
  if (act === "BUY")
    return `<span class="signal-buy">Compra</span>`;
  if (act === "SELL")
    return `<span class="signal-sell">Venta</span>`;
  if (act === "HOLD")
    return `<span class="signal-hold">Mantener</span>`;
  return act || "\u2014";
};
var setStatus = (msg, type = "") => {
  const el = document.getElementById("status-box");
  if (!el)
    return;
  el.textContent = msg || "";
  el.className = `status ${type}`;
};
var renderTable = () => {
  const body = document.getElementById("signals-body");
  if (!body)
    return;
  if (!state.signals.length) {
    body.innerHTML = '<tr><td colspan="9" class="muted">Sin se\xF1ales disponibles.</td></tr>';
    return;
  }
  body.innerHTML = state.signals.map((s) => {
    const range = `${formatPct(s.range_p10_pct)} \xB7 ${formatPct(s.range_p90_pct)}`;
    const stopTake = `${formatNum(s.stop_price)} / ${formatNum(s.take_price)}`;
    return `
      <tr data-symbol="${s.especie || s.symbol}">
        <td>${s.especie || s.symbol}</td>
        <td data-action-symbol="${s.especie || s.symbol}">${formatAction(s.action)}</td>
        <td>${formatPct(s.confidence, 1)}</td>
        <td>${s.horizon_days || "\u2014"}d</td>
        <td>${formatPct(s.exp_return_pct)}</td>
        <td>${range}</td>
        <td>${stopTake}</td>
        <td>${s.rationale_short ?? "\u2014"}</td>
        <td><button class="icon-button" data-symbol="${s.especie || s.symbol}">\u{1F4C8} Gr\xE1fico</button></td>
      </tr>
    `;
  }).join("");
  body.querySelectorAll("button[data-symbol]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      const sym = btn.getAttribute("data-symbol") || "";
      const signal = state.signals.find((x) => (x.especie || x.symbol) === sym);
      if (signal) {
        state.selected = signal;
        renderDetail();
        await overlay.withLoader(() => openChart(signal));
      }
    });
  });
  body.querySelectorAll("td[data-action-symbol]").forEach((cell) => {
    cell.style.cursor = "pointer";
    cell.title = "Ver detalle de la se\xF1al";
    cell.addEventListener("click", () => {
      const sym = cell.getAttribute("data-action-symbol") || "";
      const signal = state.signals.find((x) => (x.especie || x.symbol) === sym);
      if (signal) {
        state.selected = signal;
        renderDetail();
      }
    });
  });
};
var renderDetail = () => {
  const panel = document.getElementById("detail-panel");
  if (!panel)
    return;
  const s = state.selected;
  if (!s) {
    panel.innerHTML = "Selecciona una se\xF1al.";
    return;
  }
  panel.innerHTML = `
    <div class="grid">
      <div class="stat"><strong>Acci\xF3n</strong><div>${formatAction(s.action)}</div></div>
      <div class="stat"><strong>Confianza</strong><div>${formatPct(s.confidence, 1)}</div></div>
      <div class="stat"><strong>Retorno esperado</strong><div>${formatPct(s.exp_return_pct)}</div></div>
      <div class="stat"><strong>Rango P10-P90</strong><div>${formatPct(s.range_p10_pct)} \xB7 ${formatPct(s.range_p90_pct)}</div></div>
      <div class="stat"><strong>Stop / Take</strong><div>${formatNum(s.stop_price)} / ${formatNum(s.take_price)}</div></div>
      <div class="stat"><strong>Trend / Momentum</strong><div>${s.trend_state ?? "N/D"} \xB7 ${s.momentum_state ?? "N/D"}</div></div>
      <div class="stat"><strong>ATR</strong><div>${formatNum(s.volatility_atr)}</div></div>
      <div class="stat"><strong>Data</strong><div>${s.data_quality ?? "N/D"} \xB7 ${s.data_points_used ?? "\u2014"} velas</div></div>
    </div>
    <p class="muted" style="margin-top:10px;">${s.rationale_short ?? "Sin explicaci\xF3n"} \xB7 Tags: ${(s.rationale_tags ?? []).join(", ")}</p>
  `;
};
var filterByRangeDays = (points, days) => {
  if (!Number.isFinite(days) || days <= 0)
    return points;
  const cutoff = /* @__PURE__ */ new Date();
  cutoff.setDate(cutoff.getDate() - days);
  return points.filter((p) => p.t >= cutoff);
};
var filterByDateRange = (points, start, end) => {
  if (!(start instanceof Date) || !(end instanceof Date))
    return points;
  return points.filter((p) => p.t >= start && p.t <= end);
};
var computeRsi = (points, period = 14) => {
  const closes = points.map((p) => p.close);
  if (closes.length <= period)
    return points.map((p) => ({ t: p.t, v: null }));
  const rsis = [];
  let gains = 0;
  let losses = 0;
  for (let i = 1; i <= period; i++) {
    const diff = closes[i] - closes[i - 1];
    if (diff >= 0)
      gains += diff;
    else
      losses -= diff;
  }
  let avgGain = gains / period;
  let avgLoss = losses / period;
  const firstRsi = 100 - 100 / (1 + (avgLoss === 0 ? Infinity : avgGain / avgLoss));
  rsis[period] = firstRsi;
  for (let i = period + 1; i < closes.length; i++) {
    const diff = closes[i] - closes[i - 1];
    const gain = diff > 0 ? diff : 0;
    const loss = diff < 0 ? -diff : 0;
    avgGain = (avgGain * (period - 1) + gain) / period;
    avgLoss = (avgLoss * (period - 1) + loss) / period;
    const rs = avgLoss === 0 ? Infinity : avgGain / avgLoss;
    rsis[i] = 100 - 100 / (1 + rs);
  }
  return points.map((p, idx) => ({ t: p.t, v: rsis[idx] ?? null }));
};
var buildTicks = (min, max, count = 4) => {
  const ticks = [];
  if (!Number.isFinite(min) || !Number.isFinite(max))
    return ticks;
  const step = (max - min) / Math.max(1, count);
  for (let i = 0; i <= count; i++)
    ticks.push(min + step * i);
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
var drawPriceChart = (points) => {
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
  const margin = { top: 20, right: 20, bottom: 60, left: 70 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const xs = points.map((p) => p.t.getTime());
  const highs = points.map((p) => p.high ?? p.close);
  const lows = points.map((p) => p.low ?? p.close);
  const maxX = Math.max(...xs);
  const minX = Math.min(...xs);
  const maxY = Math.max(...highs);
  const minY = Math.min(...lows);
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  const scaleX = (t) => margin.left + (t - minX) / Math.max(1, maxX - minX) * width;
  const scaleY = (v) => margin.top + height - (v - minY) / Math.max(1e-6, maxY - minY) * height;
  const volHeight = 60;
  const volTop = margin.top + height - volHeight;
  const maxVol = Math.max(...points.map((p) => p.volume ?? 0), 1);
  ctx.fillStyle = "rgba(56,189,248,0.12)";
  points.forEach((p) => {
    const x = scaleX(p.t.getTime());
    const barWidth = Math.max(2, width / points.length * 0.8);
    const vol = p.volume ?? 0;
    const vh = vol / maxVol * volHeight;
    ctx.fillRect(x - barWidth / 2, volTop + volHeight - vh, barWidth, vh);
  });
  points.forEach((p) => {
    const x = scaleX(p.t.getTime());
    const w = Math.max(3, width / points.length * 0.7);
    const open = Number.isFinite(p.open) ? p.open : p.close;
    const close = Number.isFinite(p.close) ? p.close : open;
    const high = Number.isFinite(p.high) ? p.high : Math.max(open, close);
    const low = Number.isFinite(p.low) ? p.low : Math.min(open, close);
    const color = close >= open ? "#22c55e" : "#ef4444";
    ctx.strokeStyle = color;
    ctx.beginPath();
    ctx.moveTo(x, scaleY(high));
    ctx.lineTo(x, scaleY(low));
    ctx.stroke();
    ctx.fillStyle = color;
    const yOpen = scaleY(open);
    const yClose = scaleY(close);
    const rectY = Math.min(yOpen, yClose);
    const rectH = Math.max(2, Math.abs(yOpen - yClose));
    ctx.fillRect(x - w / 2, rectY, w, rectH);
  });
  ctx.fillStyle = "#22d3ee";
  ctx.font = "12px Inter, system-ui, sans-serif";
  ctx.fillText("Velas + Volumen", margin.left + 8, margin.top + 14);
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
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = 0;
  const maxY = 100;
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  const scaleX = (t) => margin.left + (t - minX) / Math.max(1, maxX - minX) * width;
  const scaleY = (v) => margin.top + height - (v - minY) / Math.max(1e-6, maxY - minY) * height;
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
var openChart = async (signal) => {
  collapseToolbar();
  const overlayEl = document.getElementById("chart-overlay");
  if (!overlayEl)
    return;
  const presetSel = document.getElementById("chart-preset");
  const startInput = document.getElementById("chart-start");
  const endInput = document.getElementById("chart-end");
  const touchZoom = document.getElementById("chart-touch-zoom");
  state.lastChartSymbol = signal.especie || signal.symbol;
  state.chartControls.preset = presetSel?.value || "1y";
  state.chartControls.touchZoom = !!touchZoom?.checked;
  const applyPreset = (preset) => {
    const end = /* @__PURE__ */ new Date();
    let start = new Date(end);
    switch (preset) {
      case "1m":
        start.setMonth(end.getMonth() - 1);
        break;
      case "6m":
        start.setMonth(end.getMonth() - 6);
        break;
      case "1y":
        start.setFullYear(end.getFullYear() - 1);
        break;
      default:
        start = null;
    }
    return { start, end };
  };
  if (state.chartControls.preset !== "custom") {
    const { start, end } = applyPreset(state.chartControls.preset);
    if (start && startInput)
      startInput.value = start.toISOString().slice(0, 10);
    if (end && endInput)
      endInput.value = end.toISOString().slice(0, 10);
    state.chartControls.start = start;
    state.chartControls.end = end;
  } else {
    state.chartControls.start = startInput?.value ? new Date(startInput.value) : null;
    state.chartControls.end = endInput?.value ? new Date(endInput.value) : null;
  }
  let series = Array.isArray(signal.series_json) ? signal.series_json : [];
  if (!series.length) {
    await ensureSnapshots([signal.especie || signal.symbol]);
    const preset = state.chartControls.preset;
    const period = preset === "custom" ? "12m" : preset === "all" ? "12m" : preset;
    const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(signal.especie || signal.symbol)}&period=${period}`);
    const points = Array.isArray(resp?.points) ? resp.points : [];
    series = points.map((p) => ({
      t: p.t ? new Date(p.t) : /* @__PURE__ */ new Date(),
      open: Number(p.open ?? p.o ?? p.close ?? p.price ?? 0),
      high: Number(p.high ?? p.h ?? p.close ?? p.price ?? 0),
      low: Number(p.low ?? p.l ?? p.close ?? p.price ?? 0),
      close: Number(p.close ?? p.price ?? 0),
      volume: Number(p.volume ?? p.v ?? 0),
      ema20: null,
      ema50: null,
      rsi14: null,
      bb_upper: null,
      bb_lower: null
    }));
  } else {
    series = series.map((p) => ({
      t: p.t ? new Date(p.t) : /* @__PURE__ */ new Date(),
      open: Number(p.open ?? p.o ?? p.close ?? 0),
      high: Number(p.high ?? p.h ?? p.close ?? 0),
      low: Number(p.low ?? p.l ?? p.close ?? 0),
      close: Number(p.close ?? 0),
      volume: Number(p.volume ?? p.v ?? 0),
      ema20: Number.isFinite(p.ema20) ? Number(p.ema20) : null,
      ema50: Number.isFinite(p.ema50) ? Number(p.ema50) : null,
      rsi14: Number.isFinite(p.rsi14) ? Number(p.rsi14) : null,
      bb_upper: Number.isFinite(p.bb_upper) ? Number(p.bb_upper) : null,
      bb_lower: Number.isFinite(p.bb_lower) ? Number(p.bb_lower) : null
    }));
  }
  let filtered = series;
  if (state.chartControls.start && state.chartControls.end) {
    filtered = filterByDateRange(series, state.chartControls.start, state.chartControls.end);
  } else if (state.chartControls.preset !== "all") {
    const days = (() => {
      switch (state.chartControls.preset) {
        case "1m":
          return 30;
        case "3m":
          return 90;
        case "6m":
          return 180;
        case "1y":
          return 365;
        case "2y":
          return 730;
        default:
          return null;
      }
    })();
    filtered = days ? filterByRangeDays(series, days) : series;
  }
  if (!filtered.length) {
    const title2 = document.getElementById("chart-title");
    const subtitle2 = document.getElementById("chart-subtitle");
    if (title2)
      title2.textContent = signal.especie || signal.symbol;
    if (subtitle2)
      subtitle2.textContent = "Sin datos en el rango seleccionado";
    overlayEl.classList.add("visible");
    overlayEl.setAttribute("aria-hidden", "false");
    return;
  }
  const rsiPoints = filtered.some((p) => Number.isFinite(p.rsi14)) ? filtered.map((p) => ({ t: p.t, v: p.rsi14 ?? null })).filter((p) => Number.isFinite(p.v)) : computeRsi(filtered, 14);
  drawPriceChart(filtered);
  drawRsiChart(rsiPoints);
  const title = document.getElementById("chart-title");
  const subtitle = document.getElementById("chart-subtitle");
  if (title)
    title.textContent = signal.especie || signal.symbol;
  if (subtitle) {
    let label = "Hist\xF3rico + Indicadores";
    if (state.chartControls.start && state.chartControls.end) {
      label = `Hist\xF3rico ${state.chartControls.start.toISOString().slice(0, 10)} a ${state.chartControls.end.toISOString().slice(0, 10)}`;
    } else if (state.chartControls.preset && state.chartControls.preset !== "all") {
      label = `Hist\xF3rico ${state.chartControls.preset}`;
    }
    subtitle.textContent = label;
  }
  document.querySelectorAll(".chart-canvas-wrap").forEach((wrap) => {
    wrap.style.touchAction = state.chartControls.touchZoom ? "pan-y pinch-zoom" : "auto";
  });
  overlayEl.classList.add("visible");
  overlayEl.setAttribute("aria-hidden", "false");
  document.body.classList.add("no-scroll");
};
var closeOverlay = () => {
  const overlayEl = document.getElementById("chart-overlay");
  if (!overlayEl)
    return;
  overlayEl.classList.remove("visible");
  overlayEl.setAttribute("aria-hidden", "true");
  document.body.classList.remove("no-scroll");
};
var fetchSignals = async ({ force = false, collect = false } = {}) => {
  const label = force ? "Recalculando tendencias..." : "Cargando se\xF1ales...";
  setStatus(label, "info");
  const ingest = await ensureSnapshots();
  if (!ingest.ok) {
    setStatus("No se pudieron preparar snapshots (R2Lite)", "error");
    return;
  }
  const buildQuery = (opts) => {
    const params = [];
    if (opts.force)
      params.push("force=1");
    if (opts.collect)
      params.push("collect=1");
    return params.length ? `?${params.join("&")}` : "";
  };
  const load = async (opts) => {
    const resp = await getJson(`/signals/latest${buildQuery(opts)}`);
    return Array.isArray(resp?.data) ? resp.data : [];
  };
  let data = [];
  try {
    data = await load({ force, collect });
  } catch (error) {
    console.info("[trader-consejero] Reintento sin collect", error);
    if (collect) {
      data = await load({ force, collect: false });
    } else if (force) {
      console.info("[trader-consejero] Reintento sin force", error);
      data = await load({ force: false, collect: false });
    } else {
      throw error;
    }
  }
  state.signals = data.map((s) => ({
    ...s,
    symbol: (s.symbol ?? "").toUpperCase(),
    especie: (s.especie ?? s.symbol ?? "").toUpperCase(),
    rationale_tags: Array.isArray(s.rationale_tags) ? s.rationale_tags : []
  }));
  document.getElementById("badge-count").textContent = `${state.signals.length} se\xF1ales`;
  document.getElementById("badge-updated").textContent = `Actualizado: ${(/* @__PURE__ */ new Date()).toISOString().slice(0, 16)}`;
  renderTable();
  setStatus("", "");
};
var bindUi = () => {
  document.getElementById("btn-refresh")?.addEventListener("click", () => {
    overlay.withLoader(() => fetchSignals({ force: true, collect: true })).catch((error) => {
      console.info("[trader-consejero] No se pudieron actualizar tendencias", error);
      setStatus("No se pudieron actualizar las tendencias", "error");
    });
  });
  document.getElementById("chart-close-btn")?.addEventListener("click", closeOverlay);
  document.getElementById("chart-overlay")?.addEventListener("click", (e) => {
    if (e.target?.id === "chart-overlay")
      closeOverlay();
  });
  ["chart-preset", "chart-start", "chart-end", "chart-touch-zoom"].forEach((id) => {
    document.getElementById(id)?.addEventListener("change", () => {
      const signal = state.signals.find((s) => (s.especie || s.symbol) === state.lastChartSymbol);
      if (signal) {
        overlay.withLoader(() => openChart(signal)).catch(() => setStatus("No se pudo recargar gr\xE1fico", "error"));
      }
    });
  });
};
var init = async () => {
  bindUi();
  const end = /* @__PURE__ */ new Date();
  const start = /* @__PURE__ */ new Date();
  start.setFullYear(end.getFullYear() - 1);
  const startInput = document.getElementById("chart-start");
  const endInput = document.getElementById("chart-end");
  if (startInput)
    startInput.value = start.toISOString().slice(0, 10);
  if (endInput)
    endInput.value = end.toISOString().slice(0, 10);
  try {
    await overlay.withLoader(() => fetchSignals({ force: true, collect: true }));
  } catch (error) {
    console.info("[trader-consejero] No se pudieron cargar se\xF1ales iniciales", error);
    setStatus("No se pudieron cargar las se\xF1ales iniciales", "error");
  }
};
document.addEventListener("DOMContentLoaded", init);
//# sourceMappingURL=trader-consejero.js.map
