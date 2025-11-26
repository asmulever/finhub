(() => {
  const form = document.getElementById("login-form");
  const messageBox = document.getElementById("login-message");
  const emailError = document.getElementById("email-error");
  const passwordError = document.getElementById("password-error");
  const demoButtons = [
    document.getElementById("demo-mode-btn"),
    document.getElementById("demo-mode-alt"),
  ].filter(Boolean);
  const forgotLink = document.getElementById("forgot-password-link");
  const registerLink = document.getElementById("register-link");

  function setMessage(text, type = "info") {
    if (!messageBox) return;
    messageBox.textContent = text;
    const classList = ["alert-info"];
    if (type === "success") classList[0] = "alert-success";
    if (type === "danger") classList[0] = "alert-danger";
    messageBox.className = classList.join(" ");
  }

  function clearFieldErrors() {
    if (emailError) emailError.textContent = "";
    if (passwordError) passwordError.textContent = "";
  }

  function setFieldError(field, message) {
    const map = {
      email: emailError,
      password: passwordError,
    };
    if (map[field]) {
      map[field].textContent = message;
    }
  }

  async function handleLogin(event) {
    event.preventDefault();
    if (!form) return;

    clearFieldErrors();
    const formData = new FormData(form);
    const email = String(formData.get("email") || "").trim();
    const password = String(formData.get("password") || "");
    const rememberDevice = formData.get("remember_device") === "on";
    let invalid = false;

    if (email === "") {
      setFieldError("email", "Ingresa tu correo electrónico.");
      invalid = true;
    }

    if (password === "") {
      setFieldError("password", "Ingresa tu contraseña.");
      invalid = true;
    }

    if (invalid) {
      setMessage("Revisa los campos con errores.", "danger");
      return;
    }

    setMessage("Iniciando sesión...", "info");

    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        credentials: "include",
        body: JSON.stringify({ email, password, remember_device: rememberDevice }),
      });

      const contentType = response.headers.get("content-type") || "";
      const payload = contentType.includes("application/json")
        ? await response.json()
        : null;

      if (!response.ok) {
        if (response.status === 401) {
          setFieldError("password", "Credenciales inválidas. Intenta nuevamente.");
        }
        const errorMessage =
          payload?.error ||
          payload?.status ||
          `Error ${response.status}: no se pudo iniciar sesión.`;
        setMessage(errorMessage, "danger");
        return;
      }

      if (payload?.payload && payload?.access_expires_at && typeof Session !== "undefined") {
        Session.save(payload.payload, payload.access_expires_at);
      }

      const redirectUrl =
        window.LOGIN_REDIRECT_URL || "/frontend/dashboard.html";
      setMessage("Autenticado correctamente. Redirigiendo...", "success");
      setTimeout(() => {
        window.location.href = redirectUrl;
      }, 800);
    } catch (error) {
      console.error("Error al iniciar sesión:", error);
      setMessage("No se pudo contactar con la API. Intenta nuevamente.", "danger");
    }
  }

  function handleDemoMode(event) {
    event.preventDefault();
    window.location.href = "/frontend/dashboard.html?mode=demo";
  }

  function handleInfoMessage(event, message) {
    event.preventDefault();
    setMessage(message, "info");
  }

  if (form) {
    form.addEventListener("submit", handleLogin);
  }

  demoButtons.forEach((btn) => btn.addEventListener("click", handleDemoMode));

  if (forgotLink) {
    forgotLink.addEventListener("click", (event) =>
      handleInfoMessage(event, "Contacta al administrador para restablecer tu contraseña.")
    );
  }

  if (registerLink) {
    registerLink.addEventListener("click", (event) =>
      handleInfoMessage(event, "El registro está disponible bajo invitación. Solicítalo con tu asesor.")
    );
  }
})();
