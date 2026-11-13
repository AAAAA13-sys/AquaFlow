{{-- The top bar is the page header: "Group › Page" with the page title as the
     h1, and the page's own actions on the right (@section('page-actions')).
     The group comes from @section('topbar-group'); admin pages get theirs from
     partials/admin-sidebar, which renders before this partial. Both values are
     escaped when the section is defined, so they are echoed as-is here, the same
     way @yield echoes them. The clock lives in each sidebar footer. --}}
@php($topbarGroup = trim($__env->yieldContent('topbar-group', $defaultGroup ?? '')))
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
          <div class="topbar-heading">
            @if($topbarGroup !== '')
              <span class="topbar-crumb">{!! $topbarGroup !!}</span>
              <svg class="topbar-crumb-sep" width="14" height="14" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-chevron-right"></use></svg>
            @endif
            <h1 class="admin-topbar-title" id="pageTitle">@yield('topbar-title', $defaultTitle)</h1>
          </div>
        </div>
        @hasSection('page-actions')
          <div class="admin-topbar-actions">
            @yield('page-actions')
          </div>
        @endif
      </header>
