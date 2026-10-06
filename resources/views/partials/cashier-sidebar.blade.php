@php($items = [
    ['url' => '/cashier', 'icon' => 'i-terminal', 'label' => 'POS Terminal', 'match' => 'cashier.index'],
    ['url' => '/cashier/queue', 'icon' => 'i-orders', 'label' => 'Orders in Progress', 'match' => 'cashier.queue'],
    ['url' => '/cashier/history', 'icon' => 'i-receipt', 'label' => 'Sales & Transactions', 'match' => 'cashier.history'],
])

<aside class="admin-sidebar no-print" id="adminSidebar">
  @include('partials.sidebar-header', ['subtitle' => 'Cashier Terminal'])

  <nav class="sidebar-nav" id="sideNav">
    @foreach ($items as $item)
      <a href="{{ url($item['url']) }}"
         class="sidebar-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
        <svg class="icon" aria-hidden="true"><use href="{{ asset('icons/sprite.svg') }}#{{ $item['icon'] }}"></use></svg>
        <span>{{ $item['label'] }}</span>
      </a>
    @endforeach
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <span class="sidebar-user-label">Active Session</span>
      <b class="sidebar-user-name" id="who">{{ auth()->user()?->name ?? 'Cashier' }}</b>
    </div>
    <div class="sidebar-action-row">
      <button onclick="logout()" class="sidebar-logout-btn">Logout</button>
    </div>
  </div>
</aside>
