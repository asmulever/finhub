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
  <title>FinHub | Data Lake</title>
  <link rel="icon" href="/logo/favicon.png" />
  <script>
    window.__ENV = window.__ENV ?? {};
    window.__ENV.API_BASE_URL = '/api';
  </script>
  <link rel="preload" href="/logo/full_logoweb.png" as="image" />
  <style>
    :root { font-family: 'Inter', system-ui, sans-serif; }
    body { margin: 0; background: radial-gradient(circle at 15% 20%, #0b1021 0%, #050915 45%, #030712 100%); color: #e2e8f0; min-height: 100vh; }
    .content-shell { padding: 18px 22px; max-width: 1440px; margin: 0 auto; }
    main { display: grid; gap: 14px; }
    .card { background: rgba(15, 23, 42, 0.95); border-radius: 16px; padding: 18px 20px; border: 1px solid rgba(148, 163, 184, 0.3); box-shadow: 0 20px 45px rgba(2,6,23,0.6); }
    h2, h3 { margin: 0 0 6px 0; }
    .muted { color: #94a3b8; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
    .tab-btn { padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(148,163,184,0.35); background: rgba(148,163,184,0.06); color: #e2e8f0; cursor: pointer; font-weight: 600; }
    .tab-btn.active { border-color: rgba(34,211,238,0.6); background: rgba(34,211,238,0.12); color: #e0f2fe; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    input, select, button { background: #0f172a; color: #e2e8f0; border: 1px solid rgba(148,163,184,0.4); border-radius: 10px; padding: 9px 12px; }
    button { cursor: pointer; font-weight: 700; }
    button.primary { background: linear-gradient(120deg, #22d3ee, #0ea5e9); border: none; color: #0b1021; }
    button.danger { background: #ef4444; border: none; color: #0b1021; }
    table { width: 100%; border-collapse: collapse; color: #cbd5f5; }
    th, td { padding: 8px 6px; border-bottom: 1px solid rgba(148,163,184,0.2); text-align: left; }
    th { color: #e2e8f0; font-weight: 700; font-size: 0.9rem; }
    td { font-size: 0.9rem; }
    .table-wrapper { width: 100%; overflow-x: auto; }
    .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(56, 189, 248, 0.35); background: rgba(14, 165, 233, 0.08); color: #e0f2fe; font-size: 0.85rem; }
    .section-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
    .block { background: rgba(148,163,184,0.05); border: 1px solid rgba(148,163,184,0.25); border-radius: 12px; padding: 12px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; }
    .alert { background: rgba(239,68,68,0.12); color: #fecdd3; border: 1px solid rgba(239,68,68,0.3); padding: 8px 10px; border-radius: 10px; font-weight: 600; }
    .badge { padding: 4px 8px; border-radius: 8px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59,130,246,0.4); color: #bfdbfe; font-size: 0.8rem; }
    .meta-row { display: flex; gap: 10px; flex-wrap: wrap; color: #94a3b8; font-size: 0.9rem; }
  </style>
</head>
<body>
  <div class="content-shell">
    <main>
      <section class="card">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;">
          <div>
            <h2>Data Lake</h2>
            <p class="muted">Administra catálogo, snapshots y series con controles de Admin.</p>
          </div>
          <div class="meta-row">
            <span class="pill" id="meta-role">Rol: --</span>
            <span class="pill" id="meta-symbols">Símbolos: --</span>
          </div>
        </div>
        <div class="tabs" style="margin-top:12px;">
          <button class="tab-btn active" data-tab="snapshots">Snapshots</button>
          <button class="tab-btn" data-tab="series">Series</button>
        </div>

        <div class="tab-panel" id="tab-snapshots" style="margin-top:12px;">
          <div class="section-grid">
            <div class="block">
              <h3>Snapshots</h3>
              <p class="muted">Consulta el último snapshot y ejecuta ingestas.</p>
              <div class="actions" style="margin:8px 0;">
                <select id="snapshot-symbol"></select>
                <button id="snapshot-load" type="button">Cargar último</button>
                <button id="collect-btn" class="primary" type="button">Recolectar ahora</button>
              </div>
              <div class="meta-row" id="snapshot-meta"></div>
              <div class="table-wrapper" style="margin-top:8px;">
                <table>
                  <tbody id="snapshot-body">
                    <tr><td class="muted">Sin datos</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="block">
              <h3>Log de ingesta</h3>
              <textarea id="ingestion-log" readonly style="width:100%;height:240px;background:#0b1224;color:#e2e8f0;border:1px solid rgba(148,163,184,0.35);border-radius:10px;padding:10px;font-family:'JetBrains Mono','Fira Code',monospace;"></textarea>
            </div>
          </div>
        </div>

        <div class="tab-panel" id="tab-series" style="margin-top:12px;">
          <div class="section-grid">
            <div class="block">
              <h3>Series</h3>
              <p class="muted">Consulta series por símbolo y rango.</p>
              <div class="actions" style="margin:8px 0;">
                <select id="series-symbol"></select>
                <select id="series-period">
                  <option value="1m">1 mes</option>
                  <option value="3m">3 meses</option>
                  <option value="6m">6 meses</option>
                  <option value="1y">1 año</option>
                </select>
                <button id="series-load" type="button">Cargar serie</button>
              </div>
              <div class="table-wrapper" style="margin-top:8px;">
                <table>
                  <thead>
                    <tr>
                      <th>Fecha</th>
                      <th>Open</th>
                      <th>High</th>
                      <th>Low</th>
                      <th>Close</th>
                    </tr>
                  </thead>
                  <tbody id="series-body">
                    <tr><td class="muted" colspan="5">Sin datos</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="block">
              <div class="alert">Eliminar/editar series puede afectar integridad histórica. Operaciones de borrado no expuestas.</div>
              <div id="series-meta" class="muted" style="margin-top:8px;"></div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>
  <script type="module" src="/Frontend/paginas/datalake.js"></script>
</body>
</html>
