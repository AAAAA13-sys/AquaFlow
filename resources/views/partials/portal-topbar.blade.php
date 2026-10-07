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
          {{-- Filled by renderStamp() from the snapshot's own generated_at, so
               a cached/offline view is distinguishable from live data. --}}
          <span id="dataStamp" class="af-stamp" role="status" aria-live="polite"></span>
          <span id="{{ $clockId }}" @if($clockClass) class="{{ $clockClass }}" @endif></span>
        </div>
      </header>
