let state = {
  payload: null,
  sessionExpiresAt: null,
  isAdmin: false,
  users: [],
  tickers: [],
  accounts: [],
  portfolioTickers: [],
  selectedBrokerId: null,
  health: null,
  editingUserId: null,
  editingTickerId: null,
  editingAccountId: null,
};

let sessionCountdownInterval = null;
let sessionPromptTimeout = null;
let sessionPromptCountdownInterval = null;

document.addEventListener("DOMContentLoaded", async () => {
  const sessionInfo = await fetchSession();
  if (!sessionInfo) return;

  state = {
    ...state,
    payload: sessionInfo.payload,
    sessionExpiresAt: sessionInfo.access_expires_at,
    isAdmin:
      (sessionInfo.payload?.role ?? "").toLowerCase() === "admin",
  };

  setupLayout();
  loadInitialData();
  startCountdown(
    state.sessionExpiresAt,
    document.getElementById("sessionCountdown")
  );
  scheduleSessionPrompt(state.sessionExpiresAt);
});

window.addEventListener("session:refreshed", (event) => {
  const detail = event.detail || {};
  if (!detail.payload || !detail.accessExp) {
    return;
  }
  applySessionPayload(detail.payload, detail.accessExp);
});

function setupLayout() {
  const userName = document.getElementById("userName");
  const tokenPreview = document.getElementById("tokenPreview");
  const sessionCountdown = document.getElementById("sessionCountdown");
  const logoutBtn = document.getElementById("logoutBtn");
  const extendBtn = document.getElementById("extendSession");

  userName.textContent = state.payload?.email ?? "Usuario autenticado";
  tokenPreview.textContent = "Cookie HttpOnly activa";

  logoutBtn.addEventListener("click", () => {
    logoutAndRedirect();
  });

  extendBtn.addEventListener("click", () => refreshSession());

  document
    .querySelectorAll("#mainNav .nav-link")
    .forEach((link) =>
      link.addEventListener("click", (event) => {
        event.preventDefault();
        const section = link.dataset.section;
        showSection(section);
        refreshSession({ silent: true }).catch(() => {});
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

  const brokerForm = document.getElementById("brokerForm");
  if (brokerForm) {
    brokerForm.addEventListener("submit", handleBrokerFormSubmit);
    document
      .getElementById("brokerFormReset")
      ?.addEventListener("click", resetBrokerForm);
  }

  document
    .getElementById("portfolioTickerForm")
    ?.addEventListener("submit", handlePortfolioTickerFormSubmit);

  document
    .getElementById("portfolioBrokerSelect")
    ?.addEventListener("change", async (event) => {
      state.selectedBrokerId = event.target.value || null;
      await loadPortfolio();
    });
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
  const tickersPromise = fetchProtected("/financial-objects");
  const accountsPromise = fetchProtected("/accounts");
  const usersPromise = state.isAdmin
    ? fetchProtected("/users")
    : Promise.resolve([
        {
          id: state.payload?.uid ?? 0,
          email: state.payload?.email ?? "usuario",
          role: state.payload?.role ?? "user",
        },
      ]);

  const [health, tickers, users, accounts] = await Promise.all([
    healthPromise,
    tickersPromise,
    usersPromise,
    accountsPromise,
  ]);

  state.health = health ?? { status: "N/D" };
  state.tickers = Array.isArray(tickers) ? tickers : [];
  state.users = Array.isArray(users) ? users : [];
  state.accounts = Array.isArray(accounts) ? accounts : [];

  updateMetrics();
  renderUsersTable();
  renderTickersTable();
  renderBrokersTable();
  await loadPortfolio();
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
      try {
        await requestWithAuth(
          `/users/${state.editingUserId}`,
          "PUT",
          payload
        );
      } catch (err) {
        console.warn("PUT /users failed, retrying via POST", err);
        await requestWithAuth(
          `/users/${state.editingUserId}/update`,
          "POST",
          payload
        );
      }
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
  const data = await fetchProtected("/users");
  if (Array.isArray(data)) {
    state.users = data;
    renderUsersTable();
    populatePortfolioSelectors();
    populateBrokerUsers();
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
    console.warn("DELETE fallback to POST", err);
    try {
      await requestWithAuth(`/users/${userId}/delete`, "POST");
      await loadUsers();
    } catch (err2) {
      alert(err2.message || "No se pudo eliminar el usuario.");
    }
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
  const data = await fetchProtected("/financial-objects");
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

async function handleBrokerFormSubmit(event) {
  event.preventDefault();
  const status = document.getElementById("brokerFormStatus");
  status.textContent = "Guardando...";

  const payload = {
    broker_name: document.getElementById("brokerName").value.trim(),
    currency: document.getElementById("brokerCurrency").value.trim(),
    is_primary: document.getElementById("brokerPrimary").checked,
  };

  try {
    if (state.editingAccountId) {
      try {
        await requestWithAuth(
          `/accounts/${state.editingAccountId}`,
          "PUT",
          payload
        );
      } catch (err) {
        console.warn("PUT /accounts failed, retrying via POST", err);
        await requestWithAuth(
          `/accounts/${state.editingAccountId}/update`,
          "POST",
          payload
        );
      }
      status.textContent = "Cuenta actualizada.";
    } else {
      await requestWithAuth("/accounts", "POST", payload);
      status.textContent = "Cuenta creada.";
    }
    resetBrokerForm();
    await loadAccounts();
    await loadPortfolio();
    renderPortfolioSection();
  } catch (err) {
    status.textContent = err.message || "No fue posible guardar.";
  }
}

async function loadAccounts() {
  const data = await fetchProtected("/accounts");
  if (Array.isArray(data)) {
    state.accounts = data;
    const stillExists =
      state.selectedBrokerId &&
      state.accounts.some(
        (account) => String(account.id) === state.selectedBrokerId
      );
    state.selectedBrokerId = stillExists ? state.selectedBrokerId : null;
    renderBrokersTable();
    renderPortfolioSection();
  }
}

async function loadPortfolio(brokerId = state.selectedBrokerId) {
  if (!brokerId) {
    state.portfolioTickers = [];
    state.selectedBrokerId = null;
    renderPortfolioSection();
    return;
  }

  const data = await fetchProtected(`/portfolio?broker_id=${brokerId}`);
  if (!data) {
    state.portfolioTickers = [];
    renderPortfolioSection();
    return;
  }

  state.selectedBrokerId = brokerId;
  state.portfolioTickers = Array.isArray(data.tickers) ? data.tickers : [];
  renderPortfolioSection();
}

function renderPortfolioSection() {
  const brokerSelect = document.getElementById("portfolioBrokerSelect");
  const noBrokersAlert = document.getElementById("portfolioNoBrokers");
  const tickerSelect = document.getElementById("portfolioTickerSelect");
  const tableBody = document.getElementById("portfolioTickersBody");
  const form = document.getElementById("portfolioTickerForm");
  const status = document.getElementById("portfolioTickerStatus");
  if (!brokerSelect || !noBrokersAlert || !tickerSelect || !tableBody || !form) {
    return;
  }

  if (status) {
    status.textContent = "";
  }

  const toggleFormDisabled = (disabled) => {
    Array.from(form.elements).forEach((el) => {
      el.disabled = disabled;
    });
  };

  if (state.accounts.length === 0) {
    brokerSelect.innerHTML = '<option value="">Sin brokers</option>';
    brokerSelect.disabled = true;
    noBrokersAlert.classList.remove("d-none");
    toggleFormDisabled(true);
  } else {
    brokerSelect.disabled = false;
    noBrokersAlert.classList.add("d-none");
    const options = state.accounts
      .map(
        (account) =>
          `<option value="${account.id}" ${
            String(account.id) === (state.selectedBrokerId ?? '')
              ? "selected"
              : ""
          }>${account.broker_name}</option>`
      )
      .join("");
    brokerSelect.innerHTML =
      '<option value="">Seleccione un broker</option>' + options;
    toggleFormDisabled(false);
  }

  tickerSelect.innerHTML =
    state.tickers
      .map(
        (ticker) =>
          `<option value="${ticker.id}">${ticker.symbol} - ${ticker.name}</option>`
      )
      .join("") || '<option value="">Sin ticker\'s disponibles</option>';

  if (!state.selectedBrokerId) {
    tableBody.innerHTML =
      '<tr><td colspan="6" class="text-center text-muted">Seleccione el broker con el que desea operar.</td></tr>';
    toggleFormDisabled(true);
    return;
  }

  if (state.portfolioTickers.length === 0) {
    tableBody.innerHTML =
      '<tr><td colspan="6" class="text-center text-muted">Sin movimientos.</td></tr>';
    return;
  }

  tableBody.innerHTML = state.portfolioTickers
    .map(
      (ticker) => `
        <tr data-ticker="${ticker.id}">
          <td>${ticker.financial_object_symbol}</td>
          <td>${ticker.financial_object_name}</td>
          <td>
            <input type="number" step="0.0001" class="form-control form-control-sm" data-field="quantity" value="${ticker.quantity}">
          </td>
          <td>
            <input type="number" step="0.0001" class="form-control form-control-sm" data-field="avg_price" value="${ticker.avg_price}">
          </td>
          <td>${ticker.financial_object_type}</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" data-action="save-ticker" data-id="${ticker.id}">Guardar</button>
            <button class="btn btn-sm btn-outline-danger" data-action="delete-ticker" data-id="${ticker.id}">Eliminar</button>
          </td>
        </tr>
      `
    )
    .join("");

  tableBody.querySelectorAll("[data-action=\"save-ticker\"]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const id = Number(btn.dataset.id);
      handlePortfolioTickerUpdate(id);
    });
  });

  tableBody
    .querySelectorAll("[data-action=\"delete-ticker\"]")
    .forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = Number(btn.dataset.id);
        handlePortfolioTickerDelete(id);
      });
    });
}

