@php
  $role = auth()->user()?->role;
  $roleLabel = $role === 'rider' ? 'Delivery Rider' : ($role === 'refiller' ? 'Refiller' : ucfirst($role ?? 'Employee'));
  $queueLabel = $role === 'rider' ? 'Delivery Queue' : 'Refill Queue';
@endphp

<aside class="admin-sidebar no-print" id="adminSidebar">
  <div class="sidebar-header">
    <div class="sidebar-header-brand">
      <div class="sidebar-brand-badge">AF</div>
      <div>
        <h2 id="brandName" class="sidebar-brand-title">{{ $stationName ?? 'AquaFlow' }}</h2>
        <p class="sidebar-brand-subtitle">{{ $roleLabel }}</p>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close Sidebar">&times;</button>
  </div>

  <nav class="sidebar-nav" id="sideNav" aria-label="Employee workspace">
    <p class="sidebar-section-title">My work</p>
    <a href="{{ url('/employee') }}"
       class="sidebar-link {{ request()->routeIs('employee.home') ? 'active' : '' }}"
       @if(request()->routeIs('employee.home')) aria-current="page" @endif>
      <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-orders"></use></svg>
      <span>{{ $queueLabel }}</span>
    </a>
    <a href="{{ url('/employee/history') }}"
       class="sidebar-link {{ request()->routeIs('employee.history') ? 'active' : '' }}"
       @if(request()->routeIs('employee.history')) aria-current="page" @endif>
      <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#i-receipt"></use></svg>
      <span>My activity</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <div class="sidebar-user-meta">
        <span class="sidebar-user-label">Active session</span>
        <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Employee' }}</b>
        <span class="sidebar-role-chip">{{ auth()->user()?->roleLabel() ?? 'Employee' }}</span>
      </div>
    </div>

    <div class="sidebar-action-row">
      @if (auth()->user()?->isAdmin())
        <a href="{{ url('/admin/dashboard') }}" class="sidebar-back-link">
          <span class="sidebar-back-arrow" aria-hidden="true">&larr;</span>
          <span>{{ auth()->user()?->isSuperAdmin() ? 'Owner Portal' : 'Manager Portal' }}</span>
        </a>
      @endif
      <button type="button" onclick="confirmEmployeeLogout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
