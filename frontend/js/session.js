const Session = (() => {
  const PAYLOAD_KEY = "finhub_payload";
  const EXPIRATION_KEY = "finhub_exp";
  const EMAIL_COOKIE = "finhubRememberEmail";

  function save(payload, expiresAt) {
    localStorage.setItem(PAYLOAD_KEY, JSON.stringify(payload));
    if (expiresAt) {
      localStorage.setItem(EXPIRATION_KEY, String(expiresAt));
    }
  }

  function clear() {
    localStorage.removeItem(PAYLOAD_KEY);
    localStorage.removeItem(EXPIRATION_KEY);
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
    rememberEmail,
    getRememberedEmail,
    clearRememberedEmail,
  };
})();
