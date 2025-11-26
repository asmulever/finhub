// FrontendLogger centraliza los logs del navegador y captura errores globales para enviarlos al backend.
(() => {
  const LEVELS = { debug: 10, info: 20, warning: 30, error: 40 };
  const ENDPOINT = resolveEndpoint();
  const DEFAULT_HEADERS = { 'Content-Type': 'application/json' };

  function trimTrailingSlash(value) {
    return value.replace(/\/+$/, '');
  }

  function normalizeRoute(route) {
    if (!route) return '';
    return route.startsWith('/') ? route : `/${route}`;
  }

  function resolveEndpoint() {
    if (typeof window.buildApiUrl === 'function') {
      try {
        return window.buildApiUrl('/logs/frontend');
      } catch {
        // fallback to manual resolution
      }
    }

    const apiBase = window.API_BASE_URL || '/api';
    try {
      const baseUrl = new URL(apiBase, window.location.origin);
      const basePath = trimTrailingSlash(baseUrl.pathname || '/');
      const normalized = normalizeRoute('/logs/frontend');
      return `${baseUrl.origin}${trimTrailingSlash(basePath)}${normalized}`;
    } catch {
      return '/api/logs/frontend';
    }
  }

  function resolveUserId() {
    try {
      if (window.Session && typeof window.Session.getPayload === 'function') {
        const payload = window.Session.getPayload();
        const uid = payload && (payload.uid ?? payload.id);
        return typeof uid === 'number' ? uid : parseInt(uid, 10) || null;
      }
    } catch (err) {
      console.warn('No se pudo obtener el usuario para el log.', err);
    }
    return null;
  }

  function sanitizeContext(context) {
    if (!context || typeof context !== 'object') {
      return {};
    }

    const clean = Array.isArray(context) ? [] : {};
    for (const key of Object.keys(context)) {
      const value = context[key];
      if (value === null || typeof value === 'undefined') {
        clean[key] = value;
        continue;
      }

      if (typeof value === 'string') {
        clean[key] = value.length > 200 ? `${value.slice(0, 200)}…` : value;
        continue;
      }

      if (typeof value === 'number' || typeof value === 'boolean') {
        clean[key] = value;
        continue;
      }

      if (Array.isArray(value)) {
        clean[key] = sanitizeContext(value);
        continue;
      }

      if (typeof value === 'object') {
        clean[key] = sanitizeContext(value);
        continue;
      }

      clean[key] = String(value);
    }

    return clean;
  }

  function buildPayload(level, message, context) {
    return {
      level,
      message: String(message),
      context: sanitizeContext(context),
      url: window.location.href,
      userAgent: navigator.userAgent,
      timestamp: new Date().toISOString(),
      userId: resolveUserId(),
      correlationId: window.__CORRELATION_ID__ || null,
    };
  }

  async function sendToBackend(payload) {
    try {
      await fetch(ENDPOINT, {
        method: 'POST',
        headers: DEFAULT_HEADERS,
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        keepalive: true,
      });
    } catch (err) {
      console.warn('No se pudo enviar el log al backend.', err);
    }
  }

  function log(level, message, context = {}) {
    if (!LEVELS[level]) {
      console.warn('Nivel de log desconocido:', level);
      return;
    }

    const payload = buildPayload(level, message, context);
    sendToBackend(payload);
    mirrorToConsole(level, payload.message, context);
  }

  function mirrorToConsole(level, message, context) {
    const prefixed = `[${level.toUpperCase()}] ${message}`;
    if (level === 'error') {
      console.error(prefixed, context);
      return;
    }
    if (level === 'warning') {
      console.warn(prefixed, context);
      return;
    }
    if (level === 'info') {
      console.info(prefixed, context);
      return;
    }
    console.debug(prefixed, context);
  }

  window.FrontendLogger = {
    debug: (message, context) => log('debug', message, context),
    info: (message, context) => log('info', message, context),
    warning: (message, context) => log('warning', message, context),
    error: (message, context) => log('error', message, context),
  };

  // Captura global de errores JS y promesas rechazadas.
  window.addEventListener('error', (event) => {
    if (!event) return;
    const context = {
      source: 'window.error',
      filename: event.filename,
      lineno: event.lineno,
      colno: event.colno,
    };
    window.FrontendLogger.error(event.message || 'Unhandled error event', context);
  }, true);

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event && event.reason ? String(event.reason) : 'Unknown reason';
    window.FrontendLogger.error('Unhandled promise rejection', {
      source: 'unhandledrejection',
      reason,
    });
  });
})();
