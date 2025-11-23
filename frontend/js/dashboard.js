const API_BASE = "/index.php";
const PORTFOLIO_KEY = "finhub_portfolios";

let state = {
  token: null,
  payload: null,
  isAdmin: false,
  users: [],
  tickers: [],
  health: null,
  editingUserId: null,
  editingTickerId: null,
};

document.addEventListener("DOMContentLoaded", () => {
  const token = Session.getToken();
  const payload = Session.getPayload();

  if (!token || Session.isExpired(payload)) {
    redirectToLogin();
    return;
  }

  state = {
    ...state,
    token,
    payload,
    isAdmin: (payload?.role ?? "").toLowerCase() === "admin",
  };

  setupLayout();
  loadInitialData();
});

function setupLayout() {
  const userName = document.getElementById("userName");
  const tokenPreview = document.getElementById("tokenPreview");
  const sessionCountdown = document.getElementById("sessionCountdown");
  const logoutBtn = document.getElementById("logoutBtn");
  const extendBtn = document.getElementById("extendSession");

  userName.textContent = state.payload?.email ?? "Usuario autenticado";
  tokenPreview.textContent = `${state.token.substring(0, 15)}...`;

  logoutBtn.addEventListener("click", () => {
    Session.clear();
    redirectToLogin();
  });

  extendBtn.addEventListener("click", () => extendSession(state.token));
  startCountdown(state.payload?.exp, sessionCountdown);

  document
    .querySelectorAll("#mainNav .nav-link")
    .forEach((link) =>
      link.addEventListener("click", (event) => {
        event.preventDefault();
        const section = link.dataset.section;
        showSection(section);
      })
    );

  if (!state.isAdmin) {
    document
      .querySelectorAll('[data-role="admin"]')
      .forEach((el) => el.classList.add("d-none"));
  }

  const userForm = document.getElementById("userForm");
  if (userForm) {
    userForm.addEventListener("submit", handleUserFormSubmit);
    document
      .getElementById("userFormReset")
      ?.addEventListener("click", resetUserForm);
  }

  const tickerForm = document.getElementById("tickerForm");
  if (tickerForm) {
    tickerForm.addEventListener("submit", handleTickerFormSubmit);
    document
      .getElementById("tickerFormReset")
      ?.addEventListener("click", resetTickerForm);
  }

  const portfolioUserSelect = document.getElementById("portfolioUserSelect");
  const portfolioTickerSelect = document.getElementById("portfolioTickerSelect");
  const addTickerBtn = document.getElementById("addTickerToPortfolio");
  const newTickerForm = document.getElementById("portfolioNewTickerForm");

  portfolioUserSelect?.addEventListener("change", renderPortfolioList);
  addTickerBtn?.addEventListener("click", () => {
    const userId = portfolioUserSelect.value;
    const tickerId = Number(portfolioTickerSelect.value);
    if (!userId || !tickerId) return;
    addTickerToPortfolio(userId, tickerId);
  });
  newTickerForm?.addEventListener("submit", handlePortfolioTickerCreate);
}

function showSection(section) {
  document
    .querySelectorAll(".dashboard-section")
    .forEach((el) => el.classList.remove("active"));
  document
    .querySelectorAll("#mainNav .nav-link")
    .forEach((link) => link.classList.remove("active"));

  const target = document.getElementById(`section-${section}`);
  if (target) {
    target.classList.add("active");
  }

  const activeLink = document.querySelector(
    `#mainNav .nav-link[data-section="${section}"]`
  );
  activeLink?.classList.add("active");
}

async function loadInitialData() {
  // Health is public, tickers require token, users only if admin
  const healthPromise = fetchPublic("/health");
  const tickersPromise = fetchProtected("/financial-objects", state.token);
  const usersPromise = state.isAdmin
    ? fetchProtected("/users", state.token)
    : Promise.resolve([
        {
          id: state.payload?.uid ?? 0,
          email: state.payload?.email ?? "usuario",
          role: state.payload?.role ?? "user",
        },
      ]);

  const [health, tickers, users] = await Promise.all([
    healthPromise,
    tickersPromise,
    usersPromise,
  ]);

  state.health = health ?? { status: "N/D" };
  state.tickers = Array.isArray(tickers) ? tickers : [];
  state.users = Array.isArray(users) ? users : [];

  updateMetrics();
  renderUsersTable();
  renderTickersTable();
  populatePortfolioSelectors();
  renderPortfolioList();
}

