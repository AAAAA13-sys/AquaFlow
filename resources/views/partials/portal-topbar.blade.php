      <header class="admin-topbar no-print">
        <div class="admin-topbar-left">
          <button id="sidebarToggleBtn" class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Navigation Menu">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
          </button>
          <h1 class="admin-topbar-title" id="pageTitle">@yield('topbar-title', $defaultTitle)</h1>
        </div>
        <div class="admin-topbar-meta">
          @if(request()->routeIs('cashier.index'))
            <button type="button" id="posTourBtn" class="topbar-tour-btn" onclick="startPosTour(true)" title="Start interactive guide" style="background:rgba(255,255,255,0.14);border:1px solid rgba(255,255,255,0.28);color:#FFFFFF;border-radius:8px;font-size:12px;font-weight:600;padding:6px 10px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:background 0.15s;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span>Tour</span>
            </button>
          @endif
          <span id="{{ $clockId }}" @if($clockClass) class="{{ $clockClass }}" @endif></span>
        </div>
      </header>
