const API_BASE = "/index.php";

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("loginForm");
  const emailInput = document.getElementById("email");
  const passwordInput = document.getElementById("password");
  const loginBtn = document.getElementById("loginBtn");
  const errorBox = document.getElementById("loginError");
  const rememberCheck = document.getElementById("rememberUser");
  const sessionInfo = document.getElementById("sessionInfo");
  const togglePassword = document.getElementById("togglePassword");

  emailInput.value = Session.getRememberedEmail();
  rememberCheck.checked = emailInput.value !== "";

  updateSessionInfo();

  togglePassword.addEventListener("click", () => {
    const isText = passwordInput.type === "text";
    passwordInput.type = isText ? "password" : "text";
    togglePassword.innerHTML = `<i class="bi ${isText ? "bi-eye" : "bi-eye-slash"}"></i>`;
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    errorBox.classList.add("d-none");
    if (!form.checkValidity()) {
      form.classList.add("was-validated");
      return;
    }

    loginBtn.disabled = true;
    loginBtn.textContent = "Validando...";

    try {
      const response = await fetch(`${API_BASE}/auth/login`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          email: emailInput.value.trim(),
          password: passwordInput.value,
        }),
      });

      if (!response.ok) {
        throw new Error("Credenciales inválidas o token rechazado.");
      }

      const data = await response.json();
      let payload = data.payload ?? null;
      let accessExp = data.access_expires_at ?? null;

      // Fallback: si el backend colocó solo la cookie, obtener sesión explícita
      if (!payload || !accessExp) {
        const sessionCheck = await fetch(`${API_BASE}/auth/session`, {
          credentials: "same-origin",
        });
        if (sessionCheck.ok) {
          const sessionData = await sessionCheck.json();
          payload = payload ?? sessionData.payload ?? null;
          accessExp =
            accessExp ??
            sessionData.access_expires_at ??
            sessionData.payload?.exp ??
            null;
        }
      }

      if (!payload || !accessExp) {
        throw new Error("No se recibió un token válido.");
      }

      Session.save(payload, accessExp);
      if (rememberCheck.checked) {
        Session.rememberEmail(emailInput.value.trim());
      } else {
        Session.clearRememberedEmail();
      }

      updateSessionInfo();
      window.location.href = "frontend/dashboard.html";
    } catch (err) {
      console.error(err);
      errorBox.textContent = err.message || "No fue posible iniciar sesión.";
      errorBox.classList.remove("d-none");
    } finally {
      loginBtn.disabled = false;
      loginBtn.textContent = "Ingresar";
    }
  });

  function updateSessionInfo() {
    const payload = Session.getPayload();
    const exp = Session.getExpiresAt();
    if (!payload || !exp) {
      sessionInfo.textContent = "Sin sesión activa";
      return;
    }
    const expDate = new Date(exp * 1000);
    sessionInfo.textContent = `Expira: ${expDate.toLocaleString()}`;
  }
});
