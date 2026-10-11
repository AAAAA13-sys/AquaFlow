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

// Choosing a destination closes the drawer, so it cannot cover the next page.
document.addEventListener('click', (event) => {
  const link = event.target.closest && event.target.closest('#adminSidebar a');
  if (link) toggleSidebar(false);
});

// Escape closes it too.
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') toggleSidebar(false);
});
