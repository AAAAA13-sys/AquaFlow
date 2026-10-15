# Employee workflow verification - 15 October 2026

Scope: cashier, refiller and rider permissions; prepared-order handover; buyer codes; private delivery images; cash declarations; remittances; buyer debt; drawer accounting; retries and historical evidence. These are source/test results, not a production deployment approval.

## Results from this run

| Check | Result |
|---|---|
| Complete Laravel unit and feature suites | 229 tests, 1,717 assertions; zero failures, errors or skipped tests |
| Frontend suite (`npm test`) | Passed, including photo/submission recovery, handover/code actions and stale partial-remittance forms |
| Windows distribution launcher tests | Passed with mocked Docker/network operations |
| Source and packaged Compose configuration | Both validated with `docker compose ... config --quiet` |
| PHP syntax | 169 application/configuration/route/migration/bootstrap files passed |
| JavaScript syntax | All 27 browser JavaScript files passed |
| UTF-8 BOM check and Git whitespace check | Passed |
| Edge/browser connection | Unavailable: browser inventory returned no browsers or native apps |

All database tests used `aquaflow_test` on an isolated local MariaDB instance at 127.0.0.1:33316. No live station database was migrated, reset, seeded or written. The temporary database service was stopped after testing.

## Regression found and fixed

A successful partial remittance followed by a failed list refresh left the old form usable. Submitting that stale form could create another legitimate-looking payment against the remaining rider balance. The new frontend regression test reproduced the second submission before the fix. An accepted form now stays locked, including when refreshing fails. Cancelled confirmations and rejected requests remain editable. Existing financial submission keys continue to protect uncertain requests.

Two older page tests were stale. The stock-label test now checks the current `Available stock` label. The cashier/owner separation test inspects actual links and form destinations instead of rejecting shared `/js/admin/` assets, and directly verifies the owner-page redirect and owner-API denial. Product permissions were not loosened.

## Employee and money cases exercised

- Only refillers mark preparation ready; cashier/owner handover checks the receipt quantity; riders cannot replace buyer codes or perform cashier handover.
- Assigned riders must wait for handover and dispatch before completing delivery. Inactive staff and other riders cannot bypass access checks.
- Buyer codes remain encrypted and hidden from rider responses. Wrong/replaced codes fail and repeated guessing is rate limited.
- Missing, fake, tiny, oversized and script images fail. Exact photo reuse fails. Images remain private and are inaccessible to refillers or other riders.
- A failure after storing the image rolls back the proof and fulfillment state and deletes the orphan file; retry succeeds.
- Delivery and rider cash reports do not reduce buyer debt or increase drawer cash.
- Prepaid deliveries cannot create another cash collection. Negative, zero remittance, over-limit and fractional-cent values fail.
- Pending rider cash is reserved; the buyer can pay only the unreserved balance through ordinary settlement.
- Cashier acceptance requires an open drawer and creates a payment receipt, decreases buyer debt and increases only the accepting cashier's drawer, without adding sales revenue.
- Short collection/remittance needs an explanation; missing cash and uncollected buyer debt remain outstanding. Two cashiers share the same remaining remittance balance and receive only the amounts each accepted.
- Receipt replay after drawer closure is safe, conflicting reuse fails and already accepted money cannot be remitted again.
- Old outstanding remittances and historical evidence remain reviewable; referenced staff accounts cannot be deleted.
- Lost responses preserve the delivery photo, original request body and submission key. Accepted partial-remittance forms cannot submit again after a refresh failure.

## Existing evidence from the preceding implementation run

A real session-authenticated HTTP smoke test exercised cashier handover, rider dispatch, multipart image upload, private image retrieval and cashier remittance acceptance. Two separate PHP processes also raced against MySQL to accept the same rider collection: exactly one acceptance succeeded and the other was rejected. Those backend paths were unchanged in this checking pass; these are earlier checks, not additional runs claimed here.

## Remaining limits

No Edge/phone camera, visual layout, keyboard, print or accessibility walkthrough could run without a connected browser. Docker configurations were validated; fresh image builds, deployment upgrades and actual backup/restore were not exercised. Automatic launcher tests use mocks and do not establish real deployment behavior.

Buyer codes must be shared privately by the cashier; SMS/email sending is not automatic. Photos are evidence, not a guarantee of identity, location, freshness or authenticity. The private photo volume needs its own backup alongside SQL and APP_KEY; existing Backup.cmd does not copy those images. See [delivery operation and rollout instructions](DELIVERY-ACCOUNTABILITY.md). No production migration or image rebuild was performed.
