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
  <title>FinHub | Tiingo</title>
  <link rel="icon" href="/logo/favicon.png" />
  <script>
    window.__ENV = window.__ENV ?? {};
    window.__ENV.API_BASE_URL = '/api';
  </script>
  <link rel="preload" href="/logo/full_logoweb.png" as="image" />
  <style>
    :root { font-family: 'Inter', system-ui, sans-serif; }
    body { margin: 0; background: #0b1021; color: #e2e8f0; }
    .content-shell { padding: 24px 32px; }
    main { display: grid; gap: 20px; max-width: 1400px; margin: 0 auto; }
    .card { background: rgba(13, 18, 35, 0.9); border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 14px; padding: 18px 20px; box-shadow: 0 15px 40px rgba(0,0,0,0.35); }
    h2, h3 { margin: 0 0 8px 0; }
    .muted { color: #94a3b8; font-size: 0.95rem; }
    .form-row { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0; }
    input, select { background: #0f172a; color: #e2e8f0; border: 1px solid rgba(148,163,184,0.4); border-radius: 10px; padding: 10px 12px; min-width: 140px; }
    button { background: linear-gradient(120deg, #22d3ee, #0ea5e9); border: none; color: #0b1021; padding: 10px 14px; border-radius: 10px; font-weight: 600; cursor: pointer; }
    pre { background: #0f172a; color: #e2e8f0; border-radius: 10px; padding: 12px; overflow: auto; font-size: 0.9rem; max-height: 260px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
    .error { color: #f87171; font-weight: 600; min-height: 20px; }
  </style>
</head>
<body>
  <div class="content-shell">
    <main>
      <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
          <div>
            <h2>Tiingo</h2>
            <p class="muted">Demos de IEX (tops/last), daily prices, FX/crypto, búsqueda y news. Requiere token y rol admin.</p>
          </div>
        </div>
      </section>

      <section class="card grid">
        <div>
          <h3>IEX TOPS</h3>
          <div class="form-row">
            <input id="tiingo-iex-tickers" placeholder="Tickers separados por coma (ej. AAPL,MSFT)" />
            <button id="tiingo-btn-iex-tops">Consultar</button>
          </div>
          <div id="tiingo-iex-tops-error" class="error"></div>
          <pre id="tiingo-iex-tops-output">{}</pre>
        </div>
        <div>
          <h3>IEX LAST</h3>
          <div class="form-row">
            <input id="tiingo-iex-last" placeholder="Tickers separados por coma" />
            <button id="tiingo-btn-iex-last">Consultar</button>
          </div>
          <div id="tiingo-iex-last-error" class="error"></div>
          <pre id="tiingo-iex-last-output">{}</pre>
        </div>
      </section>

      <section class="card grid">
        <div>
          <h3>DAILY PRICES</h3>
          <div class="form-row">
            <input id="tiingo-daily-symbol" placeholder="Símbolo (ej. AAPL)" />
            <input id="tiingo-daily-start" type="date" />
            <input id="tiingo-daily-end" type="date" />
            <input id="tiingo-daily-freq" placeholder="resampleFreq (ej. daily)" />
            <button id="tiingo-btn-daily">Consultar</button>
          </div>
          <div id="tiingo-daily-error" class="error"></div>
          <pre id="tiingo-daily-output">{}</pre>
        </div>
        <div>
          <h3>DAILY METADATA</h3>
          <div class="form-row">
            <input id="tiingo-meta-symbol" placeholder="Símbolo (ej. AAPL)" />
            <button id="tiingo-btn-meta">Consultar</button>
          </div>
          <div id="tiingo-meta-error" class="error"></div>
          <pre id="tiingo-meta-output">{}</pre>
        </div>
      </section>

      <section class="card grid">
        <div>
          <h3>CRYPTO PRICES</h3>
          <div class="form-row">
            <input id="tiingo-crypto-tickers" placeholder="Tickers (ej. btcusd,ethusd)" />
            <input id="tiingo-crypto-start" type="date" />
            <input id="tiingo-crypto-end" type="date" />
            <input id="tiingo-crypto-freq" placeholder="resampleFreq (ej. 1hour)" />
            <button id="tiingo-btn-crypto">Consultar</button>
          </div>
          <div id="tiingo-crypto-error" class="error"></div>
          <pre id="tiingo-crypto-output">{}</pre>
        </div>
        <div>
          <h3>FX PRICES</h3>
          <div class="form-row">
            <input id="tiingo-fx-tickers" placeholder="Pairs (ej. EURUSD,USDARS)" />
            <input id="tiingo-fx-start" type="date" />
            <input id="tiingo-fx-end" type="date" />
            <input id="tiingo-fx-freq" placeholder="resampleFreq (ej. 1hour)" />
            <button id="tiingo-btn-fx">Consultar</button>
          </div>
          <div id="tiingo-fx-error" class="error"></div>
          <pre id="tiingo-fx-output">{}</pre>
        </div>
      </section>

      <section class="card grid">
        <div>
          <h3>SEARCH</h3>
          <div class="form-row">
            <input id="tiingo-search-query" placeholder="Texto (ej. apple, tesla)" />
            <button id="tiingo-btn-search">Buscar</button>
          </div>
          <div id="tiingo-search-error" class="error"></div>
          <pre id="tiingo-search-output">{}</pre>
        </div>
        <div>
          <h3>NEWS</h3>
          <div class="form-row">
            <input id="tiingo-news-tickers" placeholder="Tickers (ej. AAPL,MSFT)" />
            <input id="tiingo-news-start" type="date" />
            <input id="tiingo-news-end" type="date" />
            <input id="tiingo-news-limit" type="number" min="1" max="100" placeholder="Límite" />
            <button id="tiingo-btn-news">Consultar</button>
          </div>
          <div id="tiingo-news-error" class="error"></div>
          <pre id="tiingo-news-output">{}</pre>
        </div>
      </section>
    </main>
  </div>
  <script type="module" src="/Frontend/paginas/tiingo.js"></script>
</body>
</html>
