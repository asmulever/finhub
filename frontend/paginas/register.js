import { postJson } from '../apicliente.js';

const emailPattern = '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$';

const render = () => {
  const app = document.getElementById('app');
  if (!app) return;
  app.innerHTML = `
    <div class="container">
      <div class="row justify-content-between" style="gap:16px; justify-content:center;">
        <div class="col-12 col-sm-10 col-lg-6">
          <section class="auth-card">
            <div class="card-body">
              <div class="brand-row">
                <img src="/logo/full_logoweb.png" alt="FinHub" class="logo" />
                <h2 class="title">Crear cuenta FinHub</h2>
              </div>
              <p class="subtitle">Tu cuenta se crea inactiva hasta que el proceso de activacion por mail ,sea completado</p>
              <form id="register-form">
                <div class="form-group">
                  <label for="email">Correo</label>
                  <input id="email" name="email" type="email" required autocomplete="username" pattern="${emailPattern}" />
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
                  <p id="match-hint" class="hint" aria-live="polite"></p>
                </div>
                <button type="submit">Registrarme</button>
                <p id="error-message" class="error" aria-live="polite"></p>
                <p class="footnote">¿Ya tienes cuenta? <a href="/">Inicia sesión</a></p>
              </form>
              <p class="status-line">Estado inicial: <span class="status-strong">inactive</span></p>
            </div>
          </section>
        </div>
      </div>
    </div>
    <div class="loading-overlay" id="register-loading">
      <div class="loading-spinner" role="status" aria-label="Enviando registro"></div>
    </div>
  `;

  const form = document.getElementById('register-form');
  const errorMessage = document.getElementById('error-message');
   const matchHint = document.getElementById('match-hint');
  const loadingOverlay = document.getElementById('register-loading');
  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('password-confirm');
    const email = emailInput?.value.trim() ?? '';
    const password = passwordInput?.value ?? '';
    const confirm = confirmInput?.value ?? '';
    const submitButton = form.querySelector('button[type="submit"]');
    const showLoading = (show) => {
      if (!loadingOverlay) return;
      if (show) {
        loadingOverlay.classList.add('active');
      } else {
        loadingOverlay.classList.remove('active');
      }
      if (submitButton) {
        submitButton.disabled = show;
      }
    };
    const updateMatchHint = () => {
      if (!matchHint) return;
      matchHint.className = 'hint';
      if (confirmInput?.value === '') {
        matchHint.textContent = '';
        return;
      }
      if (passwordInput?.value === confirmInput?.value) {
        matchHint.textContent = 'Coinciden';
        matchHint.classList.add('success');
      } else {
        matchHint.textContent = 'No coinciden';
        matchHint.classList.add('error');
      }
    };
    updateMatchHint();
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
      showLoading(true);
      await postJson('/auth/register', { email, password });
      window.location.href = '/';
    } catch (err) {
      const message = err?.error?.message ?? 'No se pudo registrar';
      errorMessage.textContent = message;
    } finally {
      showLoading(false);
    }
  });

  const passwordInput = document.getElementById('password');
  const confirmInput = document.getElementById('password-confirm');
  const updateHintLive = () => {
    if (!matchHint) return;
    matchHint.className = 'hint';
    if (confirmInput?.value === '') {
      matchHint.textContent = '';
      return;
    }
    if (passwordInput?.value === confirmInput?.value) {
      matchHint.textContent = 'Coinciden';
      matchHint.classList.add('success');
    } else {
      matchHint.textContent = 'No coinciden';
      matchHint.classList.add('error');
    }
  };
  passwordInput?.addEventListener('input', updateHintLive);
  confirmInput?.addEventListener('input', updateHintLive);

  // Focus y navegación secuencial
  const sequence = [document.getElementById('email'), passwordInput, confirmInput, form?.querySelector('button[type="submit"]')];
  const ensureFocus = (target) => {
    if (target && typeof target.focus === 'function') {
      target.focus();
    }
  };
  ensureFocus(sequence[0]);

  sequence.forEach((el, idx) => {
    if (!el) return;
    el.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      event.preventDefault();
      const next = sequence[(idx + 1) % sequence.length];
      ensureFocus(next);
    });
  });
};

document.addEventListener('DOMContentLoaded', render);
