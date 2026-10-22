// AquaFlow - authentication + RBAC guards.
//
// Real authentication happens on the server (api/auth.php verifies bcrypt
// hashes in MySQL). The browser keeps a lightweight session mirror in
// localStorage only to route users to the right portal; every API call is
// authorised server-side, so tampering with the mirror grants nothing.

function roleHome(role) {
  return ['super_admin', 'admin'].includes(role) ? '/admin' : ['refiller', 'rider'].includes(role) ? '/employee' : '/cashier';
}

function roleLabel(role) {
  return {super_admin: 'Super Admin', admin: 'Admin (manager)', cashier: 'Cashier', refiller: 'Refiller', rider: 'Delivery Rider'}[role] || role;
}

function getSession() {
  try { return JSON.parse(localStorage.getItem('session') || 'null'); }
  catch (e) { return null; }
}

function setSession(user) {
  if (getSession()?.u !== user.u || getSession()?.role !== user.role) { localStorage.removeItem('aquaflow_db'); localStorage.removeItem('aquaflow_pos'); }
  localStorage.setItem('session', JSON.stringify({ u: user.u, role: user.role, name: user.name, must_change_password: !!user.must_change_password, t: Date.now() }));
}

function showLoginError(message) {
  const error = document.getElementById('err');
  error.textContent = message;
  error.classList.remove('hidden');
}

// Clears both the session and the local mirror, then returns to login.
function logout() {
  const loginUrl = '/';
  const finish = () => {
    try { localStorage.removeItem('aquaflow_db'); localStorage.removeItem('aquaflow_pos'); localStorage.removeItem('session'); } catch (e) { /* ignore */ }
    window.location.href = loginUrl;
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
  if (['refiller', 'rider'].includes(s.role)) { window.location.href = '/employee'; return null; }
  return s;
}

// Guard for owner portal: requires admin role, sends others to the cashier terminal
function guardAdmin() {
  const s = getSession();
  if (!s) { window.location.href = '/'; return null; }
  if (!['super_admin', 'admin'].includes(s.role)) { window.location.href = roleHome(s.role); return null; }
  return s;
}

// Shared credential controls for sign-in and account management.
const EYE_ICON_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF_ICON_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24m-7.07-7.07C4.16 8.5 2 12 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61M2 2l20 20"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/></svg>';

function togglePassword(id, button) {
  const field = document.getElementById(id);
  if (!field) return;
  const show = field.type === 'password';
  field.type = show ? 'text' : 'password';
  if (button) {
    if ('innerHTML' in button && typeof button.innerHTML === 'string') {
      button.innerHTML = show ? EYE_OFF_ICON_SVG : EYE_ICON_SVG;
    } else {
      button.textContent = show ? 'Hide' : 'Show';
    }
    button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    button.setAttribute('aria-pressed', String(show));
    button.title = show ? 'Hide password' : 'Show password';
  }
}

function validNewPassword(value) {
  return /^[\x21-\x7E]{8,64}$/.test(value) && /[a-z]/.test(value) && /[A-Z]/.test(value) && /[0-9]/.test(value);
}

function generateAccountPassword(id) {
  const field = document.getElementById(id);
  if (!field) return;
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let password;
  do {
    password = '';
    while (password.length < 16) {
      const bytes = crypto.getRandomValues(new Uint8Array(32));
      for (const byte of bytes) {
        if (byte < 248 && password.length < 16) password += alphabet[byte % alphabet.length];
      }
    }
  } while (!validNewPassword(password));
  field.value = password;
  field.type = 'password';
  const toggle = document.getElementById(id + 'Toggle');
  if (toggle) { toggle.textContent = 'Show'; toggle.setAttribute('aria-pressed', 'false'); }
  field.dispatchEvent(new Event('input', {bubbles:true}));
}
