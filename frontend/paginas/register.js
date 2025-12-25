import { postJson } from '../apicliente.js';

const emailPattern = '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$';

const render = () => {
  const app = document.getElementById('app');
  if (!app) return;
  app.innerHTML = `
    <div class="landing-grid">
      <div class="hero">
        <img src="/logo/full_logoweb.png" alt="FinHub" class="logo" />
        <h1>Crea tu acceso a FinHub.</h1>
        <p class="tagline">Registro seguro para operar con datos financieros.</p>
        <p class="description">Los nuevos usuarios quedan en estado inactivo hasta aprobación. Recibirás acceso al panel una vez validado.</p>
        <ul class="feature-list">
          <li class="feature">Datos históricos y en tiempo real.</li>
          <li class="feature">Pipeline trazable y auditable.</li>
          <li class="feature">Control de acceso centralizado.</li>
        </ul>
      </div>
      <section class="panel login-card">
        <div class="panel-content">
          <div class="login-header">
            <p class="caption">Registro</p>
            <h2>Crear cuenta FinHub</h2>
          </div>
          <p class="panel-copy">Completa tus datos. Tu cuenta se creará como <strong>user</strong> y <strong>inactive</strong> hasta aprobación.</p>
          <form id="register-form">
            <div class="input-grid">
              <div class="form-group">
                <label for="email">Correo</label>
                <div class="input-field">
                  <input id="email" name="email" type="email" required autocomplete="username" pattern="${emailPattern}" />
                </div>
              </div>
              <div class="form-group password-group">
                <label for="password">Contraseña</label>
                <div class="password-field">
                  <input id="password" name="password" type="password" required autocomplete="new-password" />
                </div>
              </div>
              <div class="form-group password-group">
                <label for="password-confirm">Confirmar contraseña</label>
                <div class="password-field">
                  <input id="password-confirm" name="password-confirm" type="password" required autocomplete="new-password" />
                </div>
              </div>
            </div>
            <button type="submit">Registrarme</button>
            <p id="error-message" class="error" aria-live="polite"></p>
            <p class="muted">¿Ya tienes cuenta? <a href="/">Inicia sesión</a></p>
          </form>
          <div class="login-footer">
            <span>Estado inicial: inactive</span>
            <strong>Un admin deberá habilitar tu acceso.</strong>
          </div>
        </div>
      </section>
    </div>
  `;

  const form = document.getElementById('register-form');
  const errorMessage = document.getElementById('error-message');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('password-confirm');
    const email = emailInput?.value.trim() ?? '';
    const password = passwordInput?.value ?? '';
    const confirm = confirmInput?.value ?? '';
    if (!email.match(emailPattern)) {
      errorMessage.textContent = 'Email inválido';
      return;
    }
    if (password.length < 6) {
      errorMessage.textContent = 'La contraseña debe tener al menos 6 caracteres';
      return;
    }
    if (password !== confirm) {
      errorMessage.textContent = 'Las contraseñas no coinciden';
      return;
    }
    try {
      errorMessage.textContent = '';
      await postJson('/auth/register', { email, password });
      window.location.href = '/';
    } catch (err) {
      const message = err?.error?.message ?? 'No se pudo registrar';
      errorMessage.textContent = message;
    }
  });
};

document.addEventListener('DOMContentLoaded', render);
