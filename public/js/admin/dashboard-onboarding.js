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

    // Check localStorage or snapshot indicators
    try {
      if (typeof API !== 'undefined' && typeof API.get === 'function') {
        const overview = await API.get('overview');
        if (overview) {
          // 1. Inventory items check
          if (overview.stock_items_count > 0 || overview.low_stock !== undefined) {
            markStepDone('stepInventory');
            completed++;
          }
          // 2. Suppliers check
          if (overview.suppliers_count > 0) {
            markStepDone('stepSuppliers');
            completed++;
          }
          // 3. Staff check
          if (overview.staff_count > 1) {
            markStepDone('stepStaff');
            completed++;
          }
          // 4. Settings check (always configurable)
          markStepDone('stepSettings');
          completed++;
          // 5. POS Sales check
          if (overview.sales_today > 0 || (overview.recent_transactions && overview.recent_transactions.length > 0)) {
            markStepDone('stepPos');
            completed++;
          }
        }
      }
    } catch (_) {
      // Fallback
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
