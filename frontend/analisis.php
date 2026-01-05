<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>FinHub | Análisis Heatmap</title>
  <link rel="icon" href="/logo/favicon.png" />
  <script>
    window.__ENV = window.__ENV ?? {};
    window.__ENV.API_BASE_URL = '/api';
  </script>
  <link rel="preload" href="/logo/full_logoweb.png" as="image" />
  <style>
    :root { font-family: 'Inter', system-ui, sans-serif; }
    body { margin: 0; background: #050915; color: #e2e8f0; }
    .content-shell { padding: 22px 30px; }
    main { display: grid; gap: 16px; max-width: 1500px; margin: 0 auto; }
    .card { background: rgba(10, 14, 28, 0.92); border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 16px; padding: 16px 18px; box-shadow: 0 15px 40px rgba(0,0,0,0.35); }
    h2, h3 { margin: 0 0 8px 0; }
    .muted { color: #94a3b8; font-size: 0.95rem; }
    .eyebrow { text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; color: #38bdf8; font-size: 0.78rem; margin: 0 0 6px 0; }
    .hero { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 6px 12px; font-size: 0.85rem; border: 1px solid rgba(56, 189, 248, 0.35); background: rgba(14, 165, 233, 0.08); color: #e0f2fe; }
    .controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    select, button { background: #0f172a; color: #e2e8f0; border: 1px solid rgba(148,163,184,0.4); border-radius: 10px; padding: 9px 12px; min-width: 150px; }
    button.action { background: linear-gradient(120deg, #22d3ee, #0ea5e9); border: none; color: #0b1021; min-width: auto; font-weight: 700; }
    .toggle-group { display: inline-flex; gap: 8px; }
    .toggle { border: 1px solid rgba(148,163,184,0.4); background: rgba(15, 23, 42, 0.7); color: #e2e8f0; padding: 8px 12px; border-radius: 10px; cursor: pointer; font-weight: 600; }
    .toggle.active { border-color: rgba(56, 189, 248, 0.8); background: rgba(14, 165, 233, 0.18); color: #e0f2fe; }
    .status-row { display: flex; gap: 12px; flex-wrap: wrap; margin: 8px 0 4px 0; color: #cbd5f5; font-size: 0.92rem; }
    .error { color: #f87171; font-weight: 700; min-height: 20px; }
    .heatmap { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
    .tile { border-radius: 12px; padding: 10px; color: #0b1021; min-height: 110px; position: relative; overflow: hidden; cursor: pointer; border: 1px solid rgba(255,255,255,0.04); }
    .tile:hover { outline: 2px solid rgba(255,255,255,0.15); }
    .tile .symbol { font-size: 1rem; font-weight: 800; margin-bottom: 4px; color: #0b1021; }
    .tile .name { font-size: 0.82rem; color: rgba(0,0,0,0.7); }
    .tile .value { font-size: 1.1rem; font-weight: 800; margin-top: 6px; }
    .tile .panel { position: absolute; top: 8px; right: 8px; font-size: 0.78rem; font-weight: 700; }
    .legend { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; font-size: 0.9rem; color: #cbd5f5; }
    .legend span { display: inline-block; padding: 6px 8px; border-radius: 8px; }
    .chart-shell { border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 12px; padding: 10px; background: rgba(15, 23, 42, 0.7); }
    canvas { width: 100%; max-width: 100%; border-radius: 8px; background: #0f172a; }
    @media (max-width: 768px) {
      .content-shell { padding: 16px; }
      select, button { min-width: 130px; }
    }
  </style>
</head>
<body>
  <div class="content-shell">
    <main>
      <section class="card hero">
        <div>
          <p class="eyebrow">Análisis · Heatmap</p>
          <h2>Instrumentos de portafolios (sin duplicados)</h2>
          <p class="muted">Tiles coloreados por retorno IRS en la ventana seleccionada. Click para enfocar el gráfico de evolución normalizada.</p>
        </div>
        <div class="meta">
          <span class="badge" id="badge-count">Instrumentos: --</span>
          <span class="badge" id="badge-histos">Históricos: --</span>
        </div>
      </section>

      <section class="card">
        <div class="controls">
          <div class="toggle-group" id="range-group">
            <button class="toggle active" data-range="3m">3M</button>
            <button class="toggle" data-range="6m">6M</button>
            <button class="toggle" data-range="1y">1A</button>
          </div>
          <select id="instrument-select">
            <option value="ALL">Todos los instrumentos</option>
          </select>
          <button id="btn-reload" class="action" type="button">Recargar</button>
        </div>
        <div class="status-row">
          <span id="status-updated">Actualizado: --</span>
          <span id="status-meta"></span>
        </div>
        <div id="an-error" class="error"></div>
        <div class="legend">
          <span style="background:#16a34a33; border:1px solid #16a34a55;">≥ +25%</span>
          <span style="background:#22c55e33; border:1px solid #22c55e55;">+10% a +25%</span>
          <span style="background:#10b98122; border:1px solid #10b98144;">0% a +10%</span>
          <span style="background:#f8717122; border:1px solid #f8717144;">0% a -10%</span>
          <span style="background:#ef444422; border:1px solid #ef444444;">-10% a -25%</span>
          <span style="background:#b91c1c33; border:1px solid #b91c1c55;">≤ -25%</span>
        </div>
      </section>

      <section class="card">
        <h3>Heatmap (IRS por rango seleccionado)</h3>
        <div id="heatmap" class="heatmap"></div>
      </section>

      <section class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <h3>Evolución normalizada</h3>
            <p class="muted">Ver todos (promedio normalizado) o un instrumento puntual. Ventana: rango seleccionado.</p>
          </div>
          <div class="badge" id="chart-meta">Serie: --</div>
        </div>
        <div class="chart-shell">
          <canvas id="evolution-chart" width="1000" height="360"></canvas>
        </div>
      </section>
    </main>
  </div>
  <script type="module" src="/Frontend/paginas/analisis.js"></script>
</body>
</html>
