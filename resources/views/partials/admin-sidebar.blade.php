@php($tabs = [
    'dashboard' => 'Dashboard',
    'sales' => 'Sales & POS Logs',
    'arima' => 'ARIMA Analytics',
    'inventory' => 'Consumables & ROP',
    'customers' => 'Customer Liabilities',
    'suppliers' => 'Suppliers',
    'users' => 'Users & Access',
    'settings' => 'Settings',
])
@php($active = request()->route('tab') ?? 'dashboard')

<aside class="admin-sidebar no-print" id="adminSidebar">
  <div class="sidebar-header">
    <div class="sidebar-header-brand">
      <div class="sidebar-brand-badge">AF</div>
      <div>
        <h2 id="brandName" class="sidebar-brand-title">{{ $stationName ?? 'AquaFlow' }}</h2>
        <p class="sidebar-brand-subtitle">Station Owner Portal</p>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close Sidebar">&times;</button>
  </div>

  <nav class="sidebar-nav" id="sideNav">
    @foreach ($tabs as $slug => $label)
      <a href="{{ url('/admin/' . $slug) }}" data-s="{{ $slug }}"
         class="sidebar-link {{ $active === $slug ? 'active' : '' }}">
        <span>{{ $label }}</span>
        @if ($slug === 'arima')
          <span class="pill pill-info">ARIMA</span>
        @endif
      </a>
    @endforeach
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <span class="sidebar-user-label">Active Session</span>
      <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Owner' }}</b>
    </div>
    <div class="sidebar-action-row">
      <a href="{{ url('/cashier') }}" class="sidebar-pos-link">POS Terminal</a>
      <button onclick="logout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