async function handlePortfolioTickerFormSubmit(event) {
  event.preventDefault();
  const status = document.getElementById("portfolioTickerStatus");
  status.textContent = "Guardando...";

  if (!state.selectedBrokerId) {
    status.textContent = "Debes seleccionar un broker.";
    return;
  }

  const financialObjectId = Number(
    document.getElementById("portfolioTickerSelect").value
  );
  const quantity = parseFloat(document.getElementById("portfolioQty").value);
  const avgPrice = parseFloat(document.getElementById("portfolioAvgPrice").value);

  try {
    await requestWithAuth("/portfolio/tickers", "POST", {
      broker_id: Number(state.selectedBrokerId),
      financial_object_id: financialObjectId,
      quantity,
      avg_price: avgPrice,
    });
    event.target.reset();
    status.textContent = "Ticker agregado.";
    await loadPortfolio();
  } catch (err) {
    status.textContent = err.message || "No fue posible agregar el ticker.";
  }
}

async function handlePortfolioTickerUpdate(tickerId) {
  const row = document.querySelector(`tr[data-ticker="${tickerId}"]`);
  if (!row) return;

  const quantity = parseFloat(
    row.querySelector('[data-field="quantity"]').value
  );
  const avgPrice = parseFloat(
    row.querySelector('[data-field="avg_price"]').value
  );

  try {
    await requestWithAuth(`/portfolio/tickers/${tickerId}`, "PUT", {
      quantity,
      avg_price: avgPrice,
    });
    await loadPortfolio();
  } catch (err) {
    alert(err.message || "No fue posible actualizar el ticker.");
  }
}

