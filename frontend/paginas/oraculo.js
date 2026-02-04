import { getJson, postJson } from '../apicliente.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  signals: [],
  sizing: [],
  backtestId: null,
  backtestMetrics: null,
  backtestEquity: [],
  backtestTrades: [],
};

const formatPct = (v, digits = 1) => (Number.isFinite(v) ? `${(v * 100).toFixed(digits)}%` : '—');
const formatNum = (v, digits = 2) => (Number.isFinite(v) ? v.toFixed(digits) : '—');
const formatAction = (a) => {
  const act = String(a ?? '').toUpperCase();
  if (act === 'BUY') return 'Compra';
  if (act === 'SELL') return 'Venta';
  if (act === 'HOLD') return 'Mantener';
  return act || '—';
};

const setStatus = (msg, type = '') => {
  const el = document.getElementById('signals-status');
  if (!el) return;
  el.textContent = msg || '';
  el.className = `status ${type}`.trim();
};

const setError = (id, msg) => {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg || '';
};

const switchTab = (tab) => {
  document.querySelectorAll('.tab-btn').forEach((btn) => {
    const isActive = btn.getAttribute('data-tab') === tab;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-selected', String(isActive));
  });
  document.querySelectorAll('.tab-panel').forEach((panel) => {
    panel.classList.toggle('active', panel.getAttribute('data-tab-panel') === tab);
  });
};

const bindTabs = () => {
  document.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      const tab = btn.getAttribute('data-tab') || 'senales';
      switchTab(tab);
    });
  });
};

const normalizeSignal = (s) => ({
  ...s,
  symbol: String(s.symbol ?? s.especie ?? '').toUpperCase(),
  especie: String(s.especie ?? s.symbol ?? '').toUpperCase(),
  strategy: s.strategy_name ?? s.strategy_id ?? 'N/D',
  regime: s.regime ?? s.regimen ?? null,
  holding: s.holding_expectation ?? (Number.isFinite(s.horizon_days) ? `${s.horizon_days}d` : null),
  entry_reference: Number.isFinite(s.entry_reference) ? Number(s.entry_reference) : Number.isFinite(s.price_last) ? Number(s.price_last) : null,
  expected_R: Number.isFinite(s.expected_R) ? Number(s.expected_R) : (Number.isFinite(s.risk_reward) ? Number(s.risk_reward) : null),
  expected_dd_p90: Number.isFinite(s.expected_dd_p90) ? Number(s.expected_dd_p90) : null,
});

const renderSignals = () => {
  const body = document.getElementById('signals-body');
  if (!body) return;
  if (!state.signals.length) {
    body.innerHTML = '<tr><td colspan="11" class="muted">Sin señales disponibles.</td></tr>';
    return;
  }
  body.innerHTML = state.signals.map((s) => {
    const stopTake = `${formatNum(s.stop_price)} / ${formatNum(s.take_price)}`;
    const expectedDd = s.expected_dd_p90 ?? s.range_p90_pct ?? null;
    const expectedR = Number.isFinite(s.expected_R)
      ? `${s.expected_R.toFixed(2)}R`
      : (Number.isFinite(s.risk_reward) ? `${s.risk_reward.toFixed(2)}R` : '—');
    const rationale = s.rationale_short ?? '—';
    return `
      <tr>
        <td>${s.symbol}</td>
        <td>${formatAction(s.action)}</td>
        <td>${formatPct(s.confidence, 1)}</td>
        <td>${s.holding ?? `${s.horizon_days ?? '—'}d`}</td>
        <td>${(s.regime ?? '').toString().toUpperCase() || '—'}</td>
        <td>${s.strategy}</td>
        <td>${formatNum(s.entry_reference)}</td>
        <td>${stopTake}</td>
        <td>${expectedR}</td>
        <td>${expectedDd ? formatPct(expectedDd) : '—'}</td>
        <td><span class="tooltip" title="${rationale}">ℹ️</span></td>
      </tr>
    `;
  }).join('');
};

