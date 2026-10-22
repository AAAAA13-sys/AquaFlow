{{-- "My activity" page body.

     Shared by the cashier and the employee (rider/refiller) screens: the same
     heading, summary line, list and error banner. The sidebar comes from the
     caller's own partial, so each role keeps its own navigation. --}}
<div class="activity-page" id="activityPage">
  <header class="activity-header">
    <div>
      <p class="activity-eyebrow">TODAY</p>
      <h1>What I did today</h1>
      <p id="activitySummary">{{ $activitySummary ?? 'Loading your activity…' }}</p>
    </div>
    <div class="activity-heading-actions">
      <button type="button" class="activity-refresh-btn" onclick="refreshActivity()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Refresh
      </button>
    </div>
  </header>

  <div class="activity-status-bar">
    <div class="activity-pulse-wrapper">
      <span id="pulseDot" class="activity-pulse-dot"></span>
      <span id="activityUpdated" role="status">Updating…</span>
    </div>
    <span id="activityCount" class="activity-count-pill">0</span>
  </div>

  <div id="activityError" class="activity-alert hidden" role="alert"></div>

  <section aria-labelledby="activityListTitle">
    <h2 id="activityListTitle" class="activity-section-title">Today's activity</h2>
    <div id="activityList">
      <div class="activity-empty">Loading your activity…</div>
    </div>
  </section>
</div>
