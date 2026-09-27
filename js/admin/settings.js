// AquaFlow ADMIN portal - Users & Access + Settings
// Logic copied from the original AquaFlow prototype, re-rendered
// with the current semantic design-system classes.

function loadSettings() {
  let s = {};
  try { s = JSON.parse(localStorage.getItem('aquaflow_settings') || '{}'); } catch (e) { s = {}; }
  const st = document.getElementById('setStation'), ld = document.getElementById('setLead'), su = document.getElementById('setSus');
  if (s.station && st) st.value = s.station;
  if (s.lead && ld) ld.value = s.lead;
  if (s.sus && su) su.value = s.sus;
  if (s.station) {
    const brand = document.getElementById('brandName');
    if (brand) brand.textContent = s.station;
    document.title = s.station + ' - Owner Portal';
  }
}

function saveSettings() {
  const stationEl = document.getElementById('setStation');
  const leadEl = document.getElementById('setLead');
  const susEl = document.getElementById('setSus');
  const s = {
    station: (stationEl ? stationEl.value : '').trim() || 'AquaFlow Station',
    lead: +((leadEl && leadEl.value) || 3),
    sus: (susEl ? susEl.value : '').trim()
  };
  try { localStorage.setItem('aquaflow_settings', JSON.stringify(s)); } catch (e) {}
  const brand = document.getElementById('brandName');
  if (brand) brand.textContent = s.station;
  document.title = s.station + ' - Owner Portal';
  alert('Settings saved.');
}

function renderUsers() {
  const body = document.getElementById('usersBody');
  if (!body) return;
  body.innerHTML = DB.users.map(u =>
    '<tr><td>' + u.u + '</td><td>' + u.name + '</td><td><span class="pill ' + (u.role === 'admin' ? 'pill-ok' : 'pill-info') + '">' + u.role + '</span></td>' +
    '<td>' + (u.role === 'admin' ? 'Full + Analytics' : 'POS only') + '</td><td>Yes</td></tr>'
  ).join('');
}
