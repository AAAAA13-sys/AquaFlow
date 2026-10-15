// System Settings Load & Save
function loadSettings() {
  const server = (typeof DB !== 'undefined' && DB.settings) ? DB.settings : {};
  let local = {};
  try { local = JSON.parse(localStorage.getItem('aquaflow_settings') || '{}'); } catch (e) { local = {}; }

  const station = server.station_name || local.station || 'AquaFlow Station';
  const lead = server.restock_lead_days || local.lead || 3;

  const st = document.getElementById('setStation'), ld = document.getElementById('setLead');
  if (st) st.value = station;
  if (ld) ld.value = lead;

  const brand = document.getElementById('brandName');
  if (brand) brand.textContent = station;
  document.title = station + ' - Owner Portal';
}

async function saveSettings() {
  const stationEl = document.getElementById('setStation');
  const leadEl = document.getElementById('setLead');
  const button = document.getElementById('saveSettingsButton');
  const status = document.getElementById('settingsStatus');
  if (button?.disabled) return;
  if (button) button.disabled = true;
  if (status) status.textContent = 'Saving…';

  const payload = {
    station_name: (stationEl ? stationEl.value : '').trim() || 'AquaFlow Station',
    restock_lead_days: +((leadEl && leadEl.value) || 3)
  };

  try {
    const result = await API.updateSettings(payload);
    DB.settings = result.settings;
    try {
      localStorage.setItem('aquaflow_settings', JSON.stringify({
        station: payload.station_name,
        lead: payload.restock_lead_days
      }));
    } catch (e) {}

    const brand = document.getElementById('brandName');
    if (brand) brand.textContent = payload.station_name;
    document.title = payload.station_name + ' - Owner Portal';
    if (status) status.textContent = 'Changes saved.';
  } catch (error) {
    if (status) status.textContent = error.message || 'Could not save the settings.';
  } finally {
    if (button) button.disabled = false;
  }
}


// Users List Rendering (CRUD + access control)
function escUser(value) {
  return esc(value);
}

function findUser(id) {
  return (DB.users || []).find(u => String(u.id) === String(id));
}

function renderUsers() {
  const body = document.getElementById('usersBody');
  if (!body) return;
  const summary = document.getElementById('teamSummary');
  if (summary) summary.innerHTML = '<article><span>Active staff</span><strong>' + DB.users.filter(u => ['admin', 'cashier'].includes(u.role) && u.active !== false).length + '</strong><small>Accounts with station access</small></article><article><span>Cashiers</span><strong>' + DB.users.filter(u => u.role === 'cashier').length + '</strong><small>POS access</small></article><article><span>Owners</span><strong>' + DB.users.filter(u => u.role === 'admin').length + '</strong><small>Station management access</small></article>';
  body.innerHTML = filterTableRows(DB.users.filter(u => ['admin', 'cashier'].includes(u.role)), 'usersBody').map(u => {
    const active = u.active !== false;
    const canManage = u.id !== undefined && u.id !== null;
    return '<tr><td>' + escUser(u.u) + '</td><td>' + escUser(u.name) + '</td>' +
      '<td><span class="pill ' + (u.role === 'admin' ? 'pill-ok' : 'pill-info') + '">' + escUser(u.role) + '</span></td>' +
      '<td>' + escUser(u.access || (u.role === 'admin' ? 'Full + Analytics' : 'POS only')) + '</td>' +
      '<td><span class="pill ' + (active ? 'pill-ok' : 'pill-bad') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>' +
      '<td style="white-space:nowrap;">' + (canManage
        ? '<button onclick="openUserEditor(' + u.id + ')" class="btn btn-ghost btn-sm">Edit</button> ' +
          '<button onclick="toggleUserActive(' + u.id + ')" class="btn btn-ghost btn-sm">' + (active ? 'Deactivate' : 'Activate') + '</button> ' +
          '<button onclick="deleteUser(' + u.id + ')" class="btn btn-ghost btn-sm">Delete</button>'
        : '<span class="cell-sub">offline</span>') + '</td></tr>';
  }).join('');
  showTableEmpty(body, 6);
}

let userCreatePending = false;
let userSavePending = false;

function updateUserRoleFields() {
  const admin = document.getElementById('usrRole')?.value === 'admin';
  const password = document.getElementById('usrPassword');
  document.getElementById('usrPasswordField')?.classList.toggle('hidden', !admin);
  document.getElementById('usrTemporaryHint')?.classList.toggle('hidden', admin);
  if (password) {
    password.required = admin;
    password.disabled = !admin;
    if (!admin) password.value = '';
  }
}