const computeSizing = (capital, riskPct, maxDdPct) => {
  const results = [];
  const riskAmount = capital * (riskPct / 100);
  state.signals.forEach((s) => {
    const entry = Number(s.entry_reference);
    const stop = Number(s.stop_price);
    if (!Number.isFinite(entry) || !Number.isFinite(stop) || entry <= 0 || stop <= 0 || entry === stop) {
      return;
    }
    const riskPerUnit = Math.abs(entry - stop);
    if (riskPerUnit <= 0) {
      return;
    }
    const sizeUnits = Math.max(0, Math.floor(riskAmount / riskPerUnit));
    const capitalUsed = sizeUnits * entry;
    const riskMoney = sizeUnits * riskPerUnit;
    const expectedR = Number.isFinite(s.expected_R)
      ? `${s.expected_R.toFixed(2)}R`
      : (Number.isFinite(s.risk_reward) ? `${s.risk_reward.toFixed(2)}R` : '—');
    const ddPct = Number.isFinite(s.expected_dd_p90) ? s.expected_dd_p90 : (Number.isFinite(s.range_p90_pct) ? Math.abs(s.range_p90_pct) : null);
    const ddMoney = Number.isFinite(ddPct) ? capital * ddPct : null;
    results.push({
      symbol: s.symbol,
      action: s.action,
      stopDistance: riskPerUnit,
      sizeUnits,
      capitalUsed,
      riskMoney,
      expectedR,
      expectedDd: ddMoney,
      ddPct,
      exceedsDd: Number.isFinite(ddPct) ? (ddPct * 100 > maxDdPct) : false,
    });
  });
  return results;
};

const renderRisk = (capital, riskPct, maxDdPct) => {
  const body = document.getElementById('risk-body');
  if (!body) return;
  const rows = computeSizing(capital, riskPct, maxDdPct);
  state.sizing = rows;
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="8" class="muted">No hay stops válidos para calcular tamaño.</td></tr>';
    return;
  }
  body.innerHTML = rows.map((r) => `
    <tr>
      <td>${r.symbol}</td>
      <td>${formatAction(r.action)}</td>
      <td>${formatNum(r.stopDistance)}</td>
      <td>${r.sizeUnits}</td>
      <td>${formatNum(r.capitalUsed, 0)}</td>
      <td>${formatNum(r.riskMoney, 0)}</td>
      <td>${r.expectedR}</td>
      <td>${r.expectedDd ? `${formatNum(r.expectedDd, 0)} (${formatPct(r.ddPct)})${r.exceedsDd ? ' ⚠️' : ''}` : '—'}</td>
    </tr>
  `).join('');
};

const renderProjections = () => {
  const container = document.getElementById('projections-cards');
  if (!container) return;
  if (!state.signals.length) {
    container.innerHTML = '<div class="muted">Carga señales para estimar escenarios.</div>';
    return;
  }
  container.innerHTML = state.signals.map((s) => {
    const p50 = Number.isFinite(s.range_p50_pct) ? formatPct(s.range_p50_pct) : '—';
    const p90 = Number.isFinite(s.range_p90_pct) ? formatPct(s.range_p90_pct) : '—';
    const dd = Number.isFinite(s.expected_dd_p90) ? formatPct(s.expected_dd_p90) : '—';
    const exp = Number.isFinite(s.exp_return_pct) ? formatPct(s.exp_return_pct) : '—';
    return `
      <div class="card-item">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
          <strong>${s.symbol}</strong>
          <span class="tag">${formatAction(s.action)}</span>
        </div>
        <div class="soft">P50: ${p50} · P90: ${p90}</div>
        <div class="soft">Retorno esperado: ${exp}</div>
        <div class="soft">DD esperado P90: ${dd}</div>
        <div class="muted" style="font-size:0.88rem;">Confianza ${formatPct(s.confidence,1)} · Régimen ${(s.regime ?? '').toString().toUpperCase() || '—'} · Estrategia ${s.strategy}</div>
      </div>
    `;
  }).join('');
};

