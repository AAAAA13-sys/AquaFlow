// Route & Section Mappings
const SECTION_URLS = {
  dash: '/admin/dashboard',
  sales: '/admin/sales',
  arima: '/admin/arima',
  inv: '/admin/inventory',
  cust: '/admin/customers',
  sup: '/admin/suppliers',
  users: '/admin/users',
  settings: '/admin/settings'
};

function sectionUrl(s) {
  return SECTION_URLS[s] || '/admin/dashboard';
}

function showSection(s) {
  location.href = sectionUrl(s);
}


// Responsive Sidebar Toggle
function toggleSidebar(force) {
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
  sidebar.classList.toggle('open', open);
  if (backdrop) backdrop.classList.toggle('open', open);
}


// Admin Portal Bootstrapping
async function bootAdminPortal(pageTitle) {
  const session = guardAdmin();
  if (!session) return null;

  const who = document.getElementById('who');
  if (who) who.textContent = session.name;

  const title = document.getElementById('pageTitle');
  if (title && pageTitle) title.textContent = pageTitle;

  const clock = document.getElementById('adminClock');
  const tick = () => { if (clock) clock.textContent = new Date().toLocaleString(); };
  tick();
  setInterval(tick, 1000);

  try {
    await loadFromServer();
  } catch (error) {
    console.warn('AquaFlow: API unavailable, using cached data. ' + error.message);
  }

  return session;
}