function updateMetrics() {
  document.getElementById("usersMetric").textContent = state.users.length;
  document.getElementById("foMetric").textContent = state.tickers.length;
  document.getElementById("healthStatus").textContent =
    state.health?.status ?? "N/D";
}

// Users CRUD -------------------------------------------------------
async function handleUserFormSubmit(event) {
  event.preventDefault();
  if (!state.isAdmin) return;

  const status = document.getElementById("userFormStatus");
  status.textContent = "Procesando...";

  const payload = {
    email: document.getElementById("userEmail").value.trim(),
    password: document.getElementById("userPassword").value,
    role: document.getElementById("userRole").value,
  };

  try {
    if (state.editingUserId) {
      await requestWithAuth(
        `/users/${state.editingUserId}`,
        "PUT",
        payload
      );
      status.textContent = "Usuario actualizado.";
    } else {
      await requestWithAuth("/users", "POST", payload);
      status.textContent = "Usuario creado.";
    }
    resetUserForm();
    await loadUsers();
  } catch (err) {
    status.textContent = err.message || "No fue posible guardar.";
  }
}

async function loadUsers() {
  if (!state.isAdmin) return;
  const data = await fetchProtected("/users", state.token);
  if (Array.isArray(data)) {
    state.users = data;
    renderUsersTable();
    populatePortfolioSelectors();
    updateMetrics();
  }
}

function renderUsersTable() {
  const tbody = document.getElementById("usersTableBody");
  if (!tbody) return;
  if (!state.isAdmin) {
    tbody.innerHTML =
      '<tr><td colspan="4" class="text-center text-muted">Acceso solo para administradores.</td></tr>';
    return;
  }

  if (state.users.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="4" class="text-center text-muted">Sin usuarios.</td></tr>';
    return;
  }

  tbody.innerHTML = state.users
    .map(
      (user) => `
      <tr>
        <td>${user.id}</td>
        <td>${user.email}</td>
        <td>${user.role}</td>
        <td class="text-end table-actions">
          <button class="btn btn-sm btn-outline-primary" data-action="edit-user" data-id="${user.id}">Editar</button>
          <button class="btn btn-sm btn-outline-danger" data-action="delete-user" data-id="${user.id}">Eliminar</button>
        </td>
      </tr>
    `
    )
    .join("");

  tbody.querySelectorAll("button").forEach((btn) =>
    btn.addEventListener("click", () => {
      const id = Number(btn.dataset.id);
      if (btn.dataset.action === "edit-user") {
        startEditUser(id);
      } else if (btn.dataset.action === "delete-user") {
        deleteUser(id);
      }
    })
  );
}

function startEditUser(userId) {
  const user = state.users.find((u) => u.id === userId);
  if (!user) return;
  state.editingUserId = userId;
  document.getElementById("userEmail").value = user.email;
  document.getElementById("userPassword").value = "";
  document.getElementById("userRole").value = user.role;
  document.getElementById("userFormStatus").textContent =
    "Editando usuario #" + userId + " (ingresa nueva contraseña).";
}

async function deleteUser(userId) {
  if (!confirm("¿Seguro que quieres eliminar este usuario?")) return;
  try {
    await requestWithAuth(`/users/${userId}`, "DELETE");
    await loadUsers();
  } catch (err) {
    alert(err.message || "No se pudo eliminar el usuario.");
  }
}

function resetUserForm() {
  state.editingUserId = null;
  document.getElementById("userForm").reset();
  document.getElementById("userFormStatus").textContent = "";
}

// Tickers CRUD -----------------------------------------------------
async function handleTickerFormSubmit(event) {
  event.preventDefault();

  const status = document.getElementById("tickerFormStatus");
  status.textContent = "Procesando...";

  const payload = {
    name: document.getElementById("tickerName").value.trim(),
    symbol: document.getElementById("tickerSymbol").value.trim(),
    type: document.getElementById("tickerType").value.trim(),
  };

  try {
    if (state.editingTickerId) {
      await requestWithAuth(
        `/financial-objects/${state.editingTickerId}`,
        "PUT",
        payload
      );
      status.textContent = "Ticker actualizado.";
    } else {
      await requestWithAuth("/financial-objects", "POST", payload);
      status.textContent = "Ticker creado.";
    }
    resetTickerForm();
    await loadTickers();
  } catch (err) {
    status.textContent = err.message || "No fue posible guardar.";
  }
}