const renderBacktests = () => {
  const container = document.getElementById('backtest-cards');
  const badge = document.getElementById('backtest-badge');
  if (!container || !badge) return;
  if (state.backtestMetrics) {
    renderBacktestSummaryCards();
    return;
  }
  const withBacktest = state.signals.filter((s) => s.backtest_ref);
  if (!withBacktest.length) {
    container.innerHTML = '<div class="muted">Las señales actuales no traen referencias de backtest.</div>';
    badge.textContent = 'Sin backtests cargados';
    return;
  }
  badge.textContent = `${withBacktest.length} backtests referenciados`;
  container.innerHTML = withBacktest.map((s) => `
    <div class="card-item">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
        <strong>${s.symbol}</strong>
        <span class="tag">Ref: ${s.backtest_ref}</span>
      </div>
      <div class="soft">Estrategia: ${s.strategy}</div>
      <div class="soft">Acción: ${formatAction(s.action)} · Confianza ${formatPct(s.confidence,1)}</div>
      <div class="muted" style="font-size:0.88rem;">Solicita métricas /backtests/{id}/metrics para mostrar CAGR, DD y Sharpe.</div>
    </div>
  `).join('');
};

const updateBadges = () => {
  const count = document.getElementById('badge-signals');
  const updated = document.getElementById('badge-updated');
  if (count) count.textContent = `${state.signals.length} señales`;
  if (updated) updated.textContent = `Actualizado: ${new Date().toISOString().slice(0, 16)}`;
};

const setBacktestStatus = (msg, type = '') => {
  const el = document.getElementById('backtest-status');
  if (!el) return;
  el.textContent = msg || '';
  el.className = `status ${type}`.trim();
};

const setBacktestError = (msg) => setError('backtest-error', msg);

const backtestPresets = {
  conservador: {
    risk_per_trade_pct: 1.0,
    commission_pct: 0.6,
    min_fee: 0,
    slippage_bps: 6,
    spread_bps: 4,
    breakout_lookback_buy: 20,
    breakout_lookback_sell: 10,
    atr_multiplier: 2.0,
  },
  base: {
    risk_per_trade_pct: 1.5,
    commission_pct: 0.6,
    min_fee: 0,
    slippage_bps: 4,
    spread_bps: 3,
    breakout_lookback_buy: 15,
    breakout_lookback_sell: 7,
    atr_multiplier: 1.2,
  },
  agresivo: {
    risk_per_trade_pct: 2.0,
    commission_pct: 0.6,
    min_fee: 0,
    slippage_bps: 3,
    spread_bps: 2,
    breakout_lookback_buy: 10,
    breakout_lookback_sell: 5,
    atr_multiplier: 1.0,
  },
};

const windowMonths = {
  '6m': 6,
  '1y': 12,
  '2y': 24,
};

const getUniverse = () => {
  const auto = Array.from(new Set(state.signals.map((s) => s.symbol))).filter(Boolean);
  const input = (document.getElementById('bt-universe')?.value || '').trim();
  const manual = input ? input.split(',').map((s) => s.trim().toUpperCase()).filter(Boolean) : [];
  return manual.length ? manual : auto;
};

const setUniverseDisplay = () => {
  const input = document.getElementById('bt-universe');
  if (!input) return;
  const auto = Array.from(new Set(state.signals.map((s) => s.symbol))).filter(Boolean);
  input.value = auto.join(',') || '';
};

const computeWindow = (windowKey) => {
  const months = windowMonths[windowKey] ?? 12;
  const end = new Date();
  const start = new Date();
  start.setMonth(start.getMonth() - months);
  const toISO = (d) => d.toISOString().slice(0, 10);
  return { start: toISO(start), end: toISO(end) };
};

