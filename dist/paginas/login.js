// Frontend/auth/authStore.js
var TOKEN_KEY = "finhub_token";
var TOKEN_EXP_KEY = "finhub_token_exp";
var PROFILE_KEY = "finhub_profile";
var storage = sessionStorage;
var nowSeconds = () => Math.floor(Date.now() / 1e3);
var authStore = {
  getToken() {
    const exp = Number(storage.getItem(TOKEN_EXP_KEY) || 0);
    if (exp && nowSeconds() >= exp) {
      this.clearToken();
      return null;
    }
    return storage.getItem(TOKEN_KEY);
  },
  setToken(token, expiresInSeconds = 3600) {
    storage.setItem(TOKEN_KEY, token);
    storage.setItem(TOKEN_EXP_KEY, String(nowSeconds() + expiresInSeconds));
  },
  clearToken() {
    storage.removeItem(TOKEN_KEY);
    storage.removeItem(TOKEN_EXP_KEY);
    storage.removeItem(PROFILE_KEY);
  },
  setProfile(profile) {
    storage.setItem(PROFILE_KEY, JSON.stringify(profile));
  },
  getProfile() {
    const value = storage.getItem(PROFILE_KEY);
    return value ? JSON.parse(value) : null;
  }
};

// Frontend/apicliente.js
var getBaseUrl = /* @__PURE__ */ (() => {
  let cached = "";
  return () => {
    if (cached !== "") {
      return cached;
    }
    const envBase = window.__ENV?.API_BASE_URL;
    if (!envBase) {
      throw new Error("API_BASE_URL no est\xE1 configurada en window.__ENV");
    }
    cached = envBase.endsWith("/") ? envBase.slice(0, -1) : envBase;
    return cached;
  };
})();
var buildUrl = (path) => path.startsWith("http") ? path : `${getBaseUrl()}${path}`;
var handleUnauthorized = () => {
  authStore.clearToken();
  const target = "/";
  if (window.top && window.top !== window) {
    window.top.location.href = target;
  } else {
    window.location.href = target;
  }
};
var buildHeaders = (extraHeaders) => ({
  "Content-Type": "application/json",
  ...extraHeaders
});
var parsePayload = async (response) => response.json().catch(() => null);
var apiClient = async (path, options = {}) => {
  const { method = "GET", body, headers = {}, ...rest } = options;
  const composedHeaders = buildHeaders(headers);
  const token = authStore.getToken() ?? localStorage.getItem("jwt");
  if (token) {
    composedHeaders.Authorization = `Bearer ${token}`;
  }
  const response = await fetch(buildUrl(path), {
    method,
    credentials: "include",
    headers: composedHeaders,
    body: body === void 0 ? void 0 : JSON.stringify(body),
    ...rest
  });
  const payload = await parsePayload(response);
  if (!response.ok) {
    if (response.status === 401) {
      handleUnauthorized();
    }
    const err = payload?.error ?? {
      code: `http.${response.status}`,
      message: response.statusText
    };
    throw { status: response.status, error: err };
  }
  return payload;
};
var withBody = (method) => (path, body) => apiClient(path, { method, body });
var withoutBody = (method) => (path) => apiClient(path, { method });
var postJson = withBody("POST");
var patchJson = withBody("PATCH");
var deleteJson = withoutBody("DELETE");
var getJson = withoutBody("GET");

// Frontend/components/passwordToggle.js
var setupPasswordToggle = (input, button) => {
  const setState = (visible) => {
    input.type = visible ? "text" : "password";
    button.textContent = visible ? "\u{1F648}" : "\u{1F441}\uFE0F";
    button.setAttribute("aria-label", visible ? "Ocultar contrase\xF1a" : "Mostrar contrase\xF1a");
  };
  button.addEventListener("click", (event) => {
    event.preventDefault();
    setState(input.type !== "text");
  });
  setState(false);
};

// Frontend/paginas/login.js
var render = () => {
  const app = document.getElementById("app");
  if (!app)
    return;
  app.innerHTML = `
    <div class="container">
      <div class="row g-4 login-row">
        <div class="col-12 col-lg-6 hero-col">
          <div class="hero">
            <img src="/logo/full_logoweb.png" alt="FinHub" class="logo" />
            <h1>Impulsa tu portafolio con inteligencia conectada.</h1>
            <p class="tagline">Conecta tu portafolio con la inteligencia del mercado en tiempo real.</p>
            <p class="description">FinHub centraliza usuarios, instrumentos y precios hist\xF3ricos con trazabilidad, alertas y pipelines ETL idempotentes.</p>
            <ul class="feature-list">
              <li class="feature">Visibilidad completa de instrumentos en tiempo real.</li>
              <li class="feature">Alertas inteligentes y trazabilidad transparente.</li>
              <li class="feature">Pipelines ETL idempotentes con auditor\xEDa integrada.</li>
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
                    <label for="password">Contrase\xF1a</label>
                    <div class="password-field">
                      <input id="password" name="password" type="password" required autocomplete="current-password" />
                      <button id="toggle-password" type="button" aria-label="Mostrar contrase\xF1a" title="Mostrar contrase\xF1a">\u{1F441}\uFE0F</button>
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
                <span>\xBFNecesitas acceso?</span>
                <strong>Solicita credenciales con tu equipo de operaciones.</strong>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  `;
  const passwordInput = document.getElementById("password");
  const toggleButton = document.getElementById("toggle-password");
  if (passwordInput && toggleButton) {
    setupPasswordToggle(passwordInput, toggleButton);
  }
  const form = document.getElementById("login-form");
  const errorMessage = document.getElementById("error-message");
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const payload = {
      email: formData.get("email"),
      password: formData.get("password")
    };
    try {
      errorMessage.textContent = "";
      const response = await postJson("/auth/login", payload);
      authStore.setToken(response.token);
      authStore.setProfile(response.user);
      window.location.href = "/Frontend/app.php";
    } catch (err) {
      const message = err?.error?.message ?? "No se pudo iniciar sesi\xF3n";
      errorMessage.textContent = message;
    }
  });
};
document.addEventListener("DOMContentLoaded", render);
document.addEventListener("DOMContentLoaded", async () => {
  try {
    if (!authStore.getToken())
      return;
    await getJson("/me");
    window.location.href = "/Frontend/app.php";
  } catch {
  }
});
//# sourceMappingURL=login.js.map
