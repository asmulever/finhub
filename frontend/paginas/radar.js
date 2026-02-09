import { getJson, postJson } from '../apicliente.js';
import { createLoadingOverlay } from '../components/loadingOverlay.js';

const overlay = createLoadingOverlay();
const state = {
  models: [],
  model: '',
  analysis: [],
};

const $ = (id) => document.getElementById(id);
const getId = (m) => (typeof m === 'string' ? m : m.id ?? '');
const setError = (msg, targetId = 'analyze-error') => {
  const el = $(targetId);
  if (el) el.textContent = msg || '';
};

const renderModels = () => {
  const select = $('model-select');
  if (!select) return;
  const errorEl = $('models-error');
  if (errorEl) errorEl.textContent = '';
  select.innerHTML = state.models.map((m) => {
    const id = getId(m);
    const label = id;
    const selected = id === state.model ? 'selected' : '';
    return `<option value="${id}" ${selected}>${label}</option>`;
  }).join('') || `<option value="${state.model || 'auto'}" selected>${state.model || 'auto'}</option>`;
  $('model-pill').textContent = `modelo: ${state.model || 'auto'}`;
};

const normalizeRows = (result) => {
  if (!result) return [];
  if (Array.isArray(result)) return result;
  if (Array.isArray(result.analysis)) return result.analysis;
  if (result.analysis && Array.isArray(result.analysis.analysis)) return result.analysis.analysis;
  return [];
};

const renderAnalysis = (rows) => {
  const body = $('analysis-body');
  if (!body) return;
  if (!rows.length) {
    body.innerHTML = '<tr><td class="muted" colspan="7">Sin resultados</td></tr>';
    return;
  }
  body.innerHTML = rows.map((row) => {
    const decision = (row.decision ?? row.action ?? '').toString().toLowerCase();
    const decisionClass = decision === 'buy' ? 'decision-buy' : decision === 'sell' ? 'decision-sell' : 'decision-hold';
    const conf = row.confidence_pct ?? row.confidence ?? null;
    const horizon = row.horizon_days ?? row.horizon ?? null;
    return `
      <tr>
        <td>${row.symbol ?? ''}</td>
        <td class="${decisionClass}">${decision || '—'}</td>
        <td>${row.thesis ?? row.summary ?? '—'}</td>
        <td>${row.catalysts ?? row.drivers ?? '—'}</td>
        <td>${row.risks ?? '—'}</td>
        <td>${conf !== null ? `${Number(conf).toFixed(0)}%` : '—'}</td>
        <td>${horizon ?? '—'}</td>
      </tr>
    `;
  }).join('');
};

const loadModels = async () => {
  try {
    const data = await getJson('/llm/models/openrouter');
    const items = Array.isArray(data?.data) ? data.data : [];
    if (items.length) {
      state.models = items;
      state.model = getId(items[0]);
    } else {
      state.model = 'openrouter/auto';
    }
  } catch (e) {
    console.warn('No se pudo cargar modelos, uso auto', e);
    const errorEl = $('models-error');
    if (errorEl) errorEl.textContent = 'No se pudieron listar modelos; se usa auto.';
    state.model = 'openrouter/auto';
  }
  renderModels();
};

const loadOrAnalyze = async () => {
  setError('');
  try {
    const result = await postJson('/llm/radar/analyze', { model: state.model, risk_profile: $('risk-select')?.value || 'moderado', note: $('note')?.value || '' });
    $('model-pill').textContent = `modelo: ${result.model || state.model || 'auto'}`;
    const rows = normalizeRows(result);
    renderAnalysis(rows);
  } catch (err) {
    console.error('load/analyze failed', err);
    const message = err?.error?.message || err?.message || 'Fallo la consulta al LLM.';
    setError(message);
  }
};

const analyze = async () => {
  const note = $('note')?.value || '';
  const risk = $('risk-select')?.value || 'moderado';
  const select = $('model-select');
  state.model = select?.value || state.model;
  setError('');
  try {
    const result = await postJson('/llm/radar/analyze', {
      model: state.model,
      risk_profile: risk,
      note,
    });
    $('model-pill').textContent = `modelo: ${state.model || 'auto'}`;
    const rows = normalizeRows(result);
    renderAnalysis(rows);
    setError('');
  } catch (err) {
    console.error('analyze failed', err);
    const message = err?.error?.message || err?.message || 'Fallo la consulta al LLM.';
    setError(message);
  }
};

const init = async () => {
  await overlay.withLoader(loadModels);
  await overlay.withLoader(loadOrAnalyze);
  $('analyze-btn')?.addEventListener('click', () => overlay.withLoader(analyze));
};

document.addEventListener('DOMContentLoaded', init);
