const Session = (() => {
  const PAYLOAD_KEY = "finhub_payload";
  const EXPIRATION_KEY = "finhub_exp";
  const ACCESS_TOKEN_KEY = "finhub_access_token";
  const REFRESH_TOKEN_KEY = "finhub_refresh_token";
  const EMAIL_COOKIE = "finhubRememberEmail";

  function save(payload, expiresAt, tokens = {}) {
    localStorage.setItem(PAYLOAD_KEY, JSON.stringify(payload));
    if (expiresAt) {
      localStorage.setItem(EXPIRATION_KEY, String(expiresAt));
    } else {
      localStorage.removeItem(EXPIRATION_KEY);
    }
    saveTokens(tokens.access, tokens.refresh);
  }

  function clear() {
    localStorage.removeItem(PAYLOAD_KEY);
    localStorage.removeItem(EXPIRATION_KEY);
    clearTokens();
  }

  function getPayload() {
    const raw = localStorage.getItem(PAYLOAD_KEY);
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  function getExpiresAt() {
    const raw = localStorage.getItem(EXPIRATION_KEY);
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
      localStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
    } else {
      localStorage.removeItem(ACCESS_TOKEN_KEY);
    }

    if (refreshToken) {
      localStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
    } else {
      localStorage.removeItem(REFRESH_TOKEN_KEY);
    }
  }

  function clearTokens() {
    localStorage.removeItem(ACCESS_TOKEN_KEY);
    localStorage.removeItem(REFRESH_TOKEN_KEY);
  }

  function getAccessToken() {
    return localStorage.getItem(ACCESS_TOKEN_KEY);
  }

  function getRefreshToken() {
    return localStorage.getItem(REFRESH_TOKEN_KEY);
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

  return {
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
})();
