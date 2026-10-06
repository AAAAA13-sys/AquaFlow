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
          <span id="{{ $clockId }}" @if($clockClass) class="{{ $clockClass }}" @endif></span>
        </div>
      </header>
