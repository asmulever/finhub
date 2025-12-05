// Utilidad mínima para decidir si la API es cross-origin y construir las URLs.
const API_BASE_URL = window.API_BASE_URL || "/api";

function trimTrailingSlash(value) {
  return value.replace(/\/+$/, "");
}

function normalizeRoute(route) {
  if (!route) return "";
  return route.startsWith("/") ? route : `/${route}`;
}

function resolveApiPathBase() {
  const path = new URL(API_BASE_URL, window.location.origin).pathname || "/";
  return trimTrailingSlash(path);
}

const API_PATH_BASE = resolveApiPathBase();

function isCrossOrigin(apiBaseUrl) {
  try {
    const apiOrigin = new URL(apiBaseUrl, window.location.origin).origin;
    return apiOrigin !== window.location.origin;
  } catch (err) {
    window.FrontendLogger?.warning("No se pudo resolver el origin del API", {
      reason: err instanceof Error ? err.message : String(err),
    });
    return false;
  }
}

const API_IS_CROSS_ORIGIN = isCrossOrigin(API_BASE_URL);

function buildApiUrl(route) {
  const normalized = normalizeRoute(route);
  if (API_IS_CROSS_ORIGIN) {
    return `${trimTrailingSlash(API_BASE_URL)}${normalized}`;
  }
  const base = API_PATH_BASE === "" ? "/" : API_PATH_BASE;
  return `${trimTrailingSlash(base)}${normalized}`;
}

function buildAuthHeaders() {
  if (window.Session && typeof window.Session.getAccessToken === "function") {
    const token = window.Session.getAccessToken();
    if (token) {
      return {
        Authorization: `Bearer ${token}`,
      };
    }
  }
  return {};
}

const API_DEFAULT_HEADERS = {
  Accept: "application/json",
};

function apiFetch(route, options = {}) {
  const url = buildApiUrl(route);
  const defaultCredentials = API_IS_CROSS_ORIGIN ? "include" : "same-origin";
  const headers = {
    ...API_DEFAULT_HEADERS,
    ...buildAuthHeaders(),
    ...(options.headers || {}),
  };
  const requestOptions = {
    credentials: defaultCredentials,
    ...options,
    headers,
  };
  return fetch(url, requestOptions);
}

window.apiFetch = apiFetch;
window.buildApiUrl = buildApiUrl;
