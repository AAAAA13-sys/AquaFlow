// AquaFlow — Cashier Interactive POS Product Tour
(function () {
  let currentTourStep = 0;
  let tourTargetEl = null;

  const tourSteps = [
    {
      target: '.stage-toolbar',
      fallbackTarget: '.stage-card',
      title: '1. Fast Walk-In & Customer Lookup',
      body: 'Start an instant cash sale with Quick Walk-In (F2). To track deliveries or customer debt accounts, search their name or click + New Customer.'
    },
    {
      target: '#posSteps',
      fallbackTarget: '.stage-card',
      title: '2. Guided 3-Stage Workflow',
      body: 'The terminal follows a clear progression: 1. Customer Selection, 2. Order Type & Items, and 3. Payment & Receipt. Each step validates automatically.'
    },
    {
      target: '.sidebar-gear-btn',
      fallbackTarget: '.sidebar-user-card',
      title: '3. Profile & Password Settings',
      body: 'Click the gear icon in the sidebar anytime to check your account details or update your password securely.'
    },
    {
      target: '.sidebar-logout-btn',
      fallbackTarget: '.sidebar-footer',
      title: '4. Shift Departure & Clock Out',
      body: 'When your shift finishes, clicking Logout automatically prompts you to Time Out & End Shift, closing your attendance log for the day.'
    }
  ];

  function getStepElement(stepIndex) {
    const step = tourSteps[stepIndex];
    if (!step) return null;
    return document.querySelector(step.target) || document.querySelector(step.fallbackTarget) || null;
  }

  function startPosTour(force = false) {
    if (!force && localStorage.getItem('af_pos_tour_seen')) return;

    // Only run on the cashier POS terminal page
    if (!window.location.pathname.endsWith('/cashier') && window.location.pathname !== '/cashier') return;

    currentTourStep = 0;
    renderTourStep();
  }

  function cleanupTour() {
    if (tourTargetEl) {
      tourTargetEl.classList.remove('af-tour-spotlight');
      tourTargetEl = null;
    }
    const card = document.getElementById('afTourCard');
    if (card) card.remove();
    const backdrop = document.getElementById('afTourBackdrop');
    if (backdrop) backdrop.remove();
  }

  function dismissTour() {
    cleanupTour();
    localStorage.setItem('af_pos_tour_seen', 'true');
  }

  function renderTourStep() {
    cleanupTour();

    const step = tourSteps[currentTourStep];
    if (!step) {
      dismissTour();
      return;
    }

    const target = getStepElement(currentTourStep);
    if (target) {
      tourTargetEl = target;
      target.classList.add('af-tour-spotlight');
      try {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      } catch (_) {}
    }

    // Backdrop
    const backdrop = document.createElement('div');
    backdrop.id = 'afTourBackdrop';
    backdrop.className = 'af-tour-backdrop';
    backdrop.onclick = dismissTour;
    document.body.appendChild(backdrop);

    // Card
    const card = document.createElement('aside');
    card.id = 'afTourCard';
    card.className = 'af-tour-card';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', step.title);

    // Position Card
    if (target) {
      const rect = target.getBoundingClientRect();
      const cardWidth = 360;
      let top = rect.bottom + 14;
      let left = Math.max(16, Math.min(rect.left, window.innerWidth - cardWidth - 16));

      if (top + 220 > window.innerHeight) {
        top = Math.max(16, rect.top - 230);
      }
      card.style.top = `${top}px`;
      card.style.left = `${left}px`;
    } else {
      card.style.top = '50%';
      card.style.left = '50%';
      card.style.transform = 'translate(-50%, -50%)';
    }

    // Dots
    const dotsHtml = tourSteps.map((_, i) => `<span class="af-tour-dot ${i === currentTourStep ? 'active' : ''}"></span>`).join('');

    card.innerHTML = `
      <div class="af-tour-header">
        <span class="af-tour-badge">Step ${currentTourStep + 1} of ${tourSteps.length}</span>
        <button type="button" class="af-tour-close-btn" id="btnTourClose" aria-label="Close tour">&times;</button>
      </div>
      <h3 class="af-tour-title">${step.title}</h3>
      <p class="af-tour-body">${step.body}</p>
      <div class="af-tour-footer">
        <div class="af-tour-dots">${dotsHtml}</div>
        <div class="af-tour-actions">
          ${currentTourStep > 0 ? `<button type="button" class="af-tour-btn af-tour-btn-ghost" id="btnTourPrev">Back</button>` : ''}
          <button type="button" class="af-tour-btn af-tour-btn-primary" id="btnTourNext">
            ${currentTourStep === tourSteps.length - 1 ? 'Got it!' : 'Next &rarr;'}
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(card);

    // Event listeners
    document.getElementById('btnTourClose')?.addEventListener('click', dismissTour);
    document.getElementById('btnTourPrev')?.addEventListener('click', () => {
      currentTourStep--;
      renderTourStep();
    });
    document.getElementById('btnTourNext')?.addEventListener('click', () => {
      currentTourStep++;
      if (currentTourStep >= tourSteps.length) {
        dismissTour();
      } else {
        renderTourStep();
      }
    });
  }

  // Escape key dismisses
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && document.getElementById('afTourCard')) {
      dismissTour();
    }
  });

  window.startPosTour = startPosTour;

  // Auto-launch for new cashiers after short delay
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(() => startPosTour(false), 900));
  } else {
    setTimeout(() => startPosTour(false), 900);
  }
})();
