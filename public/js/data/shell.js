// AquaFlow - shared application shell behaviour.
//
// Loaded through partials/scripts-base, which BOTH the cashier terminal and the
// owner portal include.
//
// `toggleSidebar` used to live in js/admin/navigation.js. The cashier layout only
// includes scripts-base + scripts-pos, so on the POS the function did not exist
// at all and tapping the hamburger silently did nothing. Shell behaviour now has
// one home that every authenticated page loads.

/**
 * Opens or closes the responsive sidebar.
 *
 * @param {boolean} [force] Explicit state. Omit to toggle.
 */
function toggleSidebar(force) {
  const sidebar = document.getElementById('adminSidebar');
  if (!sidebar) return;

  const backdrop = document.getElementById('sidebarBackdrop');
  const open = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');

  sidebar.classList.toggle('open', open);
  if (backdrop) backdrop.classList.toggle('open', open);
  document.body.classList.toggle('sidebar-open', open);
}

// Clean typed and pasted text before each screen's search handler reads it.
document.addEventListener('input', event => {
  const field = event.target;
  if (!field.matches?.('#custSearch, #invBodySearch, #invBodyDashSearch, #supGridSearch, #defectBodySearch, #custBodySearch, #cashOverviewSearch, #setStation, #setupStation') || event.isComposing) return;
  const clean = text => text.replace(/[^\p{L}\p{M}\p{N} .,'’&-]/gu, '');
  const value = clean(field.value);
  if (value === field.value) return;
  const caret = clean(field.value.slice(0, field.selectionStart)).length;
  field.value = value;
  field.setSelectionRange(caret, caret);
}, true);

// Choosing a destination closes the drawer, so it cannot cover the next page.
document.addEventListener('click', (event) => {
  const link = event.target.closest && event.target.closest('#adminSidebar a');
  if (link) toggleSidebar(false);
});

// Escape closes it too.
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') toggleSidebar(false);
});

// Reveal back-to-admin links in cashier and employee workspaces for owner/manager sessions
document.addEventListener('DOMContentLoaded', () => {
  try {
    const s = typeof getSession === 'function' ? getSession() : null;
    if (s && (s.role === 'super_admin' || s.role === 'admin')) {
      const topBack = document.getElementById('topbarBackToAdmin');
      if (topBack) topBack.classList.remove('hidden');
      document.querySelectorAll('.back-to-admin').forEach(el => el.classList.remove('hidden'));
    }
  } catch (e) {}
});

// Keep each existing button/handler; available for explicit enhancement.
function enhanceCrudActions(root = document) {
  const icons = {History:'i-history', Edit:'i-edit', Delete:'i-trash'};
  const buttons = [...(root && root.querySelectorAll ? root.querySelectorAll('table button, .table-actions button, .drawer-actions button') : [])];
  if (root && root.matches && root.matches('table button, .table-actions button, .drawer-actions button')) buttons.unshift(root);
  for (const button of buttons) {
    if (button.dataset.actionIcon) continue;
    const label = button.textContent.trim();
    if (!icons[label]) continue;
    button.dataset.actionIcon = icons[label];
    button.dataset.actionTip = '1';
    button.dataset.tip = label;
    button.classList.add('table-icon-action');
    button.setAttribute('aria-label', label);
    button.title = label;
    if (typeof icon === 'function') {
      button.innerHTML = icon(icons[label]);
    }
  }
}
