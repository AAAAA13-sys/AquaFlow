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


// Users List Rendering
function renderUsers() {
  const body = document.getElementById('usersBody');
  if (!body) return;
  body.innerHTML = DB.users.map(u =>
    '<tr><td>' + u.u + '</td><td>' + u.name + '</td><td><span class="pill ' + (u.role === 'admin' ? 'pill-ok' : 'pill-info') + '">' + u.role + '</span></td>' +
    '<td>' + (u.role === 'admin' ? 'Full + Analytics' : 'POS only') + '</td><td>Yes</td></tr>'
  ).join('');
}
