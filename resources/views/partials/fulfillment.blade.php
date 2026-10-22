@php($monitor = $monitor ?? false)
<section class="card" style="padding:20px;margin:20px 0"><h2>{{ $monitor ? 'Delivery monitoring' : 'Preparation, Delivery Evidence & Remittances' }}</h2>
@if($monitor)<p class="settings-help">Riders start the oldest ready delivery. The system records the rider at dispatch; no manual assignment is needed.</p>@endif
<p id="fulfillmentStatus" role="status">Loading...</p><div id="fulfillmentRows" data-mode="{{ $monitor ? 'monitor' : 'operations' }}"></div></section>
<script src="{{ asset('js/fulfillment.js') }}?v={{ asset_version('js/fulfillment.js') }}"></script>
