const fs = require('fs');
const vm = require('vm');
const path = require('path');

function assert(condition, message) {
  if (!condition) {
    throw new Error(`Assertion failed: ${message}`);
  }
}

class LocalStorageMock {
  constructor() {
    this.store = new Map();
  }

  setItem(key, value) {
    this.store.set(key, String(value));
  }

  getItem(key) {
    return this.store.has(key) ? this.store.get(key) : null;
  }

  removeItem(key) {
    this.store.delete(key);
  }
}

const sandbox = {
  window: {
    localStorage: new LocalStorageMock(),
  },
  Cookies: {
    set: () => {},
    get: () => undefined,
    remove: () => {},
  },
  console,
};

sandbox.Date = Date;
const sandboxDate = sandbox.Date;

vm.createContext(sandbox);
const sessionScript = fs.readFileSync(path.join(__dirname, '../frontend/js/session.js'), 'utf8');
vm.runInContext(sessionScript, sandbox);

const { Session } = sandbox.window;

const nowSeconds = Math.floor(Date.now() / 1000);
const expiresAt = nowSeconds + 120;
const payload = { uid: 99, email: 'tester@example.com' };

Session.save(payload, expiresAt, { access: 'access-token', refresh: 'refresh-token' });

assert(Session.getPayload()?.uid === payload.uid, 'La sesión debe almacenar el payload correctamente');
assert(Session.getPayload()?.email === payload.email, 'La sesión debe mantener el email del usuario');
assert(Session.getExpiresAt() === expiresAt, 'El valor expiración debe guardarse en localStorage');
assert(Session.getAccessToken() === 'access-token', 'El token de acceso debe escribirse en localStorage');
assert(Session.getRefreshToken() === 'refresh-token', 'El token de refresco debe escribirse en localStorage');

const originalNow = sandboxDate.now;
sandboxDate.now = () => (expiresAt - 30) * 1000; // antes de expirar
assert(Session.isExpired(expiresAt) === false, 'isExpired debe devolver falso mientras queda tiempo');
assert(Session.hasActiveSession() === true, 'hasActiveSession debe reaccionar a isExpired antes de expirar');

sandboxDate.now = () => (expiresAt + 10) * 1000; // después de expirar
assert(Session.isExpired(expiresAt) === true, 'isExpired debe devolver verdadero cuando el token venció');
assert(Session.hasActiveSession() === false, 'hasActiveSession debe fallar al detectar expiración');

sandboxDate.now = originalNow;

Session.clear();
assert(Session.getPayload() === null, 'Al borrar la sesión, el payload debe desaparecer');
assert(Session.getAccessToken() === null, 'Al borrar la sesión, el token de acceso debe eliminarse');
assert(Session.getRefreshToken() === null, 'Al borrar la sesión, el token de refresco debe eliminarse');

console.log('Client session validation test passed: lectura, limites y params verificados.');
