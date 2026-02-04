import { getJson, postJson } from '../apicliente.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';
import { collapseToolbar } from '../components/toolbar.js';

const overlay = createLoadingOverlay();
const state = {
  grouped: {},
  run: null,
  status: 'idle',
  speciesMap: {},
  lastChartSymbol: '',
};

const setStatus = (msg, type = '') => {
  const el = document.getElementById('status-box');
  if (!el) return;
  el.textContent = msg || '';
  el.className = `status ${type}`;
};

const setBadges = (statusLabel, runLabel, dotColor = '#94a3b8') => {
  const badgeRun = document.getElementById('badge-run');
  const badgeStatus = document.getElementById('badge-status');
  if (badgeRun) badgeRun.textContent = `Último run: ${runLabel}`;
  if (badgeStatus) {
    badgeStatus.innerHTML = `<span class="badge-dot" style="background:${dotColor};"></span> Estado: ${statusLabel}`;
  }
};

const formatMoney = (value) => {
  if (!Number.isFinite(value)) return 'N/D';
  return value >= 1 ? value.toFixed(2) : value.toPrecision(3);
};

const renderTable = () => {
  const tbody = document.getElementById('pred-table-body');
  if (!tbody) return;
  const symbols = Object.keys(state.grouped).sort();
  if (symbols.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="muted">Sin datos aún.</td></tr>';
    return;
  }

  const formatProjection = (item) => {
    if (!item) return '<span class="muted">—</span>';
    const dir = item.prediction;
    const cls = dir === 'up' ? 'signal-up' : dir === 'down' ? 'signal-down' : 'signal-neutral';
    const label = dir === 'up' ? 'Alza' : dir === 'down' ? 'Baja' : 'Neutral';
    const delta = Number.isFinite(item.change_pct) ? `${(item.change_pct * 100).toFixed(2)}%` : 'N/D';
    return `<span class="${cls}">${label} · ${delta}</span>`;
  };

  tbody.innerHTML = symbols.map((symbol) => {
    const row = state.grouped[symbol];
    const price = formatMoney(row.price);
    const confidence = row.selected?.confidence !== null && row.selected?.confidence !== undefined
      ? `${(row.selected.confidence * 100).toFixed(1)}%`
      : 'N/D';
    return `
      <tr>
        <td>${row.especie || symbol}</td>
        <td>${price}</td>
        <td>${formatProjection(row.selected)}</td>
        <td>${confidence}</td>
        <td><button class="icon-button" data-symbol="${symbol}" data-especie="${row.especie || symbol}" aria-label="Ver gráfico de ${symbol}">📈 Gráfico</button></td>
      </tr>
    `;
  }).join('');

  tbody.querySelectorAll('button[data-symbol]').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      const symbol = btn.getAttribute('data-symbol');
      if (symbol) {
        overlay.withLoader(() => openChart(symbol)).catch((err) => {
          console.info('[estadistica] No se pudo abrir gráfico', err);
          setStatus('No se pudo cargar el gráfico', 'error');
        });
      }
    });
  });
};

const groupPredictions = (items) => {
  const grouped = {};
  items.forEach((item) => {
    const symbol = String(item.symbol ?? '').toUpperCase();
    const especie = String(item.especie ?? item.symbol ?? '').toUpperCase();
    if (!symbol) return;
    if (!grouped[symbol]) {
      grouped[symbol] = {
        symbol,
        especie,
        price: item.price ?? null,
        price_as_of: item.price_as_of ?? null,
        horizons: {},
        confidenceAvg: null,
      };
    }
    grouped[symbol].horizons[item.horizon] = {
      prediction: item.prediction,
      confidence: item.confidence,
      change_pct: item.change_pct ?? null,
    };
  });

  Object.keys(grouped).forEach((key) => {
    const horizons = grouped[key].horizons;
    const preferred = horizons[90] ?? horizons[60] ?? horizons[30] ?? null;
    grouped[key].selected = preferred;
  });

  state.grouped = grouped;
};

