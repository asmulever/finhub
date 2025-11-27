document.addEventListener("DOMContentLoaded", () => {
  const select = document.getElementById("categorySelect");
  const statusEl = document.getElementById("status");
  const tbody = document.getElementById("quotesBody");
  const updatedEl = document.getElementById("updatedAt");
  const sessionExpirationEl = document.getElementById("sessionExpiration");
  let timer = null;

  async function loadCategories() {
    const res = await fetch("/api/quotes/categories");
    const data = await res.json();
    data.categories.forEach((cat, index) => {
      const opt = document.createElement("option");
      opt.value = cat;
      opt.textContent = cat.charAt(0).toUpperCase() + cat.slice(1);
      if (index === 0) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });
    select.addEventListener("change", () => fetchQuotes(select.value));
    if (data.categories.length > 0) {
      fetchQuotes(data.categories[0]);
    }
  }

  async function fetchQuotes(category) {
    clearInterval(timer);
    statusEl.textContent = "Actualizando " + category + "...";
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Cargando...</td></tr>';

    const res = await fetch(`/api/quotes/${encodeURIComponent(category)}`);
    const data = await res.json();
    if (!data.ok) {
      statusEl.textContent = data.error || "Error al obtener datos.";
      return;
    }

    updatedEl.textContent = new Date(data.updated_at * 1000).toLocaleTimeString();
    statusEl.textContent = "Mostrando " + data.category;
    tbody.innerHTML = "";

    data.items.forEach((item) => {
      const row = document.createElement("tr");
      const changeClass = item.change >= 0 ? "change-positive" : "change-negative";
      row.innerHTML = `
        <td>${item.symbol}</td>
        <td>${item.price?.toFixed ? item.price.toFixed(4) : item.price}</td>
        <td class="${changeClass}">${item.change?.toFixed ? item.change.toFixed(4) : item.change}</td>
        <td class="${changeClass}">${item.percent?.toFixed ? item.percent.toFixed(2) : item.percent}%</td>
        <td>${item.open ?? "--"}</td>
        <td>${item.high ?? "--"}</td>
        <td>${item.low ?? "--"}</td>
      `;
      tbody.appendChild(row);
    });

    timer = setInterval(() => fetchQuotes(category), 60000);
  }

  async function loadSessionInfo() {
    try {
      const res = await fetch("/api/auth/session", { credentials: "include" });
      if (!res.ok) {
        sessionExpirationEl.textContent = "N/D";
        return;
      }
      const data = await res.json();
      const exp = data.access_expires_at ?? data.payload?.exp ?? null;
      sessionExpirationEl.textContent = exp ? new Date(exp * 1000).toLocaleTimeString() : "N/D";
    } catch {
      sessionExpirationEl.textContent = "N/D";
    }
  }

  loadCategories().catch(() => {
    statusEl.textContent = "No se pudieron cargar las categorías.";
  });
  loadSessionInfo();
});