const renderBacktestMetrics = () => {
  const box = document.getElementById('backtest-metrics');
  const badge = document.getElementById('backtest-badge');
  if (!box || !badge) return;
  if (!state.backtestMetrics) {
    box.innerHTML = '<strong>Métricas</strong><p class="muted">Ejecuta un backtest para ver resultados.</p>';
    badge.textContent = 'Listo';
    return;
  }
  const m = state.backtestMetrics;
  badge.textContent = `Backtest #${state.backtestId}`;
  box.innerHTML = `
    <strong>Métricas</strong>
    <div class="grid" style="margin-top:8px;">
      <div class="stat"><div><strong>CAGR</strong></div><div class="soft">${formatPct(m.cagr ?? m.CAGR ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Max DD</strong></div><div class="soft">${formatPct(m.max_drawdown ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Sharpe</strong></div><div class="soft">${formatNum(m.sharpe ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Sortino</strong></div><div class="soft">${formatNum(m.sortino ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Win rate</strong></div><div class="soft">${formatPct(m.win_rate ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Profit factor</strong></div><div class="soft">${formatNum(m.profit_factor ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Expectancy</strong></div><div class="soft">${formatNum(m.expectancy ?? 0, 2)}</div></div>
      <div class="stat"><div><strong>Exposure</strong></div><div class="soft">${formatPct(m.exposure ?? 0, 2)}</div></div>
    </div>
  `;
};

const renderBacktestEquity = () => {
  const body = document.getElementById('backtest-equity-body');
  if (!body) return;
  const rows = (state.backtestEquity || []).filter(
    (row) => row && row.ts && Number.isFinite(row.equity) && Number.isFinite(row.drawdown)
  );
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="3" class="muted">Sin equity.</td></tr>';
    return;
  }
  body.innerHTML = rows.slice(-200).map((row) => `
    <tr>
      <td>${row.ts}</td>
      <td>${formatNum(row.equity, 2)}</td>
      <td>${formatPct(row.drawdown, 2)}</td>
    </tr>
  `).join('');
};

const renderBacktestTrades = () => {
  const body = document.getElementById('backtest-trades-body');
  if (!body) return;
  if (!state.backtestTrades.length) {
    body.innerHTML = '<tr><td colspan="6" class="muted">Sin trades.</td></tr>';
    return;
  }
  body.innerHTML = state.backtestTrades.map((t) => `
    <tr>
      <td>${t.symbol}</td>
      <td>${t.entry_ts} @ ${formatNum(t.entry_price, 2)}</td>
      <td>${t.exit_ts} @ ${formatNum(t.exit_price, 2)}</td>
      <td>${t.qty}</td>
      <td>${formatNum(t.pnl_net, 2)}</td>
      <td>${t.exit_reason}</td>
    </tr>
  `).join('');
};

const renderBacktestSummaryCards = () => {
  const container = document.getElementById('backtest-cards');
  if (!container) return;
  if (!state.backtestMetrics) {
    container.innerHTML = '<div class="muted">Ejecuta un backtest para ver el resumen.</div>';
    return;
  }
  const lastEquity = state.backtestEquity.length ? state.backtestEquity[state.backtestEquity.length - 1].equity : null;
  container.innerHTML = `
    <div class="card-item">
      <div><strong>Resultado</strong></div>
      <div class="soft">Equity final: ${lastEquity ? formatNum(lastEquity, 2) : 'N/D'}</div>
      <div class="soft">Trades: ${state.backtestTrades.length}</div>
      <div class="muted" style="font-size:0.88rem;">Ref: backtest #${state.backtestId} · Guardado en señales</div>
    </div>
  `;
};

const loadBacktestDetails = async (id) => {
  const [metrics, equityResp, tradesResp] = await Promise.all([
    getJson(`/backtests/${id}/metrics`),
    getJson(`/backtests/${id}/equity`),
    getJson(`/backtests/${id}/trades`),
  ]);
  state.backtestMetrics = metrics ?? null;
  state.backtestEquity = Array.isArray(equityResp?.data) ? equityResp.data : [];
  state.backtestTrades = Array.isArray(tradesResp?.data) ? tradesResp.data : [];
  renderBacktestMetrics();
  renderBacktestEquity();
  renderBacktestTrades();
  renderBacktestSummaryCards();
};

