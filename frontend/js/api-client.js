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

  return fetch(url, requestOptions).then(async (response) => {
    if (
      response.ok &&
      !skipSessionExtend &&
      !SESSION_EXEMPT_ROUTES.has(route)
    ) {
      try {
        await refreshSessionTokens(defaultCredentials);
      } catch (err) {
        console.warn("No se pudo refrescar la sesión tras la acción.", err);
      }
    }
    return response;
  });
}

async function refreshSessionTokens(credentialsMode) {
  if (sessionRefreshPromise) {
    return sessionRefreshPromise;
  }

  sessionRefreshPromise = fetch(buildApiUrl(SESSION_REFRESH_PATH), {
    method: "POST",
    credentials:
      credentialsMode ?? (API_IS_CROSS_ORIGIN ? "include" : "same-origin"),
  }).finally(() => {
    sessionRefreshPromise = null;
  });

  const response = await sessionRefreshPromise;
  if (!response.ok) {
    throw new Error(`Refresh failed: ${response.status}`);
  }
}
