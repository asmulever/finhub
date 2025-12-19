import { authStore } from './auth/authStore.js';

/** Devuelve en tiempo de ejecución la URL base que indica el entorno sin ningún hardcode. */
export const getBaseUrl = (() => {
  let cached = '';
  return () => {
    if (cached !== '') {
      return cached;
    }
    const envBase = window.__ENV?.API_BASE_URL;
    if (!envBase) {
      throw new Error('API_BASE_URL no está configurada en window.__ENV');
    }
    cached = envBase.endsWith('/') ? envBase.slice(0, -1) : envBase;
    return cached;
  };
})();

/** Construye la URL final agregando la base configurada si la ruta es relativa. */
const buildUrl = (path) => (path.startsWith('http') ? path : `${getBaseUrl()}${path}`);

/** Borra el token y redirige al login si el backend responde con 401. */
const handleUnauthorized = () => {
  authStore.clearToken();
  window.location.href = '/';
};

/** Fusiona headers adicionales con el header JSON obligatorio. */
const buildHeaders = (extraHeaders) => ({
  'Content-Type': 'application/json',
  ...extraHeaders,
});

/** Extrae el payload JSON o devuelve null si no viene contenido válido. */
const parsePayload = async (response) => response.json().catch(() => null);

/**
 * Envía una petición HTTP contra la API, gestionando tokens, errores y JSON.
 * @param path Ruta relativa o absoluta del recurso.
 * @param options Configuración adicional de fetch.
 */
export const apiClient = async (path, options = {}) => {
  const { method = 'GET', body, headers = {}, ...rest } = options;
  const composedHeaders = buildHeaders(headers);
  const token = authStore.getToken();
  if (token) {
    composedHeaders.Authorization = `Bearer ${token}`;
  }
  
  const response = await fetch(buildUrl(path), {
    method,
    credentials: 'include',
    headers: composedHeaders,
    body: body === undefined ? undefined : JSON.stringify(body),
    ...rest,
  });
  console.log(response);

  const payload = await parsePayload(response);
  if (!response.ok) {
    if (response.status === 401) {
      handleUnauthorized();
    }
    const err = payload?.error ?? {
      code: `http.${response.status}`,
      message: response.statusText,
    };
    throw { status: response.status, error: err };
  }

  return payload;
};

/** Genera un helper para métodos que envían body JSON. */
const withBody = (method) => (path, body) => apiClient(path, { method, body });

/** Genera un helper para métodos sin body. */
const withoutBody = (method) => (path) => apiClient(path, { method });

/** Envía un POST con body serializado. */
export const postJson = withBody('POST');

/** Envía un PATCH con body serializado. */
export const patchJson = withBody('PATCH');

/** Ejecuta una petición DELETE sin body. */
export const deleteJson = withoutBody('DELETE');

/** Ejecuta una petición GET sin body. */
export const getJson = withoutBody('GET');
