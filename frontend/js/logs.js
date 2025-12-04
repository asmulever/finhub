const LOGS_STATE = {
  payload: null,
  sessionExpiresAt: null,
  isAdmin: false,
};

let sessionCountdownInterval = null;
let navInitialized = false;

let form;
let logsBody;
let paginationInfo;
let prevBtn;
let nextBtn;
let modal;
let closeModalBtn;
let detailContainer;
let stackTraceEl;
let payloadEl;
let queryEl;
let correlationInput;

let currentPage = 1;
const pageSize = 20;

document.addEventListener("DOMContentLoaded", async () => {
  form = document.getElementById("filters-form");
  logsBody = document.getElementById("logs-body");
  paginationInfo = document.getElementById("pagination-info");
  prevBtn = document.getElementById("prev-page");
  nextBtn = document.getElementById("next-page");
  modal = document.getElementById("log-modal");
  closeModalBtn = document.getElementById("close-modal");
  detailContainer = document.getElementById("log-detail");
  stackTraceEl = document.getElementById("stack-trace");
  payloadEl = document.getElementById("payload");
  queryEl = document.getElementById("query-params");
  correlationInput = form?.querySelector('input[name="correlation_id"]');

  const sessionPayload =
    typeof Session !== "undefined" ? Session.getPayload() : null;
  const sessionExpiresAt =
    typeof Session !== "undefined" ? Session.getExpiresAt() : null;
  if (
    !sessionPayload ||
    !sessionExpiresAt ||
    (typeof Session !== "undefined" &&
      Session.isExpired &&
      Session.isExpired(sessionExpiresAt))
  ) {
    redirectToLogin();
    return;
  }

  LOGS_STATE.payload = sessionPayload;
  LOGS_STATE.sessionExpiresAt = sessionExpiresAt;
  LOGS_STATE.isAdmin =
    (sessionPayload?.role ?? "").toLowerCase() === "admin";

  if (!LOGS_STATE.isAdmin) {
    window.location.href = "/frontend/dashboard.html";
    return;
  }

  setupNavigation();
  startCountdown(LOGS_STATE.sessionExpiresAt);
  await loadFilterOptions();
  initializeFilters();
  setupCorrelationInput();
  registerUiEvents();
  fetchLogs();
});

async function fetchLogs(page = 1) {
  currentPage = page;
  const params = buildFilterParams();
  params.set("page", String(page));
  params.set("page_size", String(pageSize));

  logsBody.innerHTML = `
      <tr>
        <td colspan="7">Cargando registros...</td>
      </tr>
    `;

  try {
    const response = await apiFetch(`/logs?${params.toString()}`, {
      method: "GET",
    });

    if (response.status === 401) {
      redirectToLogin();
      return;
    }

    if (!response.ok) {
      throw new Error(`Error ${response.status}`);
    }

    const data = await response.json();
    renderLogs(data.data || []);
    renderPagination(data.pagination);
  } catch (error) {
    window.FrontendLogger?.error("No se pudieron cargar los logs", {
      reason: error instanceof Error ? error.message : String(error),
    });
    logsBody.innerHTML = `
        <tr>
          <td colspan="7">No se pudieron cargar los registros.</td>
        </tr>
      `;
  }
}

function renderLogs(logs) {
  if (!logs.length) {
    logsBody.innerHTML = `
        <tr>
          <td colspan="7">No hay registros para los filtros actuales.</td>
        </tr>
      `;
    return;
  }

  logsBody.innerHTML = logs
    .map(
      (log) => `
        <tr data-id="${log.id}">
          <td>${new Date(log.created_at).toLocaleString()}</td>
          <td>${log.http_status}</td>
          <td>${log.method}</td>
          <td>${log.route}</td>
          <td><span class="badge ${log.level}">${log.level}</span></td>
          <td>${log.message}</td>
        </tr>
      `
    )
    .join("");
}

function renderPagination(pagination = {}) {
  const totalPages = pagination.total_pages ?? 1;
  paginationInfo.textContent = `Página ${pagination.page ?? 1} de ${totalPages} (Total ${pagination.total ?? 0})`;
  prevBtn.disabled = currentPage <= 1;
  nextBtn.disabled = currentPage >= totalPages;
}

