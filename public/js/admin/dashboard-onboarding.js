// AquaFlow — Owner Dashboard Getting Started Onboarding Checklist
(function () {
  function initOnboardingChecklist() {
    const card = document.getElementById('onboardingChecklist');
    if (!card) return;

    if (localStorage.getItem('af_onboarding_dismissed') === 'true') {
      card.classList.add('hidden');
      return;
    }

    updateChecklistProgress();
  }

  async function updateChecklistProgress() {
    let completed = 0;
    const total = 5;

    // Every flag below is derived from the bootstrap snapshot the page already
    // loaded. This used to call /api/overview, which does not exist, so the
    // request 404'd and the checklist stayed at "0 of 5" for good.
    const snapshot = (typeof DB !== 'undefined' && DB) ? DB : null;
    if (!snapshot) return;

    // 1. Stock & Supplies - catalogue and consumables are stocked.
    if ((snapshot.inventory || []).length > 0 || (snapshot.products || []).length > 0) {
      markStepDone('stepInventory');
      completed++;
    }

    // 2. Suppliers - at least one purchasing contact on file.
    if ((snapshot.suppliers || []).length > 0) {
      markStepDone('stepSuppliers');
      completed++;
    }

    // 3. Staff & Access - more than one account exists, not just the owner.
    if ((snapshot.users || []).filter(u => u.active !== false).length > 1) {
      markStepDone('stepStaff');
      completed++;
    }

    // 4. Station Settings - always reachable, so it is never a blocker.
    markStepDone('stepSettings');
    completed++;

    // 5. POS Terminal - a recorded sale proves the till works end to end.
    if ((snapshot.meta?.sales_today_count ?? 0) > 0 || (snapshot.transactions || []).length > 0) {
      markStepDone('stepPos');
      completed++;
    }

    const badge = document.getElementById('onboardingProgressBadge');
    if (badge) {
      badge.textContent = `${completed} of ${total} Steps Done`;
      if (completed === total) {
        badge.className = 'pill pill-ok';
      }
    }
  }

  function markStepDone(stepId) {
    const el = document.getElementById(stepId);
    if (!el) return;
    el.classList.add('is-done');
    const icon = el.querySelector('.onboarding-step-icon');
    if (icon) {
      icon.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    }
  }

  function toggleOnboardingChecklist() {
    const card = document.getElementById('onboardingChecklist');
    if (!card) return;
    card.classList.add('hidden');
    localStorage.setItem('af_onboarding_dismissed', 'true');
  }

  window.toggleOnboardingChecklist = toggleOnboardingChecklist;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOnboardingChecklist);
  } else {
    setTimeout(initOnboardingChecklist, 200);
  }
})();
