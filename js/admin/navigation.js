// AquaFlow ADMIN portal - cross-page navigation + shared page chrome
// The dashboard is split into one HTML file per tab, so showSection() now
// routes to the matching tab file instead of toggling hidden sections.

const SECTION_FILES = {
  dash: 'dashboard.html',
  sales: 'sales.html',
  arima: 'arima.html',
  inv: 'inventory.html',
  cust: 'customers.html',
  sup: 'suppliers.html',
  users: 'users.html',
  settings: 'settings.html'
};

function sectionUrl(s) {
  return SECTION_FILES[s] || 'dashboard.html';
}

// Kept with the same name/signature as the original single-page dashboard so
// every button that calls showSection('arima'), showSection('inv'), etc. works.
function showSection(s) {
  location.href = sectionUrl(s);
}

// Mobile sidebar drawer (matches .admin-sidebar.open / .sidebar-backdrop.open)
function toggleSidebar(force) {
  const sidebar = document.getElementById('adminSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (!sidebar) return;
  const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
  sidebar.classList.toggle('open', open);
  if (backdrop) backdrop.classList.toggle('open', open);
}

// Shared boot: role guard, active session name, live clock and page title.
function bootAdminPortal(pageTitle) {
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

  return session;
}
