@push('scripts')
  <script>
    (async () => {
      window.SESSION = await @if ($portal === 'admin') bootAdminPortal(@json($pageTitle)) @else bootCashierPortal() @endif;
      if (SESSION) {
        {{ $slot }}
      }
    })();
  </script>
@endpush