async function createUser(event) {
  if (event) event.preventDefault();
  if (userCreatePending) return false;
  const errorElement = document.getElementById('userCreateError');
  const showError = message => { if (errorElement) { errorElement.textContent = message; errorElement.classList.remove('hidden'); } else appNotice(message); };
  if (errorElement) errorElement.classList.add('hidden');
  const role = document.getElementById('usrRole') ? document.getElementById('usrRole').value : 'cashier';
  const payload = {
    name: document.getElementById('usrName') ? document.getElementById('usrName').value.trim() : '',
    username: document.getElementById('usrUsername') ? document.getElementById('usrUsername').value.trim() : '',
    role: role,
    email: document.getElementById('usrEmail')?.value.trim() || null
  };

  if (payload.name.length > 20) { showError('Use a name of at most 20 characters.'); return false; }
  if (!payload.email) { showError('A recovery email is required for every account.'); return false; }
  if (role === 'admin') {
    payload.password = document.getElementById('usrPassword')?.value || '';
    if (!validNewPassword(payload.password)) { showError('Use 8–64 characters with uppercase, lowercase and a number, without spaces.'); return false; }
  }
  if (!payload.name || !payload.username) {
    showError('Name and username are required.');
    return false;
  }
  const button = document.getElementById('userCreateButton');
  userCreatePending = true;
  if (button) button.disabled = true;
  try {
    const result = await API.createUser(payload);
    API.replaceUser(result.user);
    document.getElementById('userForm').reset();
    updateUserRoleFields();
    renderUsers();
    saveDB();
  } catch (error) {
    showError(error.message || 'Could not add the user.');
  } finally {
    userCreatePending = false;
    if (button) button.disabled = false;
  }
  return false;
}

async function openUserEditor(id) {
  if (userSavePending) return;
  const u = findUser(id);
  const wrap = document.getElementById('userEditWrap');
  const bodyEl = document.getElementById('userEditBody');
  if (!u || !wrap || !bodyEl) return;
  bodyEl.innerHTML =
    '<label for="usrEditEmail">Recovery email</label><input id="usrEditEmail" type="email" required class="form-input" maxlength="255" value="' + escUser(u.email || '') + '">' +
    '<p class="cell-sub">Editing <b>' + escUser(u.u) + '</b></p>' +
    '<label class="cell-sub">Full name</label><input id="usrEditName" class="form-input" value="' + escUser(u.name) + '" maxlength="20">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Role</label><select id="usrEditRole" class="form-input">' +
    '<option value="cashier"' + (u.role === 'cashier' ? ' selected' : '') + '>Cashier (POS only)</option>' +
    '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Owner (Full + Analytics)</option></select>' +
    '<div style="height:.5rem"></div><label class="cell-sub">New password (blank = keep)</label><div class="credential-control"><input id="usrEditPassword" type="password" class="form-input" minlength="8" maxlength="64" autocomplete="new-password"><button id="usrEditPasswordToggle" type="button" class="password-toggle-btn" onclick="togglePassword(\'usrEditPassword\', this)" aria-controls="usrEditPassword" aria-pressed="false" aria-label="Show password" title="Show password"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>' +
    '<p class="credential-hint">8–64 characters, uppercase, lowercase and a number. Leave blank to keep the current password.</p>' +
    '<p id="userEditError" class="auth-error hidden" role="alert"></p>' +
    '<div class="drawer-actions" style="margin-top:.75rem;"><button id="userSaveButton" onclick="saveUserEdit(' + u.id + ')" class="btn btn-primary btn-sm">Save</button>' +
    ' <button onclick="closeUserEditor()" class="btn btn-ghost btn-sm">Cancel</button></div>';
  wrap.classList.remove('hidden');
}

function closeUserEditor() {
  const wrap = document.getElementById('userEditWrap');
  if (wrap) wrap.classList.add('hidden');
}

async function saveUserEdit(id) {
  if (userSavePending) return;
  const errorElement = document.getElementById('userEditError');
  const showError = message => { if (errorElement) { errorElement.textContent = message; errorElement.classList.remove('hidden'); } else appNotice(message); };
  if (errorElement) errorElement.classList.add('hidden');
  const payload = {
    name: document.getElementById('usrEditName').value.trim(),
    role: document.getElementById('usrEditRole').value,
    email: document.getElementById('usrEditEmail')?.value.trim() || null
  };
  if (!payload.name || payload.name.length > 20) { showError('Use a name from 1 to 20 characters.'); return; }
  if (!payload.email) { showError('A recovery email is required.'); return; }
  if (!document.getElementById('usrEditEmail').reportValidity()) return;
  const pw = document.getElementById('usrEditPassword').value;  if (payload.role === 'admin' && findUser(id)?.role !== 'admin' && !pw) { showError('Set a strong password when promoting an account to admin.'); return; }
  if (pw && !validNewPassword(pw)) { showError('Use 8–64 characters with uppercase, lowercase and a number, without spaces.'); return; }
  if (pw) payload.password = pw;
  const button = document.getElementById('userSaveButton');
  userSavePending = true;
  if (button) button.disabled = true;
  try {
    const result = await API.updateUser(id, payload);
    API.replaceUser(result.user);
    closeUserEditor();
    renderUsers();
    saveDB();
  } catch (error) {
    showError(error.message || 'Could not save the user.');
  } finally {
    userSavePending = false;
    if (button) button.disabled = false;
  }
}

async function toggleUserActive(id) {
  const u = findUser(id);
  if (!u) return;
  try {
    const result = await API.updateUser(id, { is_active: !(u.active !== false) });
    API.replaceUser(result.user);
    renderUsers();
    saveDB();
  } catch (error) {
    appNotice(error.message || 'Could not update access.');
  }
}

async function deleteUser(id) {
  const u = findUser(id);
  if (!u) return;
  if (!await appConfirm('Delete user "' + u.u + '"?')) return;
  try {
    await API.deleteUser(id);
    API.removeUser(id);
    renderUsers();
    saveDB();
  } catch (error) {
    appNotice(error.message || 'Could not delete the user.');
  }
}
