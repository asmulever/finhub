import { getJson } from '../apicliente.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  signals: [],
  selected: null,
  lastChartSymbol: '',
};

const formatPct = (v, digits = 2) => (Number.isFinite(v) ? `${(v * 100).toFixed(digits)}%` : '—');
const formatNum = (v, digits = 2) => (Number.isFinite(v) ? v.toFixed(digits) : '—');
const formatAction = (a) => {
  const act = String(a ?? '').toUpperCase();
  if (act === 'BUY') return `<span class="signal-buy">Compra</span>`;
  if (act === 'SELL') return `<span class="signal-sell">Venta</span>`;
  if (act === 'HOLD') return `<span class="signal-hold">Mantener</span>`;
  return act || '—';
};

const setStatus = (msg, type = '') => {
  const el = document.getElementById('status-box');
  if (!el) return;
  el.textContent = msg || '';
  el.className = `status ${type}`;
};

const renderTable = () => {
  const body = document.getElementById('signals-body');
  if (!body) return;
  if (!state.signals.length) {
    body.innerHTML = '<tr><td colspan="9" class="muted">Sin señales disponibles.</td></tr>';
    return;
  }
  body.innerHTML = state.signals.map((s) => {
    const range = `${formatPct(s.range_p10_pct)} · ${formatPct(s.range_p90_pct)}`;
    const stopTake = `${formatNum(s.stop_price)} / ${formatNum(s.take_price)}`;
    return `
      <tr data-symbol="${s.symbol}">
        <td>${s.especie || s.symbol}</td>
        <td>${formatAction(s.action)}</td>
        <td>${formatPct(s.confidence, 1)}</td>
        <td>${s.horizon_days || '—'}d</td>
        <td>${formatPct(s.exp_return_pct)}</td>
        <td>${range}</td>
        <td>${stopTake}</td>
        <td>${s.rationale_short ?? '—'}</td>
        <td><button class="icon-button" data-symbol="${s.symbol}">📈 Gráfico</button></td>
      </tr>
    `;
  }).join('');
  body.querySelectorAll('button[data-symbol]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const sym = btn.getAttribute('data-symbol') || '';
      const signal = state.signals.find((x) => x.symbol === sym);
      if (signal) {
        state.selected = signal;
        renderDetail();
        await overlay.withLoader(() => openChart(signal));
      }
    });
  });
};

