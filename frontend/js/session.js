const Session = (() => {
  const PAYLOAD_KEY = "finhub_payload";
  const EXPIRATION_KEY = "finhub_exp";
  const ACCESS_TOKEN_KEY = "finhub_access_token";
  const REFRESH_TOKEN_KEY = "finhub_refresh_token";
  const EMAIL_COOKIE = "finhubRememberEmail";
  const storage = window.localStorage;

  // El estado de sesión se mantiene exclusivamente sobre localStorage.
  function save(payload, expiresAt, tokens = {}) {
    storage.setItem(PAYLOAD_KEY, JSON.stringify(payload));
    if (expiresAt) {
      storage.setItem(EXPIRATION_KEY, String(expiresAt));
    } else {
      storage.removeItem(EXPIRATION_KEY);
    }
    saveTokens(tokens.access, tokens.refresh);
  }

  function clear() {
    storage.removeItem(PAYLOAD_KEY);
    storage.removeItem(EXPIRATION_KEY);
    clearTokens();
  }

  function getPayload() {
    const raw = storage.getItem(PAYLOAD_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  function getExpiresAt() {
    const raw = storage.getItem(EXPIRATION_KEY);
    if (!raw) return null;
    const parsed = Number(raw);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function isExpired(expiration) {
    if (!expiration) return true;
    const now = Math.floor(Date.now() / 1000);
    return now >= Number(expiration);
  }

  function hasActiveSession() {
    const expiration = getExpiresAt();
    return !isExpired(expiration);
  }

  function saveTokens(accessToken, refreshToken) {
    if (accessToken) {
      storage.setItem(ACCESS_TOKEN_KEY, accessToken);
    } else {
      storage.removeItem(ACCESS_TOKEN_KEY);
    }

    if (refreshToken) {
      storage.setItem(REFRESH_TOKEN_KEY, refreshToken);
    } else {
      storage.removeItem(REFRESH_TOKEN_KEY);
    }
  }

  function clearTokens() {
    storage.removeItem(ACCESS_TOKEN_KEY);
    storage.removeItem(REFRESH_TOKEN_KEY);
  }

  function getAccessToken() {
    return storage.getItem(ACCESS_TOKEN_KEY);
  }

  function getRefreshToken() {
    return storage.getItem(REFRESH_TOKEN_KEY);
  }

  function rememberEmail(email) {
    Cookies.set(EMAIL_COOKIE, email, { expires: 3650 });
  }

  function getRememberedEmail() {
    return Cookies.get(EMAIL_COOKIE) ?? "";
  }

  function clearRememberedEmail() {
    Cookies.remove(EMAIL_COOKIE);
  }

  const manager = {
    save,
    clear,
    getPayload,
    getExpiresAt,
    isExpired,
    hasActiveSession,
    saveTokens,
    getAccessToken,
    getRefreshToken,
    rememberEmail,
    getRememberedEmail,
    clearRememberedEmail,
  };

  if (typeof window !== "undefined") {
    window.Session = manager;
  }

  return manager;
})();
