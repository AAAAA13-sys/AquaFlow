// System Settings Load & Save
function loadSettings() {
  const server = (typeof DB !== 'undefined' && DB.settings) ? DB.settings : {};
  let local = {};
  try { local = JSON.parse(localStorage.getItem('aquaflow_settings') || '{}'); } catch (e) { local = {}; }

  const station = server.station_name || local.station || 'AquaFlow Station';
  const lead = server.restock_lead_days || local.lead || 3;
  const sus = server.sus_target || local.sus || '';

  const st = document.getElementById('setStation'), ld = document.getElementById('setLead'), su = document.getElementById('setSus');
  if (st) st.value = station;
  if (ld) ld.value = lead;
  if (su) su.value = sus;

  const brand = document.getElementById('brandName');
  if (brand) brand.textContent = station;
  document.title = station + ' - Owner Portal';
}

async function saveSettings() {
  const stationEl = document.getElementById('setStation');
  const leadEl = document.getElementById('setLead');
  const susEl = document.getElementById('setSus');

  const payload = {
    station_name: (stationEl ? stationEl.value : '').trim() || 'AquaFlow Station',
    restock_lead_days: +((leadEl && leadEl.value) || 3),
    sus_target: (susEl ? susEl.value : '').trim()
  };

  try {
    const result = await API.updateSettings(payload);
    DB.settings = result.settings;
    try {
      localStorage.setItem('aquaflow_settings', JSON.stringify({
        station: payload.station_name,
        lead: payload.restock_lead_days,
        sus: payload.sus_target
      }));
    } catch (e) {}

    const brand = document.getElementById('brandName');
    if (brand) brand.textContent = payload.station_name;
    document.title = payload.station_name + ' - Owner Portal';
    alert('Settings saved.');
  } catch (error) {
    alert(error.message || 'Could not save the settings.');
  }
}


// Users List Rendering (CRUD + access control)
function escUser(value) {
  if (typeof esc === 'function') return esc(value);
  return String(value === undefined || value === null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function findUser(id) {
  return (DB.users || []).find(u => String(u.id) === String(id));
}

function renderUsers() {
  const body = document.getElementById('usersBody');
  if (!body) return;
  body.innerHTML = DB.users.map(u => {
    const active = u.active !== false;
    const canManage = u.id !== undefined && u.id !== null;
    return '<tr><td>' + escUser(u.u) + '</td><td>' + escUser(u.name) + '</td>' +
      '<td><span class="pill ' + (u.role === 'admin' ? 'pill-ok' : 'pill-info') + '">' + escUser(u.role) + '</span></td>' +
      '<td>' + escUser(u.access || (u.role === 'admin' ? 'Full + Analytics' : 'POS only')) + '</td>' +
      '<td><span class="pill ' + (active ? 'pill-ok' : 'pill-bad') + '">' + (active ? 'Yes' : 'No') + '</span></td>' +
      '<td style="white-space:nowrap;">' + (canManage
        ? '<button onclick="openUserEditor(' + u.id + ')" class="btn btn-ghost btn-sm">Edit</button> ' +
          '<button onclick="toggleUserActive(' + u.id + ')" class="btn btn-ghost btn-sm">' + (active ? 'Deactivate' : 'Activate') + '</button> ' +
          '<button onclick="deleteUser(' + u.id + ')" class="btn btn-ghost btn-sm">Delete</button>'
        : '<span class="cell-sub">offline</span>') + '</td></tr>';
  }).join('');
}

async function createUser(event) {
  if (event) event.preventDefault();
  const role = document.getElementById('usrRole') ? document.getElementById('usrRole').value : 'cashier';
  const pinEl = document.getElementById('usrPin');
  const payload = {
    name: document.getElementById('usrName') ? document.getElementById('usrName').value.trim() : '',
    username: document.getElementById('usrUsername') ? document.getElementById('usrUsername').value.trim() : '',
    password: document.getElementById('usrPassword') ? document.getElementById('usrPassword').value : '',
    role: role,
    pin: (role === 'admin' && pinEl) ? pinEl.value : null
  };
  if (!payload.name || !payload.username || !payload.password) {
    alert('Name, username and password are required.');
    return false;
  }
  if (role === 'admin' && !payload.pin) {
    alert('An Owner PIN is required for admin accounts.');
    return false;
  }
  try {
    const result = await API.createUser(payload);
    API.replaceUser(result.user);
    document.getElementById('userForm').reset();
    if (pinEl) pinEl.style.display = 'none';
    renderUsers();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not add the user.');
  }
  return false;
}

function openUserEditor(id) {
  const u = findUser(id);
  const wrap = document.getElementById('userEditWrap');
  const bodyEl = document.getElementById('userEditBody');
  if (!u || !wrap || !bodyEl) return;
  bodyEl.innerHTML =
    '<p class="cell-sub">Editing <b>' + escUser(u.u) + '</b></p>' +
    '<label class="cell-sub">Full name</label><input id="usrEditName" class="form-input" value="' + escUser(u.name) + '" maxlength="100">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Role</label><select id="usrEditRole" class="form-input">' +
    '<option value="cashier"' + (u.role === 'cashier' ? ' selected' : '') + '>Cashier (POS only)</option>' +
    '<option value="admin"' + (u.role === 'admin' ? ' selected' : '') + '>Owner (Full + Analytics)</option></select>' +
    '<div style="height:.5rem"></div><label class="cell-sub">New password (blank = keep)</label><input id="usrEditPassword" type="password" class="form-input" minlength="4">' +
    '<div style="height:.5rem"></div><label class="cell-sub">Owner PIN (admins only, blank = keep/remove)</label><input id="usrEditPin" type="password" class="form-input" minlength="4" maxlength="12">' +
    '<div class="drawer-actions" style="margin-top:.75rem;"><button onclick="saveUserEdit(' + u.id + ')" class="btn btn-primary btn-sm">Save</button>' +
    ' <button onclick="closeUserEditor()" class="btn btn-ghost btn-sm">Cancel</button></div>';
  wrap.classList.remove('hidden');
}

function closeUserEditor() {
  const wrap = document.getElementById('userEditWrap');
  if (wrap) wrap.classList.add('hidden');
}

async function saveUserEdit(id) {
  const payload = {
    name: document.getElementById('usrEditName').value.trim(),
    role: document.getElementById('usrEditRole').value
  };
  const pw = document.getElementById('usrEditPassword').value;
  const pin = document.getElementById('usrEditPin').value;
  if (pw) payload.password = pw;
  if (pin) payload.pin = pin;
  try {
    const result = await API.updateUser(id, payload);
    API.replaceUser(result.user);
    closeUserEditor();
    renderUsers();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not save the user.');
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
    alert(error.message || 'Could not update access.');
  }
}

async function deleteUser(id) {
  const u = findUser(id);
  if (!u) return;
  if (!confirm('Delete user "' + u.u + '"?')) return;
  try {
    await API.deleteUser(id);
    API.removeUser(id);
    renderUsers();
    saveDB();
  } catch (error) {
    alert(error.message || 'Could not delete the user.');
  }
}
