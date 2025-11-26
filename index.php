<?php
declare(strict_types=1);

require_once __DIR__ . '/App/Infrastructure/SecurityHeaders.php';
apply_security_headers();
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>FinHub | Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <style>
      :root {
        color-scheme: dark;
      }

      * {
        box-sizing: border-box;
      }

      body {
        min-height: 100vh;
        margin: 0;
        font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        background: radial-gradient(circle at top left, #3b82f6 0%, #312e81 45%, #0b1120 100%);
        color: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px 16px;
        position: relative;
        overflow: hidden;
      }

      body::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image: linear-gradient(120deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px),
          linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px);
        background-size: 140px 140px, 90px 90px;
        opacity: 0.4;
        pointer-events: none;
      }

      main {
        width: 100%;
        max-width: 1120px;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(18px);
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 36px;
        padding: clamp(24px, 5vw, 48px);
        box-shadow: 0 40px 80px rgba(7, 9, 16, 0.65);
        position: relative;
        z-index: 1;
      }

      .grid {
        display: grid;
        grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.1fr);
        gap: clamp(24px, 4vw, 48px);
        align-items: stretch;
      }

      .marketing {
        display: flex;
        flex-direction: column;
        gap: 18px;
        padding-right: clamp(0px, 3vw, 24px);
      }

      .logo {
        font-size: 1.25rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        color: #a5b4fc;
        text-transform: uppercase;
      }

      .marketing h1 {
        font-size: clamp(2rem, 2.8vw, 2.8rem);
        color: #f8fafc;
        margin: 0;
        line-height: 1.2;
      }

      .marketing p {
        margin: 0;
        color: #cbd5f5;
        font-size: 1rem;
      }

      .benefits {
        list-style: none;
        padding: 0;
        margin: 12px 0 24px;
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .benefits li {
        display: flex;
        gap: 10px;
        align-items: flex-start;
        color: #dbeafe;
      }

      .benefits li::before {
        content: "✔";
        color: #34d399;
        font-size: 0.95rem;
        margin-top: 2px;
      }

      .trust-txt {
        font-size: 0.9rem;
        color: #86efac;
        margin-top: auto;
      }

      .form-panel {
        background: rgba(15, 23, 42, 0.65);
        border-radius: 28px;
        border: 1px solid rgba(148, 163, 184, 0.25);
        padding: clamp(24px, 4vw, 36px);
        display: flex;
        flex-direction: column;
        gap: 20px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
      }

      .form-panel h2 {
        margin: 0;
        font-size: 1.65rem;
        color: #f8fafc;
      }

      .form-panel p {
        margin: 0;
        color: #cbd5f5;
        font-size: 0.95rem;
      }

      form {
        display: flex;
        flex-direction: column;
        gap: 18px;
      }

      label {
        font-size: 0.95rem;
        color: #e2e8f0;
        font-weight: 500;
        margin-bottom: 6px;
      }

      .field-wrapper {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }

      input[type="email"],
      input[type="password"] {
        width: 100%;
        padding: 14px 16px;
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: rgba(7, 12, 24, 0.75);
        color: #f8fafc;
        font-size: 1rem;
        transition: border 0.2s ease, box-shadow 0.2s ease;
      }

      input[type="email"]:focus,
      input[type="password"]:focus {
        outline: none;
        border-color: #60a5fa;
        box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25);
      }

      .error-text {
        min-height: 18px;
        font-size: 0.85rem;
        color: #fda4af;
      }

      .remember-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
        color: #94a3b8;
      }

      input[type="checkbox"] {
        accent-color: #22d3ee;
      }

      .primary-btn {
        width: 100%;
        padding: 14px 16px;
        border-radius: 18px;
        border: none;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        background: linear-gradient(135deg, #22d3ee, #14b8a6 45%, #1e40af);
        color: #0b1120;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
      }

      .primary-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 20px 35px rgba(34, 211, 238, 0.35);
      }

      .primary-btn:active {
        transform: translateY(0);
      }

      .links {
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 0.9rem;
      }

      .links a,
      .links button {
        color: #93c5fd;
        text-decoration: none;
        background: none;
        border: none;
        padding: 0;
        text-align: left;
        cursor: pointer;
        font: inherit;
      }

      .links a:hover,
      .links button:hover {
        text-decoration: underline;
      }

      #login-message {
        min-height: 34px;
        border-radius: 12px;
        padding: 10px 14px;
        font-size: 0.95rem;
        background: rgba(59, 130, 246, 0.18);
        color: #bfdbfe;
      }

      #login-message.alert-success {
        background: rgba(16, 185, 129, 0.2);
        color: #bbf7d0;
      }

      #login-message.alert-danger {
        background: rgba(248, 113, 113, 0.2);
        color: #fecaca;
      }

      .demo-btn {
        border-radius: 16px;
        padding: 12px 0;
        font-weight: 500;
        color: #38bdf8;
        border: 1px solid rgba(56, 189, 248, 0.4);
        background: transparent;
        cursor: pointer;
        transition: border 0.2s ease, color 0.2s ease;
        width: 100%;
      }

      .demo-btn:hover {
        border-color: rgba(56, 189, 248, 0.8);
        color: #e0f2fe;
      }

      @media (max-width: 900px) {
        main {
          padding: 28px;
        }

        .grid {
          grid-template-columns: 1fr;
          gap: 32px;
        }

        .marketing {
          text-align: center;
          padding-right: 0;
        }

        .benefits li {
          justify-content: center;
        }

        .trust-txt {
          text-align: center;
        }
      }

      @media (max-width: 600px) {
        body {
          padding: 16px;
        }

        main {
          padding: 24px;
        }

        .form-panel {
          padding: 20px;
        }
      }
    </style>
  </head>
  <body>
    <main>
      <div class="grid">
        <section class="marketing">
          <div class="logo">FinHub</div>
          <h1>FinHub: tu tablero de inversiones en un solo lugar.</h1>
          <p>Conecta tus cuentas y sigue tus inversiones en tiempo real.</p>
          <ul class="benefits">
            <li>Monitoriza portafolios multi-broker con datos al día.</li>
            <li>Alertas inteligentes para movimientos relevantes del mercado.</li>
            <li>Reportes personalizados con insights accionables.</li>
          </ul>
          <p class="trust-txt">Tus datos se protegen con estándares de seguridad bancarios.</p>
        </section>
        <section class="form-panel">
          <div>
            <h2>Accede a tu panel de inversión</h2>
            <p>Introduce tus credenciales para continuar.</p>
          </div>
          <div id="login-message" class="alert-info"></div>
          <form id="login-form" autocomplete="on" novalidate>
            <div class="field-wrapper">
              <label for="email">Correo electrónico</label>
              <input
                type="email"
                id="email"
                name="email"
                placeholder="usuario@ejemplo.com"
                required
              />
              <span id="email-error" class="error-text"></span>
            </div>
            <div class="field-wrapper">
              <label for="password">Contraseña</label>
              <input
                type="password"
                id="password"
                name="password"
                placeholder="********"
                required
              />
              <span id="password-error" class="error-text"></span>
            </div>
            <label class="remember-row">
              <input type="checkbox" id="remember-device" name="remember_device" />
              Recordar este dispositivo
            </label>
            <button type="submit" class="primary-btn">Entrar a FinHub</button>
          </form>
          <div class="links">
            <a href="#" id="forgot-password-link">¿Olvidaste tu contraseña?</a>
            <a href="#" id="register-link">¿Aún no tienes cuenta? Crear cuenta gratuita</a>
            <button type="button" id="demo-mode-btn">Entrar en modo demo</button>
          </div>
          <button type="button" class="demo-btn" id="demo-mode-alt">Entrar en modo demo</button>
        </section>
      </div>
    </main>
    <script src="/frontend/js/login.js" defer></script>
  </body>
</html>
