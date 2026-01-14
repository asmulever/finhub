import { getJson, postJson } from '../apicliente.js';
import { authStore } from '../auth/authStore.js';
import { setupPasswordToggle } from '../components/passwordToggle.js';

const render = () => {
  const app = document.getElementById('app');
  if (!app) return;
  app.innerHTML = `
    <div class="container">
      <div class="row g-4 login-row">
        <div class="col-12 col-lg-6 hero-col">
          <div class="hero">
            <img src="/logo/full_logoweb.png" alt="FinHub" class="logo" />
            <h1>Impulsa tu portafolio con inteligencia conectada.</h1>
            <p class="tagline">Conecta tu portafolio con la inteligencia del mercado en tiempo real.</p>
            <p class="description">FinHub centraliza usuarios, instrumentos y precios históricos con trazabilidad, alertas y pipelines ETL idempotentes.</p>
            <ul class="feature-list">
              <li class="feature">Visibilidad completa de instrumentos en tiempo real.</li>
              <li class="feature">Alertas inteligentes y trazabilidad transparente.</li>
              <li class="feature">Pipelines ETL idempotentes con auditoría integrada.</li>
            </ul>
          </div>
        </div>
        <div class="col-12 col-lg-6 form-col">
          <section class="panel login-card">
            <div class="panel-content">
              <div class="login-header">
                <p class="caption">Inicio seguro</p>
                <h2>Accede a tu panel FinHub</h2>
              </div>
              <p class="panel-copy">Accede a reportes, ABMs y controles de ownership sin dejar de lado el control de acceso y los registros necesarios.</p>
              <form id="login-form">
                <div class="input-grid">
                  <div class="form-group">
                    <label for="email">Correo</label>
                    <div class="input-field">
                      <input id="email" name="email" type="email" required autocomplete="username" />
                    </div>
                  </div>
                  <div class="form-group password-group">
                    <label for="password">Contraseña</label>
                    <div class="password-field">
                      <input id="password" name="password" type="password" required autocomplete="current-password" />
                      <button id="toggle-password" type="button" aria-label="Mostrar contraseña" title="Mostrar contraseña">👁️</button>
                    </div>
                  </div>
                </div>
                <div class="form-options">
                  <label class="checkbox">
                    <input id="remember-user" name="remember" type="checkbox" />
                    <span>Recordar usuario</span>
                  </label>
                  <a href="/Frontend/register.html" class="register-link">Registrar nuevo usuario</a>
                </div>
                <button type="submit">Ingresar</button>
                <p id="error-message" class="error" aria-live="polite"></p>
              </form>
              <div class="login-footer">
                <span>¿Necesitas acceso?</span>
                <strong>Solicita credenciales con tu equipo de operaciones.</strong>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  `;
  const passwordInput = document.getElementById('password');
  const toggleButton = document.getElementById('toggle-password');
  if (passwordInput && toggleButton) {
    setupPasswordToggle(passwordInput, toggleButton);
  }
  const form = document.getElementById('login-form');
  const errorMessage = document.getElementById('error-message');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const payload = {
      email: formData.get('email'),
      password: formData.get('password'),
    };    

    try {
      errorMessage.textContent = '';
      const response = await postJson('/auth/login', payload);
      authStore.setToken(response.token);
      authStore.setProfile(response.user);
      window.location.href = '/Frontend/app.php';
    } catch (err) {      
      const message = err?.error?.message ?? 'No se pudo iniciar sesión';
      errorMessage.textContent = message;
    }
  });
};

document.addEventListener('DOMContentLoaded', render);

// Validar sesión al iniciar y redirigir si es válida
document.addEventListener('DOMContentLoaded', async () => {
  try {
    if (!authStore.getToken()) return;
    await getJson('/me');
      window.location.href = '/Frontend/app.php';
  } catch {
    // sesión no válida, continuar en login
  }
});
