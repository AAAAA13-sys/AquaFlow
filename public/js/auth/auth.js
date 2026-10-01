// AquaFlow - authentication + RBAC guards.
//
// Real authentication happens on the server (api/auth.php verifies bcrypt
// hashes in MySQL). The browser keeps a lightweight session mirror in
// localStorage only to route users to the right portal; every API call is
// authorised server-side, so tampering with the mirror grants nothing.

function getSession() {
  try { return JSON.parse(localStorage.getItem('session') || 'null'); }
  catch (e) { return null; }
}

function setSession(user) {
  localStorage.setItem('session', JSON.stringify({ u: user.u, role: user.role, name: user.name, t: Date.now() }));
}

// Cashier login (server-verified)
async function apiLogin(username, password) {
  const result = await API.login(username, password);
  setSession(result.user);
  return result;
}

// Owner login (server-verified, requires the Owner PIN)
async function apiLoginOwner(username, password, pin) {
  const result = await API.loginOwner(username, password, pin);
  setSession(result.user);
  return result;
}

// Clears both the session and the local mirror, then returns to login.
function logout() {
  const finish = () => {
    try { localStorage.removeItem('session'); } catch (e) { /* ignore */ }
    window.location.href = '/';
  };
  if (typeof API !== 'undefined' && API) {
    API.logout().then(finish).catch(finish);
  } else {
    finish();
  }
}

// Guard for cashier terminal: requires any authenticated session
function guardCashier() {
  const s = getSession();
  if (!s) { window.location.href = '/'; return null; }
  return s;
}

// Guard for owner portal: requires admin role, sends others to the cashier terminal
function guardAdmin() {
  const s = getSession();
  if (!s) { window.location.href = '/owner/login'; return null; }
  if (s.role !== 'admin') { window.location.href = '/cashier'; return null; }
  return s;
}