async function loadTickers() {
  const data = await fetchProtected("/financial-objects", state.token);
  if (Array.isArray(data)) {
    state.tickers = data;
    renderTickersTable();
    populatePortfolioSelectors();
    updateMetrics();
  }
}

function renderTickersTable() {
  const tbody = document.getElementById("tickersTableBody");
  if (!tbody) return;

  if (state.tickers.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="5" class="text-center text-muted">Sin registros.</td></tr>';
    return;
  }

  tbody.innerHTML = state.tickers
    .map(
      (ticker) => `
      <tr>
        <td>${ticker.id}</td>
        <td>${ticker.name}</td>
        <td>${ticker.symbol}</td>
        <td>${ticker.type}</td>
        <td class="text-end table-actions">
          <button class="btn btn-sm btn-outline-primary" data-action="edit-ticker" data-id="${ticker.id}">Editar</button>
          <button class="btn btn-sm btn-outline-danger" data-action="delete-ticker" data-id="${ticker.id}">Eliminar</button>
        </td>
      </tr>
    `
    )
    .join("");

  tbody.querySelectorAll("button").forEach((btn) =>
    btn.addEventListener("click", () => {
      const id = Number(btn.dataset.id);
      if (btn.dataset.action === "edit-ticker") {
        startEditTicker(id);
      } else if (btn.dataset.action === "delete-ticker") {
        deleteTicker(id);
      }
    })
  );
}

function startEditTicker(tickerId) {
  const ticker = state.tickers.find((t) => t.id === tickerId);
  if (!ticker) return;
  state.editingTickerId = tickerId;
  document.getElementById("tickerName").value = ticker.name;
  document.getElementById("tickerSymbol").value = ticker.symbol;
  document.getElementById("tickerType").value = ticker.type;
  document.getElementById("tickerFormStatus").textContent =
    "Editando ticker #" + tickerId;
}

async function deleteTicker(tickerId) {
  if (!confirm("¿Eliminar este ticker definitivamente?")) return;
  try {
    await requestWithAuth(`/financial-objects/${tickerId}`, "DELETE");
    await loadTickers();
  } catch (err) {
    alert(err.message || "No se pudo eliminar el ticker.");
  }
}

function resetTickerForm() {
  state.editingTickerId = null;
  document.getElementById("tickerForm").reset();
  document.getElementById("tickerFormStatus").textContent = "";
}

// Portafolio -------------------------------------------------------
function populatePortfolioSelectors() {
  const userSelect = document.getElementById("portfolioUserSelect");
  const tickerSelect = document.getElementById("portfolioTickerSelect");
  if (!userSelect || !tickerSelect) return;

  const users = state.isAdmin
    ? state.users
    : [
        {
          id: state.payload?.uid ?? 0,
          email: state.payload?.email ?? "usuario",
        },
      ];

  userSelect.innerHTML = users
    .map((user) => `<option value="${user.id}">${user.email}</option>`)
    .join("");

  tickerSelect.innerHTML =
    state.tickers
      .map(
        (ticker) =>
          `<option value="${ticker.id}">${ticker.symbol} - ${ticker.name}</option>`
      )
      .join("") || '<option value="">Sin ticker\'s disponibles</option>';

  if (userSelect.value) {
    renderPortfolioList();
  }
}

function renderPortfolioList() {
  const container = document.getElementById("portfolioList");
  const userSelect = document.getElementById("portfolioUserSelect");
  if (!container || !userSelect) return;

  const userId = userSelect.value;
  const portfolio = getPortfolioForUser(userId);
  if (!portfolio || portfolio.length === 0) {
    container.innerHTML =
      '<span class="text-muted">Aún no se agregan ticker\'s.</span>';
    return;
  }

  container.innerHTML = "";
  portfolio.forEach((tickerId) => {
    const ticker = state.tickers.find((t) => t.id === tickerId);
    if (!ticker) return;
    const badge = document.createElement("span");
    badge.className = "badge text-bg-light d-inline-flex align-items-center gap-2";
    badge.innerHTML = `${ticker.symbol} - ${ticker.name}
      <button class="btn-close btn-close-dark btn-sm" aria-label="Eliminar"></button>`;
    badge
      .querySelector("button")
      .addEventListener("click", () => removeTickerFromPortfolio(userId, tickerId));
    container.appendChild(badge);
  });
}