async function handlePortfolioTickerDelete(tickerId) {
  if (!confirm("¿Eliminar este ticker del portafolio?")) return;
  try {
    await requestWithAuth(`/portfolio/tickers/${tickerId}`, "DELETE");
    await loadPortfolio();
  } catch (err) {
    alert(err.message || "No fue posible eliminar el ticker.");
  }
}

function renderBrokersTable() {
  const tbody = document.getElementById("brokersTableBody");
  if (!tbody) return;

  if (state.accounts.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="7" class="text-center text-muted">Sin cuentas registradas.</td></tr>';
    return;
  }

  tbody.innerHTML = state.accounts
    .map(
      (account) => `
      <tr>
        <td>${account.id}</td>
        <td>${account.broker_name}</td>
        <td>${account.currency}</td>
        <td>${account.is_primary ? "Sí" : "No"}</td>
        <td>${account.created_at ?? "-"}</td>
        <td class="text-end table-actions">
          <button class="btn btn-sm btn-outline-primary" data-action="edit-account" data-id="${account.id}">Editar</button>
          <button class="btn btn-sm btn-outline-danger" data-action="delete-account" data-id="${account.id}">Eliminar</button>
        </td>
      </tr>
    `
    )
    .join("");

  tbody.querySelectorAll("button").forEach((btn) =>
    btn.addEventListener("click", () => {
      const id = Number(btn.dataset.id);
      if (btn.dataset.action === "edit-account") {
        startEditBroker(id);
      } else if (btn.dataset.action === "delete-account") {
        deleteAccountBroker(id);
      }
    })
  );
}

