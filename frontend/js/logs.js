document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("filters-form");
  const logsBody = document.getElementById("logs-body");
  const paginationInfo = document.getElementById("pagination-info");
  const prevBtn = document.getElementById("prev-page");
  const nextBtn = document.getElementById("next-page");
  const modal = document.getElementById("log-modal");
  const closeModalBtn = document.getElementById("close-modal");
  const detailContainer = document.getElementById("log-detail");
  const stackTraceEl = document.getElementById("stack-trace");
  const payloadEl = document.getElementById("payload");
  const queryEl = document.getElementById("query-params");

  let currentPage = 1;
  const pageSize = 20;

  async function fetchLogs(page = 1) {
    currentPage = page;
    const params = new URLSearchParams(new FormData(form));
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
          <td>${log.user_id ?? "-"}</td>
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
        <strong>Usuario</strong>
        <div>${log.user_id ?? "-"}</div>
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

  fetchLogs();
});
