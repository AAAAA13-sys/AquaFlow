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
  if (summary) summary.innerHTML = '<article><span>Active staff</span><strong>' + DB.users.filter(u => u.active !== false).length + '</strong><small>Accounts with station access</small></article><article><span>Cashiers</span><strong>' + DB.users.filter(u => u.role === 'cashier').length + '</strong><small>POS access</small></article><article><span>Owners</span><strong>' + DB.users.filter(u => u.role === 'admin').length + '</strong><small>Station management access</small></article>';
  body.innerHTML = filterTableRows(DB.users, 'usersBody').map(u => {
    const active = u.active !== false;
    const canManage = u.id !== undefined && u.id !== null;
    return '<tr><td>' + escUser(u.u) + '</td><td>' + escUser(u.name) + '</td>' +
      '<td>' + escUser(u.job_title || '-') + '</td>' +
      '<td><span class="pill ' + (u.role === 'admin' ? 'pill-ok' : 'pill-info') + '">' + escUser(u.role) + '</span></td>' +
      '<td>' + escUser(u.access || (u.role === 'admin' ? 'Full + Analytics' : 'POS only')) + '</td>' +
      '<td><span class="pill ' + (active ? 'pill-ok' : 'pill-bad') + '">' + (active ? 'Active' : 'Inactive') + '</span></td>' +
      '<td style="white-space:nowrap;">' + (canManage
        ? '<button onclick="openUserEditor(' + u.id + ')" class="btn btn-ghost btn-sm">Edit</button> ' +
          '<button onclick="toggleUserActive(' + u.id + ')" class="btn btn-ghost btn-sm">' + (active ? 'Deactivate' : 'Activate') + '</button> ' +
          '<button onclick="deleteUser(' + u.id + ')" class="btn btn-ghost btn-sm">Delete</button>'
        : '<span class="cell-sub">offline</span>') + '</td></tr>';
  }).join('');
  showTableEmpty(body, 7);
}

let userCreatePending = false;
let userSavePending = false;

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
    password: document.getElementById('usrPassword') ? document.getElementById('usrPassword').value : '',
    role: role,
    email: document.getElementById('usrEmail')?.value.trim() || null,
    job_title: document.getElementById('usrJob')?.value.trim() || null
  };
  if (!validNewPassword(payload.password)) {
    showError('Use 8–64 characters with uppercase, lowercase and a number, without spaces.');
    return false;
  }
  if (role === 'admin' && !payload.email) { showError('Owner verification email is required.'); return false; }
  if (!payload.name || !payload.username || !payload.password) {
    showError('Name, username and password are required.');
    return false;
  }
  const button = document.getElementById('userCreateButton');
  userCreatePending = true;
  if (button) button.disabled = true;
  try {
    const result = await API.createUser(payload);
    API.replaceUser(result.user);
    document.getElementById('userForm').reset();
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
    '<label for="usrEditEmail">Email (optional)</label><input id="usrEditEmail" type="email" class="form-input" maxlength="255" value="' + escUser(u.email || '') + '">' +
    '<p class="cell-sub">Editing <b>' + escUser(u.u) + '</b></p>' +
    '<label class="cell-sub">Full name</label><input id="usrEditName" class="form-input" value="' + escUser(u.name) + '" maxlength="100">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Job title</label><input id="usrEditJob" class="form-input" value="' + escUser(u.job_title || '') + '" maxlength="120" placeholder="e.g. Cashier, Delivery rider">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Role</label><select id="usrEditRole" class="form-input">' +
    '<option value="cashier"' + (u.role === 'cashier' ? ' selected' : '') + '>Cashier (POS only)</option>' +
    '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Owner (Full + Analytics)</option></select>' +
    '<div style="height:.5rem"></div><label class="cell-sub">New password (blank = keep)</label><input id="usrEditPassword" type="password" class="form-input" minlength="8" maxlength="64" autocomplete="new-password">' +
    '<div class="drawer-actions"><button id="usrEditPasswordToggle" type="button" class="btn btn-ghost btn-sm" onclick="togglePassword(\'usrEditPassword\', this)" aria-pressed="false">Show password</button><button type="button" class="btn btn-secondary btn-sm" onclick="generateAccountPassword(\'usrEditPassword\')">Generate password</button></div><p class="credential-hint">8–64 characters, uppercase, lowercase and a number. Leave blank to keep the current password.</p>' +
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
    email: document.getElementById('usrEditEmail')?.value.trim() || null,
    job_title: document.getElementById('usrEditJob')?.value.trim() || null
  };
  const pw = document.getElementById('usrEditPassword').value;
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