const runBacktest = async () => {
  setBacktestError('');
  const universe = getUniverse();
  if (!universe.length) {
    setBacktestError('No hay universo detectado (carga señales o define tickers).');
    return;
  }
  const windowKey = document.getElementById('bt-window')?.value || '1y';
  const profile = document.getElementById('bt-profile')?.value || 'base';
  const dates = computeWindow(windowKey);
  const preset = backtestPresets[profile] || backtestPresets.base;
  const payload = {
    strategy_id: 'trend_breakout',
    universe,
    start: dates.start,
    end: dates.end,
    initial_capital: 100000,
    ...preset,
  };
  setBacktestStatus(`Ejecutando backtest (${profile}, ${windowKey})...`, 'info');
  try {
    const resp = await postJson('/backtests/run', payload);
    const id = resp?.id;
    state.backtestId = id;
    setBacktestStatus(`Backtest #${id} completado`, '');
    await loadBacktestDetails(id);
    await loadSignals({ force: false });
  } catch (error) {
    console.info('[oraculo] Backtest error', error);
    setBacktestStatus('Error al ejecutar backtest', 'error');
    setBacktestError(error?.error?.message ?? 'No se pudo ejecutar el backtest');
  }
};

const fetchSignals = async ({ force = false, collect = false } = {}) => {
  const params = [];
  if (force) params.push('force=1');
  if (collect) params.push('collect=1');
  const query = params.length ? `?${params.join('&')}` : '';
  const resp = await getJson(`/signals/latest${query}`);
  const data = Array.isArray(resp?.data) ? resp.data : [];
  return data.map(normalizeSignal);
};

const loadSignals = async ({ force = false } = {}) => {
  setError('signals-error', '');
  setStatus(force ? 'Recalculando señales...' : 'Cargando señales...');
  try {
    const signals = await fetchSignals({ force, collect: true });
    state.signals = signals;
    renderSignals();
    renderProjections();
    renderBacktests();
    const capital = Number(document.getElementById('capital-input')?.value ?? 0) || 0;
    const riskPct = Number(document.getElementById('risk-input')?.value ?? 0) || 0;
    const maxDdPct = Number(document.getElementById('dd-input')?.value ?? 0) || 0;
    renderRisk(capital, riskPct, maxDdPct);
    updateBadges();
    setStatus('', '');
  } catch (error) {
    console.info('[oraculo] No se pudieron cargar señales', error);
    setError('signals-error', 'No se pudieron cargar las señales.');
    setStatus('Error al cargar señales', 'error');
  }
};

const bindInputs = () => {
  document.getElementById('btn-refresh')?.addEventListener('click', () => {
    overlay.withLoader(() => loadSignals({ force: true })).catch((error) => {
      console.info('[oraculo] No se pudo recalcular señales', error);
      setStatus('Error al recalcular', 'error');
    });
  });

  const recalc = () => {
    const capital = Number(document.getElementById('capital-input')?.value ?? 0) || 0;
    const riskPct = Number(document.getElementById('risk-input')?.value ?? 0) || 0;
    const maxDdPct = Number(document.getElementById('dd-input')?.value ?? 0) || 0;
    renderRisk(capital, riskPct, maxDdPct);
  };

  document.getElementById('btn-recalc-risk')?.addEventListener('click', recalc);
  document.getElementById('capital-input')?.addEventListener('change', recalc);
  document.getElementById('risk-input')?.addEventListener('change', recalc);
  document.getElementById('dd-input')?.addEventListener('change', recalc);

  document.getElementById('btn-run-backtest')?.addEventListener('click', () => {
    overlay.withLoader(() => runBacktest()).catch(() => {
      setBacktestStatus('Error al ejecutar backtest', 'error');
    });
  });
};

const init = async () => {
  bindTabs();
  bindInputs();
  try {
    await overlay.withLoader(() => loadSignals({ force: true }));
    setUniverseDisplay();
  } catch (error) {
    console.info('[oraculo] Error inicial', error);
  }
};

document.addEventListener('DOMContentLoaded', init);