function populateBrokerUsers() {
  const select = document.getElementById("brokerUserSelect");
  if (!select) return;

  const users = state.isAdmin
    ? state.users
    : [
        {
          id: state.payload?.uid ?? 0,
          email: state.payload?.email ?? "usuario",
        },
      ];

  select.innerHTML = users
    .map((user) => `<option value="${user.id}">${user.email}</option>`)
    .join("");
}

function startEditBroker(accountId) {
  const account = state.accounts.find((a) => a.id === accountId);
  if (!account) return;

  state.editingAccountId = accountId;
  document.getElementById("brokerName").value = account.broker_name;
  document.getElementById("brokerCurrency").value = account.currency;
  document.getElementById("brokerPrimary").checked = account.is_primary;
  document.getElementById("brokerFormStatus").textContent =
    "Editando broker #" + accountId;
}

async function deleteAccountBroker(accountId) {
  if (!confirm("¿Eliminar esta cuenta definitivamente?")) return;
  try {
    await requestWithAuth(`/accounts/${accountId}`, "DELETE");
    await loadAccounts();
    await loadPortfolio();
  } catch (err) {
    console.warn("DELETE /accounts fallo, reintentando via POST", err);
    try {
      await requestWithAuth(`/accounts/${accountId}/delete`, "POST");
      await loadAccounts();
      await loadPortfolio();
    } catch (err2) {
      alert(err2.message || "No se pudo eliminar la cuenta.");
    }
  }
}

function resetBrokerForm() {
  state.editingAccountId = null;
  document.getElementById("brokerForm")?.reset();
  document.getElementById("brokerFormStatus").textContent = "";
}

