// AquaFlow - shared authentication + RBAC guards
// Used by the login pages (root) and by the ADMIN/ and CASHIER/ tab pages,
// which are both exactly one folder deep, so the relative paths below resolve
// correctly from either folder.

function getSession() {
  try { return JSON.parse(localStorage.getItem('session') || 'null'); }
  catch (e) { return null; }
}

function setSession(user) {
  localStorage.setItem('session', JSON.stringify({ u: user.u, role: user.role, name: user.name, t: Date.now() }));
}

function logout() {
  localStorage.removeItem('session');
  location.href = '../index.html';
}

function attemptLogin(u, p) {
  const found = (DB.users || []).find(x => x.u === u && x.p === p);
  if (!found) return { ok: false };
  setSession(found);
  return { ok: true, role: found.role };
}

// Owner login is a different path: username + password + Owner PIN
function attemptAdminLogin(u, p, pin) {
  const found = (DB.users || []).find(x => x.u === u && x.p === p && x.role === 'admin');
  if (!found) return { ok: false, reason: 'Invalid owner username or password.' };
  if ((found.pin || '') !== (pin || '')) { return { ok: false, reason: 'Wrong Owner PIN.' }; }
  setSession(found);
  return { ok: true, role: found.role };
}

// Guard for cashier terminal: requires any authenticated session
function guardCashier() {
  const s = getSession();
  if (!s) { location.href = '../index.html'; return null; }
  return s;
}

// Guard for owner portal: requires admin role, sends others to the cashier terminal
function guardAdmin() {
  const s = getSession();
  if (!s) { location.href = '../admin-login.html'; return null; }
  if (s.role !== 'admin') { location.href = '../CASHIER/index.html'; return null; }
  return s;
}