const loadSpeciesMap = async () => {
  try {
    const fetchList = async (path) => {
      try {
        const resp = await getJson(path);
        return resp?.items ?? resp?.data ?? [];
      } catch {
        return [];
      }
    };
    const [cedears, acciones, bonos] = await Promise.all([
      fetchList('/rava/cedears'),
      fetchList('/rava/acciones'),
      fetchList('/rava/bonos'),
    ]);
    const map = {};
    const add = (list) => {
      list.forEach((row) => {
        const symbol = (row.symbol ?? '').toUpperCase();
        const especie = (row.especie ?? '').toUpperCase();
        if (!symbol) return;
        if (especie) {
          map[symbol] = especie;
        }
      });
    };
    add(cedears);
    add(acciones);
    add(bonos);
    state.speciesMap = map;
  } catch {
    state.speciesMap = {};
  }
};

const applySpeciesMapping = () => {
  Object.keys(state.grouped).forEach((sym) => {
    const row = state.grouped[sym];
    if (!row.especie || row.especie === sym) {
      const mapped = state.speciesMap[sym];
      if (mapped) {
        row.especie = mapped;
      }
    }
  });
};

const showAnalysisOverlay = (message, redirect = false) => {
  const overlayEl = document.getElementById('analysis-overlay');
  const titleEl = document.getElementById('analysis-overlay-title');
  const textEl = document.getElementById('analysis-overlay-text');
  if (!overlayEl || !titleEl || !textEl) return;
  titleEl.textContent = 'Análisis en curso';
  textEl.textContent = message;
  overlayEl.classList.add('visible');
  overlayEl.setAttribute('aria-hidden', 'false');
  setTimeout(() => {
    overlayEl.classList.remove('visible');
    overlayEl.setAttribute('aria-hidden', 'true');
    if (redirect) {
      window.location.href = '/Frontend/Portafolios.html';
    }
  }, 5000);
};

const fetchLatest = async () => {
  setStatus('Cargando predicciones...', 'info');
  const resp = await getJson('/analytics/predictions/latest');
  const status = resp?.status ?? 'unknown';
  state.status = status;

  if (status === 'running') {
    setBadges('En progreso', 'n/a', '#eab308');
    setStatus('Hay un análisis en curso, se redirigirá a Portafolios.', 'info');
    showAnalysisOverlay('Ya hay un análisis ejecutándose. Evitamos lanzarlo de nuevo.', true);
    return;
  }

  if (status === 'empty') {
    setBadges('Pendiente', 'n/a', '#eab308');
    setStatus('Sin análisis previo, iniciando cálculo...', 'info');
    await triggerRun(true);
    return;
  }

  if (status !== 'ready') {
    setStatus('No se pudo obtener el estado de predicciones', 'error');
    return;
  }

  const predictions = Array.isArray(resp.predictions) ? resp.predictions : [];
  groupPredictions(predictions);
  await loadSpeciesMap();
  applySpeciesMapping();
  state.run = resp.run ?? null;
  const runLabel = resp.run?.finished_at ?? resp.run?.started_at ?? '--';
  setBadges('Listo', runLabel, '#22c55e');
  setStatus('', '');
  renderTable();
};

const triggerRun = async (redirectAfter = false) => {
  const resp = await postJson('/analytics/predictions/run/me', {});
  const status = resp?.status ?? 'unknown';
  const runId = resp?.run_id ?? resp?.run?.id ?? null;
  const label = runId ? `run ${runId}` : 'n/a';
  if (status === 'running') {
    setBadges('En progreso', label, '#eab308');
    showAnalysisOverlay('Analizando instrumentos seleccionados...', redirectAfter);
    return;
  }
  if (status === 'skipped') {
    setBadges('Pendiente', label, '#eab308');
    setStatus('No hay instrumentos en el portafolio para analizar.', 'info');
    return;
  }
  if (status === 'success' || status === 'partial') {
    setBadges('Completado', label, '#22c55e');
    if (redirectAfter) {
      showAnalysisOverlay('Análisis ejecutado. Te redirigimos a Portafolios en segundos.', true);
      return;
    }
    setStatus('Análisis finalizado, recargando tabla...', 'info');
    await fetchLatest();
    return;
  }
  setStatus('No se pudo lanzar el análisis', 'error');
};