async function showLogDetail(id) {
  try {
    const response = await apiFetch(`/logs/${id}`, { method: "GET" });

    if (response.status === 401) {
      redirectToLogin();
      return;
    }

    if (!response.ok) {
      throw new Error(`Error ${response.status}`);
    }

    const log = await response.json();
    renderLogDetail(log);
    modal.classList.add("active");
  } catch (error) {
    window.FrontendLogger?.error("No se pudo cargar el detalle del log", {
      reason: error instanceof Error ? error.message : String(error),
      logId: id,
    });
  }
}

function renderLogDetail(log) {
  detailContainer.innerHTML = `
      <div>
        <strong>Fecha</strong>
        <div>${new Date(log.created_at).toLocaleString()}</div>
      </div>
      <div>
        <strong>Nivel</strong>
        <div>${log.level}</div>
      </div>
      <div>
        <strong>Status</strong>
        <div>${log.http_status}</div>
      </div>
      <div>
        <strong>Ruta</strong>
        <div>${log.method} ${log.route}</div>
      </div>
      <div>
        <strong>Correlation ID</strong>
        <div>${log.correlation_id}</div>
      </div>
      <div style="grid-column: 1 / -1;">
        <strong>Mensaje</strong>
        <div>${log.message}</div>
      </div>
    `;
  stackTraceEl.textContent = log.stack_trace || "Sin stack trace.";
  payloadEl.textContent = formatJsonField(log.request_payload);
  queryEl.textContent = formatJsonField(log.query_params);
}

function formatJsonField(field) {
  if (!field) return "Sin datos.";
  try {
    const parsed = typeof field === "string" ? JSON.parse(field) : field;
    return JSON.stringify(parsed, null, 2);
  } catch {
    return field;
  }
}

function buildFilterParams() {
  const params = new URLSearchParams();
  const controls = Array.from(form.elements).filter(
    (el) => el.name && !el.disabled
  );
  const today = getTodayString();

  let fromDate = today;
  let toDate = today;

  controls.forEach((control) => {
    const { name, type } = control;
    let value =
      typeof control.value === "string" ? control.value.trim() : control.value;

    if (type === "date") {
      if (!value || value > today) {
        value = today;
      }
      control.value = value;
      if (name === "date_from") {
        fromDate = value;
      } else if (name === "date_to") {
        toDate = value;
      }
      return;
    }

    const defaultValue = control.dataset.defaultValue ?? "";
    if (value === "" || value === defaultValue) {
      return;
    }
    params.set(name, value);
  });

  if (fromDate > toDate) {
    toDate = fromDate;
    const toInput = form.querySelector('input[name="date_to"]');
    if (toInput) {
      toInput.value = toDate;
    }
  }

  params.set("date_from", fromDate);
  params.set("date_to", toDate);

  return params;
}

function initializeFilters() {
  const controls = Array.from(form.elements).filter((el) => el.name);
  const today = getTodayString();

  controls.forEach((control) => {
    if (control.type === "date") {
      control.max = today;
      if (!control.value || control.value > today) {
        control.value = today;
      }
      return;
    }

    control.dataset.defaultValue =
      typeof control.value === "string" ? control.value.trim() : control.value ?? "";
  });
}

function setupCorrelationInput() {
  if (!correlationInput) {
    return;
  }

  const disableControl = () => {
    correlationInput.readOnly = true;
    correlationInput.classList.add("text-muted");
    correlationInput.placeholder = "TODOS";
    correlationInput.dataset.defaultValue = "";
    correlationInput.value = "";
  };

  const enableControl = () => {
    correlationInput.readOnly = false;
    correlationInput.classList.remove("text-muted");
    correlationInput.placeholder = "Ingresa correlation id";
  };

  disableControl();

  correlationInput.addEventListener("focus", () => {
    if (correlationInput.readOnly) {
      enableControl();
    }
  });

  correlationInput.addEventListener("blur", () => {
    if (correlationInput.value.trim() === "") {
      disableControl();
    }
  });
}

