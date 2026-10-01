@extends('layouts.base')

@section('content')
  <div class="admin-layout">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

    @include('partials.cashier-sidebar')

    <div class="admin-main-container">
      <header class="admin-topbar no-print">
        <div class="admin-topbar-left">
          <button id="sidebarToggleBtn" class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Navigation Menu">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
          </button>
          <h1 class="admin-topbar-title" id="pageTitle">@yield('topbar-title', 'POS Terminal')</h1>
        </div>
        <div class="admin-topbar-meta">
          <span id="clock" class="header-clock"></span>
        </div>
      </header>

      @yield('cashier-body')
    </div>
  </div>

  @include('partials.scripts-base')
  @include('partials.scripts-pos')
@endsection
