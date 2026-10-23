@php($items = [
    ['url' => '/cashier', 'icon' => 'i-terminal', 'label' => 'POS Terminal', 'match' => 'cashier.index'],
    ['url' => '/cashier/cash', 'icon' => 'i-vault', 'label' => 'Cash Drawer', 'match' => 'cashier.cash', 'badge_id' => 'sidebarShiftBadge'],
    ['url' => '/cashier/queue', 'icon' => 'i-orders', 'label' => 'Orders in Progress', 'match' => 'cashier.queue'],
    ['url' => '/cashier/activity', 'icon' => 'i-check-circle', 'label' => 'My Activity', 'match' => 'cashier.activity'],
    ['url' => '/cashier/history', 'icon' => 'i-receipt', 'label' => 'Sales & Receipts', 'match' => 'cashier.history'],
    ['url' => '/cashier/stocks', 'icon' => 'i-package', 'label' => 'Stock Availability', 'match' => 'cashier.stocks'],
])

<aside class="admin-sidebar no-print" id="adminSidebar">
  <div class="sidebar-header">
    <div class="sidebar-header-brand">
      <div class="sidebar-brand-badge">AF</div>
      <div>
        <h2 id="brandName" class="sidebar-brand-title">{{ $stationName ?? 'AquaFlow' }}</h2>
        <p class="sidebar-brand-subtitle">Cashier Terminal</p>
      </div>
    </div>
    <button class="sidebar-close-btn" onclick="toggleSidebar(false)" aria-label="Close Sidebar">&times;</button>
  </div>

  <nav class="sidebar-nav" id="sideNav" aria-label="Cashier page catalog">
    <p class="sidebar-section-title">Daily operations</p>
    @foreach ($items as $item)
      <a href="{{ url($item['url']) }}"
         class="sidebar-link {{ request()->routeIs($item['match']) ? 'active' : '' }}" @if(request()->routeIs($item['match'])) aria-current="page" @endif>
        <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#{{ $item['icon'] }}"></use></svg>
        <span>{{ $item['label'] }}</span>
        @if(!empty($item['badge_id']))
          <span id="{{ $item['badge_id'] }}" class="sidebar-badge sidebar-badge-drawer" style="display:none"></span>
        @endif
      </a>
    @endforeach
  </nav>


  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <div class="sidebar-user-meta">
        <span class="sidebar-user-label">Active Session</span>
        <div class="sidebar-user-info">
          <b class="sidebar-user-name" id="who" title="{{ auth()->user()?->name ?? 'Cashier' }}">{{ auth()->user()?->name ?? 'Cashier' }}</b>
          <span class="sidebar-role-chip">{{ auth()->user()?->roleLabel() ?? 'Cashier' }}</span>
        </div>
      </div>
    </div>

    <div class="sidebar-action-row">
      <button onclick="confirmCashierLogout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
