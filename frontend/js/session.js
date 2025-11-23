const Session = (() => {
  const TOKEN_KEY = "finhub_token";
  const PAYLOAD_KEY = "finhub_payload";
  const EMAIL_COOKIE = "finhubRememberEmail";

  function save(token, payload) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(PAYLOAD_KEY, JSON.stringify(payload));
  }

  function clear() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(PAYLOAD_KEY);
  }

  function getToken() {
    return localStorage.getItem(TOKEN_KEY);
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

  function isExpired(payload) {
    if (!payload || !payload.exp) return true;
    const now = Math.floor(Date.now() / 1000);
    return now >= Number(payload.exp);
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
    getToken,
    getPayload,
    isExpired,
    rememberEmail,
    getRememberedEmail,
    clearRememberedEmail,
  };
})();
