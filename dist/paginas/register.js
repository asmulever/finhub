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

// Frontend/paginas/register.js
var emailPattern = "^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$";
var render = () => {
  const app = document.getElementById("app");
  if (!app)
    return;
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
                  <label for="password">Contrase\xF1a</label>
                  <div class="password-field">
                    <input id="password" name="password" type="password" required autocomplete="new-password" />
                  </div>
                </div>
                <div class="form-group password-group">
                  <label for="password-confirm">Confirmar contrase\xF1a</label>
                  <div class="password-field">
                    <input id="password-confirm" name="password-confirm" type="password" required autocomplete="new-password" />
                  </div>
                  <p id="match-hint" class="hint" aria-live="polite"></p>
                </div>
                <button type="submit">Registrarme</button>
                <p id="error-message" class="error" aria-live="polite"></p>
                <p class="footnote">\xBFYa tienes cuenta? <a href="/">Inicia sesi\xF3n</a></p>
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
  const form = document.getElementById("register-form");
  const errorMessage = document.getElementById("error-message");
  const matchHint = document.getElementById("match-hint");
  const loadingOverlay = document.getElementById("register-loading");
  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const emailInput = document.getElementById("email");
    const passwordInput2 = document.getElementById("password");
    const confirmInput2 = document.getElementById("password-confirm");
    const email = emailInput?.value.trim() ?? "";
    const password = passwordInput2?.value ?? "";
    const confirm = confirmInput2?.value ?? "";
    const submitButton = form.querySelector('button[type="submit"]');
    const showLoading = (show) => {
      if (!loadingOverlay)
        return;
      if (show) {
        loadingOverlay.classList.add("active");
      } else {
        loadingOverlay.classList.remove("active");
      }
      if (submitButton) {
        submitButton.disabled = show;
      }
    };
    const updateMatchHint = () => {
      if (!matchHint)
        return;
      matchHint.className = "hint";
      if (confirmInput2?.value === "") {
        matchHint.textContent = "";
        return;
      }
      if (passwordInput2?.value === confirmInput2?.value) {
        matchHint.textContent = "Coinciden";
        matchHint.classList.add("success");
      } else {
        matchHint.textContent = "No coinciden";
        matchHint.classList.add("error");
      }
    };
    updateMatchHint();
    if (!email.match(emailPattern)) {
      errorMessage.textContent = "Email inv\xE1lido";
      return;
    }
    if (password.length < 6) {
      errorMessage.textContent = "La contrase\xF1a debe tener al menos 6 caracteres";
      return;
    }
    if (password !== confirm) {
      errorMessage.textContent = "Las contrase\xF1as no coinciden";
      return;
    }
    try {
      errorMessage.textContent = "";
      showLoading(true);
      await postJson("/auth/register", { email, password });
      window.location.href = "/";
    } catch (err) {
      const message = err?.error?.message ?? "No se pudo registrar";
      errorMessage.textContent = message;
    } finally {
      showLoading(false);
    }
  });
  const passwordInput = document.getElementById("password");
  const confirmInput = document.getElementById("password-confirm");
  const updateHintLive = () => {
    if (!matchHint)
      return;
    matchHint.className = "hint";
    if (confirmInput?.value === "") {
      matchHint.textContent = "";
      return;
    }
    if (passwordInput?.value === confirmInput?.value) {
      matchHint.textContent = "Coinciden";
      matchHint.classList.add("success");
    } else {
      matchHint.textContent = "No coinciden";
      matchHint.classList.add("error");
    }
  };
  passwordInput?.addEventListener("input", updateHintLive);
  confirmInput?.addEventListener("input", updateHintLive);
  const sequence = [document.getElementById("email"), passwordInput, confirmInput, form?.querySelector('button[type="submit"]')];
  const ensureFocus = (target) => {
    if (target && typeof target.focus === "function") {
      target.focus();
    }
  };
  ensureFocus(sequence[0]);
  sequence.forEach((el, idx) => {
    if (!el)
      return;
    el.addEventListener("keydown", (event) => {
      if (event.key !== "Tab")
        return;
      event.preventDefault();
      const next = sequence[(idx + 1) % sequence.length];
      ensureFocus(next);
    });
  });
};
document.addEventListener("DOMContentLoaded", render);
//# sourceMappingURL=register.js.map