const renderDetail = () => {
  const panel = document.getElementById('detail-panel');
  if (!panel) return;
  const s = state.selected;
  if (!s) {
    panel.innerHTML = 'Selecciona una señal.';
    return;
  }
  panel.innerHTML = `
    <div class="grid">
      <div class="stat"><strong>Acción</strong><div>${formatAction(s.action)}</div></div>
      <div class="stat"><strong>Confianza</strong><div>${formatPct(s.confidence,1)}</div></div>
      <div class="stat"><strong>Retorno esperado</strong><div>${formatPct(s.exp_return_pct)}</div></div>
      <div class="stat"><strong>Rango P10-P90</strong><div>${formatPct(s.range_p10_pct)} · ${formatPct(s.range_p90_pct)}</div></div>
      <div class="stat"><strong>Stop / Take</strong><div>${formatNum(s.stop_price)} / ${formatNum(s.take_price)}</div></div>
      <div class="stat"><strong>Trend / Momentum</strong><div>${s.trend_state ?? 'N/D'} · ${s.momentum_state ?? 'N/D'}</div></div>
      <div class="stat"><strong>ATR</strong><div>${formatNum(s.volatility_atr)}</div></div>
      <div class="stat"><strong>Data</strong><div>${s.data_quality ?? 'N/D'} · ${s.data_points_used ?? '—'} velas</div></div>
    </div>
    <p class="muted" style="margin-top:10px;">${s.rationale_short ?? 'Sin explicación'} · Tags: ${(s.rationale_tags ?? []).join(', ')}</p>
  `;
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
  for (let i = 0; i <= count; i++) ticks.push(min + step * i);
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

const drawPriceChart = (points) => {
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
  const closes = points.map((p) => p.close);
  const ema20s = points.map((p) => p.ema20).filter(Number.isFinite);
  const ema50s = points.map((p) => p.ema50).filter(Number.isFinite);
  const bbUpper = points.map((p) => p.bb_upper).filter(Number.isFinite);
  const bbLower = points.map((p) => p.bb_lower).filter(Number.isFinite);
  const ys = closes.concat(ema20s, ema50s, bbUpper, bbLower).filter(Number.isFinite);
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = Math.min(...ys);
  const maxY = Math.max(...ys);
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  const scaleX = (t) => margin.left + ((t - minX) / Math.max(1, maxX - minX)) * width;
  const scaleY = (v) => margin.top + height - ((v - minY) / Math.max(1e-6, maxY - minY)) * height;

  const line = (arr, color) => {
    ctx.strokeStyle = color;
    ctx.beginPath();
    arr.forEach((p, idx) => {
      if (!Number.isFinite(p.v)) return;
      const x = scaleX(p.t.getTime());
      const y = scaleY(p.v);
      if (idx === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    });
    ctx.stroke();
  };
  line(points.map((p) => ({ t: p.t, v: p.close })), '#22d3ee');
  line(points.map((p) => ({ t: p.t, v: p.ema20 })), '#fbbf24');
  line(points.map((p) => ({ t: p.t, v: p.ema50 })), '#a855f7');
  line(points.map((p) => ({ t: p.t, v: p.bb_upper })), 'rgba(14,165,233,0.5)');
  line(points.map((p) => ({ t: p.t, v: p.bb_lower })), 'rgba(14,165,233,0.5)');
  ctx.fillStyle = '#22d3ee';
  ctx.font = '12px Inter, system-ui, sans-serif';
  ctx.fillText('Precio + EMAs + Bollinger', margin.left + 8, margin.top + 14);
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
  const minX = Math.min(...xs);
  const maxX = Math.max(...xs);
  const minY = 0;
  const maxY = 100;
  drawAxes(ctx, margin, width, height, minX, maxX, minY, maxY);
  const scaleX = (t) => margin.left + ((t - minX) / Math.max(1, maxX - minX)) * width;
  const scaleY = (v) => margin.top + height - ((v - minY) / Math.max(1e-6, maxY - minY)) * height;

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
    if (idx === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
  });
  ctx.stroke();
  ctx.fillStyle = '#a855f7';
  ctx.font = '12px Inter, system-ui, sans-serif';
  ctx.fillText('RSI 14', margin.left + 8, margin.top + 14);
};

const openChart = async (signal) => {
  const overlayEl = document.getElementById('chart-overlay');
  if (!overlayEl) return;
  const rangeSelect = document.getElementById('chart-range');
  const rangeVal = rangeSelect?.value || 'all';
  const days = rangeVal === 'all' ? null : Number(rangeVal);
  state.lastChartSymbol = signal.symbol;

  let series = Array.isArray(signal.series_json) ? signal.series_json : [];
  if (!series.length) {
    const period = days === null ? '12m' : (days <= 30 ? '1m' : (days <= 90 ? '3m' : '6m'));
    const resp = await getJson(`/datalake/prices/series?symbol=${encodeURIComponent(signal.especie || signal.symbol)}&period=${period}`);
    const points = Array.isArray(resp?.points) ? resp.points : [];
    series = points.map((p) => ({
      t: p.t ? new Date(p.t) : new Date(),
      close: Number(p.close ?? p.price ?? 0),
      ema20: null,
      ema50: null,
      rsi14: null,
      bb_upper: null,
      bb_lower: null,
    }));
  } else {
    series = series.map((p) => ({
      t: p.t ? new Date(p.t) : new Date(),
      close: Number(p.close ?? 0),
      ema20: Number.isFinite(p.ema20) ? Number(p.ema20) : null,
      ema50: Number.isFinite(p.ema50) ? Number(p.ema50) : null,
      rsi14: Number.isFinite(p.rsi14) ? Number(p.rsi14) : null,
      bb_upper: Number.isFinite(p.bb_upper) ? Number(p.bb_upper) : null,
      bb_lower: Number.isFinite(p.bb_lower) ? Number(p.bb_lower) : null,
    }));
  }

  const filtered = days ? filterByRange(series, days) : series;
  if (!filtered.length) {
    const title = document.getElementById('chart-title');
    const subtitle = document.getElementById('chart-subtitle');
    if (title) title.textContent = signal.especie || signal.symbol;
    if (subtitle) subtitle.textContent = 'Sin datos en el rango seleccionado';
    overlayEl.classList.add('visible');
    overlayEl.setAttribute('aria-hidden', 'false');
    return;
  }

  const rsiPoints = filtered.map((p) => ({ t: p.t, v: p.rsi14 ?? null })).filter((p) => Number.isFinite(p.v));
  drawPriceChart(filtered);
  drawRsiChart(rsiPoints);

  const title = document.getElementById('chart-title');
  const subtitle = document.getElementById('chart-subtitle');
  if (title) title.textContent = signal.especie || signal.symbol;
  if (subtitle) subtitle.textContent = days ? `Histórico ${days}d + Indicadores` : 'Histórico completo + Indicadores';

  overlayEl.classList.add('visible');
  overlayEl.setAttribute('aria-hidden', 'false');
};

const closeOverlay = () => {
  const overlayEl = document.getElementById('chart-overlay');
  if (!overlayEl) return;
  overlayEl.classList.remove('visible');
  overlayEl.setAttribute('aria-hidden', 'true');
};

const fetchSignals = async ({ force = false, collect = false } = {}) => {
  const label = force ? 'Recalculando tendencias...' : 'Cargando señales...';
  setStatus(label, 'info');
  const params = [];
  if (force) params.push('force=1');
  if (collect) params.push('collect=1');
  const query = params.length ? `?${params.join('&')}` : '';
  const resp = await getJson(`/signals/latest${query}`);
  const data = Array.isArray(resp?.data) ? resp.data : [];
  state.signals = data.map((s) => ({
    ...s,
    symbol: (s.symbol ?? '').toUpperCase(),
    especie: (s.especie ?? s.symbol ?? '').toUpperCase(),
    rationale_tags: Array.isArray(s.rationale_tags) ? s.rationale_tags : [],
  }));
  document.getElementById('badge-count').textContent = `${state.signals.length} señales`;
  document.getElementById('badge-updated').textContent = `Actualizado: ${new Date().toISOString().slice(0,16)}`;
  renderTable();
  setStatus('', '');
};

const bindUi = () => {
  document.getElementById('btn-refresh')?.addEventListener('click', () => {
    overlay.withLoader(() => fetchSignals({ force: true, collect: true }))
      .catch((error) => {
        console.info('[trader-consejero] No se pudieron actualizar tendencias', error);
        setStatus('No se pudieron actualizar las tendencias', 'error');
      });
  });
  document.getElementById('chart-close-btn')?.addEventListener('click', closeOverlay);
  document.getElementById('chart-overlay')?.addEventListener('click', (e) => {
    if (e.target?.id === 'chart-overlay') closeOverlay();
  });
  document.getElementById('chart-range')?.addEventListener('change', () => {
    const signal = state.signals.find((s) => s.symbol === state.lastChartSymbol);
    if (signal) {
      overlay.withLoader(() => openChart(signal)).catch(() => setStatus('No se pudo recargar gráfico', 'error'));
    }
  });
};

const init = async () => {
  bindUi();
  try {
    await overlay.withLoader(() => fetchSignals({ force: true, collect: true }));
  } catch (error) {
    console.info('[trader-consejero] No se pudieron cargar señales iniciales', error);
    setStatus('No se pudieron cargar las señales iniciales', 'error');
  }
};

document.addEventListener('DOMContentLoaded', init);