// Helpers ----------------------------------------------------------
async function requestWithAuth(route, method, body) {
  const res = await apiFetch(route, {
    method,
    headers: {
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

async function fetchProtected(route) {
  try {
    const res = await apiFetch(route);
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
    const res = await apiFetch(route);
    return await res.json();
  } catch (err) {
    console.warn(`Error fetching ${route}`, err);
    return null;
  }
}

async function fetchSession() {
  try {
    const res = await apiFetch("/auth/session");
    if (!res.ok) {
      redirectToLogin();
      return null;
    }
    const data = await res.json();
    const payload = data.payload ?? null;
    const accessExp = data.access_expires_at ?? payload?.exp ?? null;
    if (!payload || !accessExp) {
      redirectToLogin();
      return null;
    }
    Session.save(payload, accessExp);
    return { payload, access_expires_at: accessExp };
  } catch (err) {
    console.error("No se pudo validar la sesión", err);
    redirectToLogin();
    return null;
  }
}

async function logoutAndRedirect() {
  clearSessionTimers();
  try {
    await apiFetch("/auth/logout", { method: "POST", skipSessionExtend: true });
  } catch (err) {
    console.warn("No se pudo cerrar la sesión en el servidor", err);
  }
  redirectToLogin();
}

function redirectToLogin() {
  clearSessionTimers();
  Session.clear();
  window.location.href = "../index.html";
}

function clearSessionTimers() {
  if (sessionCountdownInterval) {
    clearInterval(sessionCountdownInterval);
    sessionCountdownInterval = null;
  }
  if (sessionPromptTimeout) {
    clearTimeout(sessionPromptTimeout);
    sessionPromptTimeout = null;
  }
  if (sessionPromptCountdownInterval) {
    clearInterval(sessionPromptCountdownInterval);
    sessionPromptCountdownInterval = null;
  }
}

function startCountdown(exp, element) {
  if (!exp || !element) return;
  if (sessionCountdownInterval) clearInterval(sessionCountdownInterval);

  const update = () => {
    const now = Math.floor(Date.now() / 1000);
    const secondsLeft = exp - now;
    if (secondsLeft <= 0) {
      element.textContent = "Sesión expirada";
      if (sessionCountdownInterval) clearInterval(sessionCountdownInterval);
      if (!document.getElementById("sessionExtendPrompt")) {
        showExtendPrompt();
      }
      return;
    }
    element.textContent = `Expira en ${formatDuration(secondsLeft)}`;
  };

  update();
  sessionCountdownInterval = setInterval(update, 1000);
}

function scheduleSessionPrompt(exp) {
  if (!exp) return;
  if (sessionPromptTimeout) clearTimeout(sessionPromptTimeout);
  const now = Math.floor(Date.now() / 1000);
  const msUntilPrompt = Math.max(exp - now, 0) * 1000;
  sessionPromptTimeout = setTimeout(() => showExtendPrompt(), msUntilPrompt);
}

function showExtendPrompt() {
  const existing = document.getElementById("sessionExtendPrompt");
  if (existing) return;
  sessionPromptTimeout = null;

  const overlay = document.createElement("div");
  overlay.id = "sessionExtendPrompt";
  overlay.style.position = "fixed";
  overlay.style.inset = "0";
  overlay.style.background = "rgba(0,0,0,0.5)";
  overlay.style.display = "flex";
  overlay.style.alignItems = "center";
  overlay.style.justifyContent = "center";
  overlay.style.zIndex = "1050";

  overlay.innerHTML = `
    <div style="background:#fff; padding:24px; border-radius:12px; max-width:420px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.25); text-align:center;">
      <h5 style="margin-bottom:12px;">Extender sesión</h5>
      <p style="margin-bottom:12px;">Tu sesión de 5 minutos llegó al límite. ¿Quieres extenderla?</p>
      <p style="font-size:14px; color:#6c757d; margin-bottom:16px;">Se cerrará automáticamente en <span data-countdown>20</span>s.</p>
      <div style="display:flex; gap:10px; justify-content:center;">
        <button type="button" class="btn btn-primary" data-action="extend">Extender</button>
        <button type="button" class="btn btn-outline-secondary" data-action="logout">Salir</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  const countdownEl = overlay.querySelector("[data-countdown]");
  let remaining = 20;
  if (sessionPromptCountdownInterval) {
    clearInterval(sessionPromptCountdownInterval);
  }
  sessionPromptCountdownInterval = setInterval(() => {
    remaining -= 1;
    if (countdownEl) countdownEl.textContent = remaining;
    if (remaining <= 0) {
      clearInterval(sessionPromptCountdownInterval);
      overlay.remove();
      logoutAndRedirect();
    }
  }, 1000);

  overlay
    .querySelector('[data-action="extend"]')
    ?.addEventListener("click", async () => {
      clearInterval(sessionPromptCountdownInterval);
      sessionPromptCountdownInterval = null;
      overlay.remove();
      await refreshSession({ silent: true });
    });

  overlay
    .querySelector('[data-action="logout"]')
    ?.addEventListener("click", () => {
      clearInterval(sessionPromptCountdownInterval);
      sessionPromptCountdownInterval = null;
      overlay.remove();
      logoutAndRedirect();
    });
}

function formatDuration(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}m ${secs}s`;
}

async function refreshSession({ silent = false } = {}) {
  try {
    const res = await apiFetch("/auth/refresh", { method: "POST" });
    if (!res.ok) throw new Error("No se pudo extender la sesión.");
    const data = await res.json();
    const payload = data.payload ?? null;
    const accessExp = data.access_expires_at ?? payload?.exp ?? null;
    if (!payload || !accessExp) throw new Error("Respuesta de sesión incompleta.");

    applySessionPayload(payload, accessExp);
    return true;
  } catch (err) {
    console.error(err);
    if (!silent) {
      alert(err.message || "No se pudo extender la sesión.");
    }
    redirectToLogin();
    return false;
  }
}

function applySessionPayload(payload, accessExp) {
  if (typeof Session !== "undefined") {
    Session.save(payload, accessExp);
  }
  state.payload = payload;
  state.sessionExpiresAt = accessExp;
  state.isAdmin = (payload?.role ?? "").toLowerCase() === "admin";

  const userName = document.getElementById("userName");
  if (userName) {
    userName.textContent = payload.email ?? "Usuario autenticado";
  }

  startCountdown(accessExp, document.getElementById("sessionCountdown"));
  scheduleSessionPrompt(accessExp);
}