const rsiSeries = (closes, period = 14) => {
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
      if (change > 0) gains += change;
      else losses += Math.abs(change);
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

const bollinger = (closes, window = 20, multiplier = 2) => {
  const bands = [];
  for (let i = 0; i < closes.length; i++) {
    if (i + 1 < window) {
      bands.push({ mid: null, upper: null, lower: null });
      continue;
    }
    const slice = closes.slice(i - window + 1, i + 1);
    const avg = slice.reduce((a, b) => a + b, 0) / slice.length;
    const variance = slice.reduce((acc, v) => acc + (v - avg) ** 2, 0) / slice.length;
    const std = Math.sqrt(variance);
    bands.push({ mid: avg, upper: avg + multiplier * std, lower: avg - multiplier * std });
  }
  return bands;
};

const drawLineChart = (canvas, points, { color = '#22d3ee', label = '' } = {}) => {
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!points.length) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', 20, 30);
    return;
  }
  const margin = { top: 20, right: 20, bottom: 30, left: 60 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const xs = points.map((p) => p.t.getTime());
  const ys = points.map((p) => p.v).filter((v) => Number.isFinite(v));
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = Math.min(...ys);
  const maxY = Math.max(...ys);
  const scaleX = (t) => margin.left + ((t - minX) / Math.max(1, maxX - minX)) * width;
  const scaleY = (v) => margin.top + height - ((v - minY) / Math.max(1e-6, maxY - minY)) * height;

  ctx.strokeStyle = 'rgba(148,163,184,0.4)';
  ctx.beginPath();
  ctx.moveTo(margin.left, margin.top);
  ctx.lineTo(margin.left, margin.top + height);
  ctx.lineTo(margin.left + width, margin.top + height);
  ctx.stroke();

  ctx.strokeStyle = color;
  ctx.beginPath();
  points.forEach((p, idx) => {
    if (!Number.isFinite(p.v)) return;
    const x = scaleX(p.t.getTime());
    const y = scaleY(p.v);
    if (idx === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  if (label) {
    ctx.fillStyle = color;
    ctx.font = '12px Inter, system-ui, sans-serif';
    ctx.fillText(label, margin.left + 8, margin.top + 14);
  }
};

const filterByRange = (points, days) => {
  if (!Number.isFinite(days) || days <= 0) return points;
  const cutoff = new Date();
  cutoff.setDate(cutoff.getDate() - days);
  return points.filter((p) => p.t >= cutoff);
};

const buildTicks = (min, max, count = 4) => {
  const ticks = [];
  if (!Number.isFinite(min) || !Number.isFinite(max)) return ticks;
  const step = (max - min) / Math.max(1, count);
  for (let i = 0; i <= count; i++) {
    ticks.push(min + step * i);
  }
  return ticks;
};

const drawAxes = (ctx, margin, width, height, minX, maxX, minY, maxY) => {
  ctx.strokeStyle = 'rgba(148,163,184,0.35)';
  ctx.beginPath();
  ctx.moveTo(margin.left, margin.top);
  ctx.lineTo(margin.left, margin.top + height);
  ctx.lineTo(margin.left + width, margin.top + height);
  ctx.stroke();

  const yTicks = buildTicks(minY, maxY, 4);
  ctx.fillStyle = '#94a3b8';
  ctx.font = '11px Inter, system-ui, sans-serif';
  yTicks.forEach((val) => {
    const y = margin.top + height - ((val - minY) / Math.max(1e-6, maxY - minY)) * height;
    ctx.fillText(val.toFixed(2), 6, y + 4);
    ctx.strokeStyle = 'rgba(148,163,184,0.15)';
    ctx.beginPath();
    ctx.moveTo(margin.left, y);
    ctx.lineTo(margin.left + width, y);
    ctx.stroke();
  });

  const xTicks = buildTicks(minX, maxX, 3);
  xTicks.forEach((val) => {
    const x = margin.left + ((val - minX) / Math.max(1, maxX - minX)) * width;
    const d = new Date(val);
    const label = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
    ctx.fillText(label, x - 14, margin.top + height + 16);
    ctx.strokeStyle = 'rgba(148,163,184,0.15)';
    ctx.beginPath();
    ctx.moveTo(x, margin.top);
    ctx.lineTo(x, margin.top + height);
    ctx.stroke();
  });
};

const drawPriceChart = (points, bands) => {
  const canvas = document.getElementById('price-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!points.length) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', 20, 30);
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
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin datos para graficar', 20, 30);
    return;
  }
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = Math.min(...allY);
  const maxY = Math.max(...allY);
  const scaleX = (t) => margin.left + ((t - minX) / Math.max(1, maxX - minX)) * width;
  const scaleY = (v) => margin.top + height - ((v - minY) / Math.max(1e-6, maxY - minY)) * height;

  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);

  // Bollinger bands
  ctx.strokeStyle = 'rgba(14,165,233,0.5)';
  ctx.beginPath();
  bands.forEach((b, idx) => {
    if (!Number.isFinite(b.upper) || !Number.isFinite(b.lower)) return;
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
    if (!Number.isFinite(b.lower)) return;
    const x = scaleX(xs[idx]);
    const yLower = scaleY(b.lower);
    if (idx === 0) ctx.moveTo(x, yLower);
    else ctx.lineTo(x, yLower);
  });
  ctx.stroke();

  // Precio
  ctx.strokeStyle = '#22d3ee';
  ctx.beginPath();
  points.forEach((p, idx) => {
    if (!Number.isFinite(p.v)) return;
    const x = scaleX(p.t.getTime());
    const y = scaleY(p.v);
    if (idx === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  ctx.fillStyle = '#22d3ee';
  ctx.font = '12px Inter, system-ui, sans-serif';
  ctx.fillText('Precio + Bandas Bollinger', margin.left + 8, margin.top + 14);
};

const drawRsiChart = (points) => {
  const canvas = document.getElementById('rsi-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!points.length) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin RSI disponible', 20, 30);
    return;
  }

  const margin = { top: 16, right: 20, bottom: 24, left: 60 };
  const width = canvas.width - margin.left - margin.right;
  const height = canvas.height - margin.top - margin.bottom;
  const xs = points.map((p) => p.t.getTime());
  const ys = points.map((p) => p.v).filter((v) => Number.isFinite(v));
  if (!ys.length) {
    ctx.fillStyle = '#94a3b8';
    ctx.fillText('Sin RSI disponible', 20, 30);
    return;
  }
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = 0;
  const maxY = 100;
  const scaleX = (t) => margin.left + ((t - minX) / Math.max(1, maxX - minX)) * width;
  const scaleY = (v) => margin.top + height - ((v - minY) / Math.max(1e-6, maxY - minY)) * height;

  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);

  // Zonas RSI
  ctx.strokeStyle = 'rgba(248, 113, 113, 0.4)';
  ctx.beginPath();
  ctx.moveTo(margin.left, scaleY(70));
  ctx.lineTo(margin.left + width, scaleY(70));
  ctx.stroke();
  ctx.strokeStyle = 'rgba(34, 197, 94, 0.4)';
  ctx.beginPath();
  ctx.moveTo(margin.left, scaleY(30));
  ctx.lineTo(margin.left + width, scaleY(30));
  ctx.stroke();

  ctx.strokeStyle = '#a855f7';
  ctx.beginPath();
  points.forEach((p, idx) => {
    if (!Number.isFinite(p.v)) return;
    const x = scaleX(p.t.getTime());
    const y = scaleY(p.v);
    if (idx === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  });
  ctx.stroke();

  ctx.fillStyle = '#a855f7';
  ctx.font = '12px Inter, system-ui, sans-serif';
  ctx.fillText('RSI 14', margin.left + 8, margin.top + 14);
};

const openChart = async (symbol) => {
  collapseToolbar();
  const overlayEl = document.getElementById('chart-overlay');
  if (!overlayEl) return;
  const rangeSelect = document.getElementById('chart-range');
  const rangeVal = rangeSelect?.value || 'all';
  const days = rangeVal === 'all' ? null : Number(rangeVal);
  state.lastChartSymbol = symbol;

  const entry = state.grouped[symbol] ?? {};
  const especie = (entry.especie || state.speciesMap[symbol] || symbol).toUpperCase();
  const period = days === null ? '12m' : (days <= 30 ? '1m' : (days <= 90 ? '3m' : '6m'));
  const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(especie)}&period=${encodeURIComponent(period)}`);
  const points = Array.isArray(resp?.points) ? resp.points : [];
  const parsed = points.map((p) => {
    const candidates = [p.close, p.price, p.cierre, p.ajuste, p.ultimo];
    const close = candidates.find((v) => Number.isFinite(v));
    if (!Number.isFinite(close)) return null;
    const rawTime = p.t ?? p.as_of ?? p.fecha ?? p.date ?? null;
    const t = rawTime ? new Date(rawTime) : new Date();
    return { t, v: Number(close) };
  }).filter(Boolean).sort((a, b) => a.t.getTime() - b.t.getTime());

  const filtered = days ? filterByRange(parsed, days) : parsed;
  if (!filtered.length) {
    const title = document.getElementById('chart-title');
    const subtitle = document.getElementById('chart-subtitle');
    if (title) title.textContent = especie;
    if (subtitle) subtitle.textContent = 'Sin datos en el rango seleccionado';
    overlayEl.classList.add('visible');
    overlayEl.setAttribute('aria-hidden', 'false');
    document.body.classList.add('no-scroll');
    return;
  }

  const closes = filtered.map((p) => p.v);
  const bands = bollinger(closes);
  drawPriceChart(filtered, bands);

  const rsiValues = rsiSeries(closes);
  const rsiPoints = rsiValues.map((v, idx) => ({ t: filtered[idx]?.t ?? new Date(), v }));
  drawRsiChart(rsiPoints);

  const title = document.getElementById('chart-title');
  const subtitle = document.getElementById('chart-subtitle');
  if (title) title.textContent = especie;
  if (subtitle) subtitle.textContent = days ? `Histórico ${days}d + RSI/Bandas de Bollinger` : 'Histórico completo + RSI/Bandas de Bollinger';

  overlayEl.classList.add('visible');
  overlayEl.setAttribute('aria-hidden', 'false');
  document.body.classList.add('no-scroll');
};

const bindOverlayClose = () => {
  const overlayEl = document.getElementById('chart-overlay');
  const closeBtn = document.getElementById('chart-close-btn');
  if (!overlayEl || !closeBtn) return;
  const close = () => {
    overlayEl.classList.remove('visible');
    overlayEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('no-scroll');
  };
  closeBtn.addEventListener('click', close);
  overlayEl.addEventListener('click', (event) => {
    if (event.target === overlayEl) {
      close();
    }
  });
};

const init = async () => {
  bindOverlayClose();
  document.getElementById('chart-range')?.addEventListener('change', () => {
    if (state.lastChartSymbol) {
      overlay.withLoader(() => openChart(state.lastChartSymbol)).catch(() => setStatus('No se pudo recargar el gráfico', 'error'));
    }
  });
  document.getElementById('btn-refresh')?.addEventListener('click', () => overlay.withLoader(fetchLatest));
  document.getElementById('btn-run')?.addEventListener('click', () => overlay.withLoader(() => triggerRun(false)));
  await overlay.withLoader(fetchLatest);
};

document.addEventListener('DOMContentLoaded', init);