function registerUiEvents() {
  form.addEventListener("input", () => fetchLogs(1));
  prevBtn.addEventListener("click", () => fetchLogs(currentPage - 1));
  nextBtn.addEventListener("click", () => fetchLogs(currentPage + 1));
  closeModalBtn.addEventListener("click", () => modal.classList.remove("active"));
  modal.addEventListener("click", (event) => {
    if (event.target === modal) {
      modal.classList.remove("active");
    }
  });

  logsBody.addEventListener("click", (event) => {
    const row = event.target.closest("tr");
    if (row && row.dataset.id) {
      showLogDetail(row.dataset.id);
    }
  });
}

function getTodayString() {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, "0");
  const day = String(today.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

async function loadFilterOptions() {
  try {
    const response = await apiFetch("/logs/filters");

    if (response.status === 401) {
      redirectToLogin();
      return;
    }

    if (!response.ok) {
      throw new Error(`Status ${response.status}`);
    }

    const data = await response.json();
    populateSelectOptions('http_status', data.http_statuses || [], (value) => value);
    populateSelectOptions('level', data.levels || [], (value) => value);
    populateSelectOptions('route', data.routes || [], (value) => value);
  } catch (error) {
    window.FrontendLogger?.warning('No se pudieron cargar las opciones de filtros', {
      reason: error instanceof Error ? error.message : String(error),
    });
  }
}

function populateSelectOptions(name, values, formatValue) {
  const select = form?.querySelector(`select[name="${name}"]`);
  if (!select) {
    return;
  }

  // preserve the first option (Todos)
  while (select.options.length > 1) {
    select.remove(1);
  }

  values.forEach((item) => {
    if (item === null || item === '') {
      return;
    }
    const option = document.createElement('option');
    option.value = String(formatValue(item));
    option.textContent = String(item);
    select.appendChild(option);
  });
}

function setupNavigation() {
  const email = LOGS_STATE.payload?.email ?? "Usuario autenticado";
  const userMenuEmail = document.getElementById("userMenuEmail");
  if (userMenuEmail) {
    userMenuEmail.textContent = email;
  }

  document
    .querySelectorAll('[data-role="admin-link"]')
    .forEach((link) => link.classList.toggle("d-none", !LOGS_STATE.isAdmin));

  if (navInitialized) {
    return;
  }

  document
    .querySelectorAll("[data-user-action]")
    .forEach((item) =>
      item.addEventListener("click", (event) => {
        event.preventDefault();
        handleUserMenuAction(item.dataset.userAction);
      })
    );

  navInitialized = true;
}

function handleUserMenuAction(action) {
  switch (action) {
    case "profile":
    case "preferences":
      alert("Función disponible únicamente desde el dashboard.");
      break;
    case "users":
      window.location.href = "/frontend/dashboard.html";
      break;
    case "logs":
      break;
    case "logout":
      logoutAndRedirect();
      break;
    default:
      break;
  }
}

async function logoutAndRedirect() {
  clearCountdown();
  if (typeof Session !== "undefined") {
    Session.clear();
  }
  window.location.href = "/index.php";
}

function redirectToLogin() {
  if (typeof Session !== "undefined") {
    Session.clear();
  }
  window.location.href = "/index.php";
}

function startCountdown(exp) {
  const target = document.getElementById("sessionCountdown");
  if (!target || !exp) {
    return;
  }

  clearCountdown();

  const update = () => {
    const now = Math.floor(Date.now() / 1000);
    const secondsLeft = exp - now;
    if (secondsLeft <= 0) {
      target.textContent = "expirada";
      clearCountdown();
      return;
    }
    target.textContent = formatDuration(secondsLeft);
  };

  update();
  sessionCountdownInterval = setInterval(update, 1000);
}

function clearCountdown() {
  if (sessionCountdownInterval) {
    clearInterval(sessionCountdownInterval);
    sessionCountdownInterval = null;
  }
}

function formatDuration(seconds) {
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return `${mins}m ${secs}s`;
}
