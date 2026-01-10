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
  <title>FinHub | Portafolio RAVA</title>
  <link rel="icon" href="/logo/favicon.png" />
  <script>
    window.__ENV = window.__ENV ?? {};
    window.__ENV.API_BASE_URL = '/api';
  </script>
  <style>
    :root { font-family: 'Inter', system-ui, sans-serif; }
    body { margin: 0; background: radial-gradient(circle at 15% 20%, #111a3a 0%, #0b1224 45%, #0a0f1f 100%); color: #cbd5f5; }
    .content-shell { padding: 24px 32px; }
    main { display: grid; gap: 18px; max-width: 1440px; margin: 0 auto; }
    .card { background: rgba(13, 18, 35, 0.95); border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 16px; padding: 18px 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.4); }
    h2, h3 { margin: 0 0 8px 0; }
    .muted { color: #94a3b8; font-size: 0.95rem; }
    .grid { display: grid; grid-template-columns: repeat(4, minmax(280px, 1fr)); gap: 14px; }
    .tile { background: linear-gradient(145deg, rgba(2, 6, 23, 0.9), rgba(10, 21, 45, 0.9)); border: 1px solid rgba(56, 189, 248, 0.35); border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 6px; transition: transform 120ms ease, border-color 120ms ease; min-height: 180px; }
    .tile:hover { transform: translateY(-2px); border-color: rgba(34, 211, 238, 0.6); }
    .tile strong { font-size: 1.05rem; color: #e2e8f0; }
    .tile small { color: #94a3b8; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .badge-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding: 6px 0; }
    input, select { background: #0f172a; color: #e2e8f0; border: 1px solid rgba(148,163,184,0.4); border-radius: 10px; padding: 9px 12px; min-width: 160px; }
    button { background: linear-gradient(120deg, #22d3ee, #0ea5e9); border: none; color: #0b1021; padding: 9px 12px; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .pill { font-size: 0.82rem; letter-spacing: 0.06em; text-transform: uppercase; padding: 8px 14px; border-radius: 12px; border: 1px solid rgba(56, 189, 248, 0.35); background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(30, 41, 59, 0.8)); color: #e0f2fe; display: inline-flex; align-items: center; justify-content: center; min-height: 36px; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.02); }
    .error { color: #f87171; font-weight: 700; min-height: 20px; }
    .meta-row { display:flex; flex-wrap:wrap; gap:8px; color:#cbd5f5; font-size:0.9rem; }
    .table-wrapper { width: 100%; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; color: #cbd5f5; }
    th, td { border-bottom: 1px solid rgba(148,163,184,0.2); padding: 10px 8px; text-align: left; }
    th { color: #e2e8f0; font-weight: 700; font-size: 0.9rem; }
    td { font-size: 0.9rem; }
    .history-meta { display:flex; gap:10px; flex-wrap:wrap; }
    .pos { color: #22c55e; }
    .neg { color: #ef4444; }
    .tag { padding: 4px 8px; border-radius: 10px; border: 1px solid rgba(148,163,184,0.35); font-size: 0.8rem; color: #94a3b8; }
    .tile footer { display:flex; gap:8px; flex-wrap:wrap; }
    .btn-secondary { background: rgba(56, 189, 248, 0.15); color: #e0f2fe; border: 1px solid rgba(56, 189, 248, 0.4); }
    .deselect-btn { background: #facc15; color: #0f172a; }
  </style>
</head>
<body>
  <div class="content-shell">
    <main>
      <section class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <h2>Portafolio con instrumentos RAVA</h2>
            <p class="muted">Selecciona CEDEARs, Acciones AR y Bonos desde RAVA; agrégalos a tu cartera y consulta históricos.</p>
          </div>
          <div class="badge-row">
            <span class="pill" id="rava-badge-portfolio">En cartera: --</span>
            <span class="pill" id="rava-badge-cedears">CEDEARs: --</span>
            <span class="pill" id="rava-badge-acciones">Acciones: --</span>
            <span class="pill" id="rava-badge-bonos">Bonos: --</span>
            <span class="pill" id="rava-badge-fx">FX USD/ARS: --</span>
          </div>
        </div>
        <div class="actions" style="margin-top:12px;">
          <select id="rava-category">
            <option value="all">Todos</option>
            <option value="selected">En cartera</option>
            <option value="cedears">CEDEARs</option>
            <option value="acciones">Acciones AR</option>
            <option value="bonos">Bonos</option>
          </select>
          <select id="rava-currency">
            <option value="ARS" selected>Mostrar en ARS</option>
            <option value="USD">Mostrar en USD</option>
          </select>
          <input id="rava-search" placeholder="Filtrar por símbolo o nombre..." />
          <button id="rava-btn-reload" type="button">Recargar</button>
        </div>
        <div id="rava-error" class="error"></div>
      </section>

      <section class="card">
        <h3>Instrumentos disponibles</h3>
        <div id="rava-list" class="grid">
          <p class="muted">Cargando...</p>
        </div>
      </section>

      <section class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <h3>Histórico diario (RAVA)</h3>
            <p class="muted">Haz click en cualquier tarjeta para cargar el histórico desde RAVA.</p>
          </div>
          <div class="history-meta">
            <span class="pill" id="history-symbol">Símbolo: --</span>
            <span class="pill" id="history-count">Registros: 0</span>
          </div>
        </div>
        <div class="meta-row">
          <span id="history-status" class="muted">Selecciona un instrumento para ver su histórico.</span>
          <span id="history-meta"></span>
        </div>
        <div id="history-error" class="error"></div>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Apertura</th>
                <th>Máximo</th>
                <th>Mínimo</th>
                <th>Cierre</th>
                <th>Var %</th>
                <th>Volumen</th>
                <th>Ajuste</th>
              </tr>
            </thead>
            <tbody id="historicos-body">
              <tr><td colspan="8" class="muted">Selecciona un instrumento</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
  <script type="module" src="/Frontend/paginas/portafolios.js"></script>
</body>
</html>
