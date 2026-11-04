      <header class="admin-topbar no-print">
        <div class="admin-topbar-left">
          <button id="sidebarToggleBtn" class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Navigation Menu">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
          </button>
          @if(auth()->user()?->isAdmin())
            <a href="{{ url('/admin/dashboard') }}" id="topbarBackToAdmin" class="topbar-back-link" aria-label="Back to Admin Dashboard" title="Return to {{ auth()->user()->isSuperAdmin() ? 'Owner' : 'Manager' }} Dashboard">
              <span aria-hidden="true">&larr;</span>
              <span>Dashboard</span>
            </a>
          @endif
          <h1 class="admin-topbar-title" id="pageTitle">@yield('topbar-title', $defaultTitle)</h1>
        </div>
        <div class="admin-topbar-meta">
          <span id="{{ $clockId }}" @if($clockClass) class="{{ $clockClass }}" @endif></span>
        </div>
      </header>
