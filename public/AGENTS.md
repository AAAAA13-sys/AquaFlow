# AquaFlow frontend agent guide

Read the repository [backend and entry guide](../AGENTS.md) for shared rules and API changes. This frontend guide applies automatically within `public/`; the root guide also directs agents editing `resources/views/` to read it.

## Before editing

1. Trace the Blade page, its layout/partials, included styles/scripts, event handlers and API calls. Identify which cashier, owner, rider or refiller screens share the code.
2. Search callers before changing globals or response shapes. For API contract changes, trace the Laravel request, controller and resource with the backend guide.
3. For audit fixes, read the relevant section of [the dated codebase audit](../docs/CODEBASE-AUDIT-2026-10-15.md), then verify current behavior. Implement only the requested scope.

## Work with the existing frontend

- Pages use Blade, CSS and plain JavaScript served directly from `public/`. Preserve that structure; there is no Vite/Tailwind build requirement.
- Reuse existing styles, dialogs, formatting, API methods and snapshot helpers. Read `resources/views/partials/scripts-base.blade.php` and `scripts-admin.blade.php` before adding scripts or moving shared globals; inclusion order matters.
- Keep user-facing behavior in the appropriate `public/js/pos`, `public/js/admin`, `public/js/auth` or `public/js/data` module. Avoid adding a framework or dependency for a small interaction.
- Preserve asset-version query strings when adding Blade CSS/JS references. Packaged release images require rebuilding; changing source does not update an existing distributed package.

## Interaction and data rules

- Treat API responses as the source of truth for prices, stock, receipt totals, balances and role permissions. Browser session/storage mirrors help navigation and recovery; they grant no permissions.
- Use `API` and the existing financial `Submissions` flow for sales and settlements. Preserve submission keys across uncertain failures and reloads; clear them only through the established acknowledged-success or definite-validation-failure paths.
- After a successful write, update the appropriate snapshots. Distinguish a committed write followed by refresh failure from a failed write so the user does not repeat a payment or stock movement.
- Preserve duplicate-click guards, stale-response checks and retry behavior. Financial actions need clear pending/error/success states; failed reports must not export stale results.
- Keep the POS step gates, keyboard shortcuts, stock confirmation, cash limits and customer-switch behavior consistent. A keyboard action must pass the same checks as its button.
- Match current server validation in form limits and messages. Frontend checks improve feedback; the server must still validate every write.
- Escape dynamic HTML with the existing helper or use `textContent`. Keep CSV exports on the shared escaping/formula-neutralization path and preserve numeric values as numbers.
- Keep existing focus/visibility/reconnection synchronization behavior. Avoid overlapping refreshes or allowing old search responses to overwrite a newer selection.
- Preserve employee role restrictions and delivery-state/version checks when changing work cards or assignment controls.

## Accessibility and layout

- Use semantic buttons, labels and dialogs. Keep keyboard access, visible focus, dialog focus/close behavior and readable error/status feedback.
- Verify long names, empty lists, offline/error states, large balances and narrow screens for the changed view.
- The POS has a fixed-height shell with deliberate internal scrolling. Keep navigation/payment controls reachable; queue/history pages may use normal document scrolling.
- Verify receipt printing separately from on-screen layout when changing receipt markup or print styles.
- Password inputs must use full-width input fields (`.credential-control`) with inside-field eye icon toggles instead of separate exterior "Show" text buttons. Maintain accessible `aria-label`, `aria-pressed`, `title` and SVG icon states.

## Verification and completion

1. Run `node --check <changed-file.js>` for changed JavaScript, then `npm test` from the repository root. Add a focused case to the relevant existing `tests/frontend-*.cjs` harness for changed non-trivial behavior.
2. For Blade changes, check included asset paths and run the relevant Laravel page/Blade test when a safe test database is available. Run `php scripts/check-encoding.php` and `git diff --check`.
3. Exercise the changed role/page in a browser when the application is available: success, validation failure, network failure/retry and keyboard interaction. Check the console and network requests for errors. For responsive or print changes, inspect those layouts as well.
4. Report exactly which tests and browser checks ran. Node harnesses use simulated DOM/API behavior; passing them does not prove a real screen, printer or database workflow works. Name unavailable services or untested devices explicitly.
