@extends('layouts.base')

@section('body-class', '')

@section('content')
  <div class="admin-layout">
    <div id="sidebarBackdrop" class="sidebar-backdrop" onclick="toggleSidebar(false)"></div>

    @include('partials.admin-sidebar')

    <div class="admin-main-container">
      <header class="admin-topbar no-print">
        <div class="admin-topbar-left">
          <button id="sidebarToggleBtn" class="sidebar-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle Navigation Menu">
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
            <span class="hamburger-bar"></span>
          </button>
          <h1 class="admin-topbar-title" id="pageTitle">@yield('topbar-title', 'Station Dashboard')</h1>
        </div>
        <div class="admin-topbar-meta">
          <span id="adminClock"></span>
        </div>
      </header>

      <main class="admin-main-content">
        @yield('admin-content')
      </main>
    </div>
  </div>

  @include('partials.scripts-base')
  @include('partials.scripts-admin')
@endsection
