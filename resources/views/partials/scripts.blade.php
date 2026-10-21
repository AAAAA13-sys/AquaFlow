{{-- Base Data & Auth Scripts --}}
<script src="{{ asset('js/data/initial-data.js') }}?v={{ asset_version('js/data/initial-data.js') }}"></script>
<script src="{{ asset('js/data/api.js') }}?v={{ asset_version('js/data/api.js') }}"></script>
<script src="{{ asset('js/data/store.js') }}?v={{ asset_version('js/data/store.js') }}"></script>
<script src="{{ asset('js/data/shell.js') }}?v={{ asset_version('js/data/shell.js') }}"></script>
<script src="{{ asset('js/auth/auth.js') }}?v={{ asset_version('js/auth/auth.js') }}"></script>
<script src="{{ asset('js/data/submissions.js') }}?v={{ asset_version('js/data/submissions.js') }}"></script>
<script src="{{ asset('js/data/dialog.js') }}?v={{ asset_version('js/data/dialog.js') }}"></script>
<script src="{{ asset('js/data/profile.js') }}?v={{ asset_version('js/data/profile.js') }}"></script>

@if(($panel ?? '') === 'admin')
{{-- Admin Portal Scripts --}}
<script src="{{ asset('js/admin/navigation.js') }}?v={{ asset_version('js/admin/navigation.js') }}"></script>
<script src="{{ asset('js/admin/inventory.js') }}?v={{ asset_version('js/admin/inventory.js') }}"></script>
<script src="{{ asset('js/admin/arima.js') }}?v={{ asset_version('js/admin/arima.js') }}"></script>
<script src="{{ asset('js/admin/insights.js') }}?v={{ asset_version('js/admin/insights.js') }}"></script>
<script src="{{ asset('js/admin/customers.js') }}?v={{ asset_version('js/admin/customers.js') }}"></script>
<script src="{{ asset('js/admin/approvals.js') }}?v={{ asset_version('js/admin/approvals.js') }}"></script>
<script src="{{ asset('js/admin/audit-logs.js') }}?v={{ asset_version('js/admin/audit-logs.js') }}"></script>
<script src="{{ asset('js/admin/sales.js') }}?v={{ asset_version('js/admin/sales.js') }}"></script>
<script src="{{ asset('js/admin/settings.js') }}?v={{ asset_version('js/admin/settings.js') }}"></script>
<script src="{{ asset('js/admin/stock.js') }}?v={{ asset_version('js/admin/stock.js') }}"></script>
<script src="{{ asset('js/admin/till.js') }}?v={{ asset_version('js/admin/till.js') }}"></script>
<script src="{{ asset('js/admin/defects.js') }}?v={{ asset_version('js/admin/defects.js') }}"></script>
@elseif(($panel ?? '') === 'cashier')
{{-- Cashier POS Scripts --}}
<script src="{{ asset('js/pos/cart.js') }}?v={{ asset_version('js/pos/cart.js') }}"></script>
<script src="{{ asset('js/pos/checkout.js') }}?v={{ asset_version('js/pos/checkout.js') }}"></script>
<script src="{{ asset('js/pos/panels.js') }}?v={{ asset_version('js/pos/panels.js') }}"></script>
@endif