function addTickerToPortfolio(userId, tickerId) {
  const portfolio = getPortfolioStore();
  const list = new Set(portfolio[userId] ?? []);
  if (list.has(tickerId)) {
    alert("Este ticker ya está en el portafolio.");
    return;
  }
  list.add(tickerId);
  portfolio[userId] = Array.from(list);
  savePortfolioStore(portfolio);
  renderPortfolioList();
}

function removeTickerFromPortfolio(userId, tickerId) {
  const portfolio = getPortfolioStore();
  portfolio[userId] = (portfolio[userId] ?? []).filter((id) => id !== tickerId);
  savePortfolioStore(portfolio);
  renderPortfolioList();
}

function getPortfolioForUser(userId) {
  if (!userId) return [];
  const portfolio = getPortfolioStore();
  return portfolio[userId] ?? [];
}

function getPortfolioStore() {
  try {
    const raw = localStorage.getItem(PORTFOLIO_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch {
    return {};
  }
}

function savePortfolioStore(data) {
  localStorage.setItem(PORTFOLIO_KEY, JSON.stringify(data));
}

async function handlePortfolioTickerCreate(event) {
  event.preventDefault();
  const status = document.getElementById("portfolioTickerStatus");
  status.textContent = "Creando...";

  const payload = {
    name: document.getElementById("portfolioTickerName").value.trim(),
    symbol: document.getElementById("portfolioTickerSymbol").value.trim(),
    type: document.getElementById("portfolioTickerType").value.trim(),
  };

  try {
    const result = await requestWithAuth("/financial-objects", "POST", payload);
    status.textContent = "Ticker creado y disponible.";
    document.getElementById("portfolioNewTickerForm").reset();
    await loadTickers();
    // Agrega de inmediato al portafolio del usuario seleccionado
    const userId = document.getElementById("portfolioUserSelect").value;
    if (userId && result?.id) {
      addTickerToPortfolio(userId, result.id);
    }
  } catch (err) {
    status.textContent = err.message || "No se pudo crear.";
  }
}

// Helpers ----------------------------------------------------------
async function requestWithAuth(route, method, body) {
  const res = await fetch(`${API_BASE}${route}`, {
    method,
    headers: {
      Authorization: `Bearer ${state.token}`,
      "Content-Type": "application/json",
    },
    body: body ? JSON.stringify(body) : undefined,
  });

  if (res.status === 401) {
    redirectToLogin();
    return null;
  }
  if (!res.ok) {
    const text = await res.text();
    throw new Error(text || "Error al procesar la solicitud.");
  }
  const contentType = res.headers.get("content-type") || "";
  if (contentType.includes("application/json")) {
    return res.json();
  }
  return null;
}

async function fetchProtected(route, token) {
  try {
    const res = await fetch(`${API_BASE}${route}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json",
      },
    });
    if (res.status === 401) {
      redirectToLogin();
      return null;
    }
    if (!res.ok) {
      throw new Error(`Status ${res.status}`);
    }
    return await res.json();
  } catch (err) {
    console.warn(`Error fetching ${route}`, err);
    return null;
  }
}

async function fetchPublic(route) {
  try {
    const res = await fetch(`${API_BASE}${route}`);
    return await res.json();
  } catch (err) {
    console.warn(`Error fetching ${route}`, err);
    return null;
  }
}

function redirectToLogin() {
  Session.clear();
  window.location.href = "../index.html";
}

function startCountdown(exp, element) {
  if (!exp || !element) return;
  const interval = setInterval(() => {
    const now = Math.floor(Date.now() / 1000);
    const secondsLeft = exp - now;
    if (secondsLeft <= 0) {
      element.textContent = "Sesión expirada";
      clearInterval(interval);
      redirectToLogin();
      return;
    }
    element.textContent = `Expira en ${formatDuration(secondsLeft)}`;
  }, 1000);
}

function formatDuration(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}m ${secs}s`;
}

async function extendSession(token) {
  try {
    const res = await fetch(`${API_BASE}/auth/validate`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token }),
    });
    if (!res.ok) throw new Error("El token no es válido, vuelve a iniciar sesión.");
    const data = await res.json();
    const payload = data.payload ?? null;
    if (payload) {
      Session.save(token, payload);
      state.payload = payload;
      document.getElementById("userName").textContent =
        payload.email ?? "Usuario autenticado";
    }
    alert("Sesión validada. Integra aquí la renovación del token si está disponible.");
  } catch (err) {
    alert(err.message);
    redirectToLogin();
  }
}
