// Utilidad mínima para decidir si la API es cross-origin y construir las URLs.
const API_BASE_URL = window.API_BASE_URL || "/index.php";

function isCrossOrigin(apiBaseUrl) {
  try {
    const apiOrigin = new URL(apiBaseUrl, window.location.origin).origin;
    return apiOrigin !== window.location.origin;
  } catch (err) {
    console.warn("No se pudo resolver el origin del API", err);
    return false;
  }
}

const API_IS_CROSS_ORIGIN = isCrossOrigin(API_BASE_URL);

function trimTrailingSlash(value) {
  return value.replace(/\/+$/, "");
}

function normalizeRoute(route) {
  if (!route) return "";
  return route.startsWith("/") ? route : `/${route}`;
}

const API_PATH_BASE = trimTrailingSlash(
  new URL(API_BASE_URL, window.location.origin).pathname || "/"
);

function buildApiUrl(route) {
  const normalized = normalizeRoute(route);
  if (API_IS_CROSS_ORIGIN) {
    return `${trimTrailingSlash(API_BASE_URL)}${normalized}`;
  }
  const base = API_PATH_BASE === "" ? "/" : API_PATH_BASE;
  return `${trimTrailingSlash(base)}${normalized}`;
}

const SESSION_REFRESH_PATH = "/auth/refresh";
const SESSION_EXEMPT_ROUTES = new Set([
  "/auth/login",
  "/auth/logout",
  SESSION_REFRESH_PATH,
]);
let sessionRefreshPromise = null;

function apiFetch(route, options = {}) {
  const { skipSessionExtend = false, ...fetchOptions } = options;
  const url = buildApiUrl(route);
  const defaultCredentials = API_IS_CROSS_ORIGIN ? "include" : "same-origin";
  const requestOptions = { credentials: defaultCredentials, ...fetchOptions };
  const mustExtend = !skipSessionExtend && !SESSION_EXEMPT_ROUTES.has(route);

  const refreshPromise = mustExtend
    ? refreshSessionTokens(defaultCredentials).catch((err) => {
        console.warn("No se pudo extender la sesión antes de la acción.", err);
      })
    : Promise.resolve();

  return refreshPromise.then(() => fetch(url, requestOptions));
}

async function refreshSessionTokens(credentialsMode) {
  if (sessionRefreshPromise) {
    return sessionRefreshPromise;
  }

  sessionRefreshPromise = fetch(buildApiUrl(SESSION_REFRESH_PATH), {
    method: "POST",
    credentials:
      credentialsMode ?? (API_IS_CROSS_ORIGIN ? "include" : "same-origin"),
  })
    .then(async (response) => {
      if (!response.ok) {
        throw new Error(`Refresh failed: ${response.status}`);
      }

      const contentType = response.headers.get("content-type") || "";
      if (contentType.includes("application/json")) {
        const data = await response.json();
        const payload = data.payload ?? null;
        const accessExp = data.access_expires_at ?? payload?.exp ?? null;

        if (payload && accessExp && typeof Session !== "undefined") {
          Session.save(payload, accessExp);
          window.dispatchEvent(
            new CustomEvent("session:refreshed", {
              detail: { payload, accessExp },
            })
          );
        }
      }

      return response;
    })
    .finally(() => {
      sessionRefreshPromise = null;
    });

  await sessionRefreshPromise;
}

window.apiFetch = apiFetch;
window.buildApiUrl = buildApiUrl;
