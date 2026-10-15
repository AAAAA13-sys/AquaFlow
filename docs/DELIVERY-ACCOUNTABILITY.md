# Delivery evidence and remittance

Buyer debt is reduced only by a cashier/owner accepting physically counted rider remittance. A delivery or a rider cash declaration never settles debt. Cash accepted is a `Debt Payment` receipt, included in the accepting operator's drawer and excluded from sales revenue.

## Work sequence

1. The cashier creates the sale. Account deliveries add buyer debt as before; prepaid deliveries must report zero rider cash.
2. A refiller verifies all receipt items and marks the order ready. It appears in the shared rider queue without an owner assignment.
3. On Orders in Progress, the cashier counts the prepared items and confirms handover. This records the cashier and time and generates an encrypted eight-digit buyer code.
4. The cashier privately gives the code to the buyer using the station's existing contact process. Never put the code on the rider's delivery slip or give it to the rider. The application does not send SMS/email automatically. Tell buyers to check the delivered items and money paid before sharing the code.
5. The first rider to start the handed-over order claims it. The rider then supplies a JPEG/PNG/WebP photo (320 x 240 minimum, 6000 x 6000 maximum, up to 5 MB), recipient name, buyer code, exact receipt quantity and cash collected. Missing items must be reported as an issue. Partial/no collection requires an explanation. The server checks role, claim, state, version, quantity, code, image content and the available account balance. Exact photo reuse is rejected.
6. Delivery completion saves immutable evidence and a pending cash declaration. The buyer balance and cashier drawer remain unchanged. Pending rider cash is reserved against the buyer balance, preventing the ordinary settlement endpoint from taking the same payment twice. Any unreserved balance can still be paid normally.
7. With today's drawer open, the cashier reviews the evidence, physically counts cash from the named rider, and accepts that amount. This atomically creates the remittance and payment receipt, reduces buyer debt and adds cash to that cashier's drawer. A short remittance requires a note; the unreceived part stays outstanding for the rider and remains in buyer debt. Retries return the original receipt. A second cashier or a fresh submission cannot accept already-remitted cash again.

Active orders, today's deliveries and all older outstanding remittances appear in live fulfillment. Delivered order history provides access to older evidence and accepted remittance records. Legacy completed orders have no retrospective proof and are labelled accordingly. For deliveries already dispatched when upgrading, the cashier must verify the existing handover and privately issue the buyer code before the rider can finish. No historical cash or evidence is invented by the migration.

A buyer code can be replaced by a cashier/owner if compromised, with an audit event and version update. Riders never receive stored codes in API responses. Uploaded photos are served only to cashier/owner accounts and the assigned rider, with private/no-store and nosniff headers. Refiller accounts cannot view them. Browser retry storage retains the original photo and submission key until a definitive response; do not clear browser data while a delivery is uncertain.

## Installation and evidence backups

Apply the new migration through the normal upgrade flow after backing up the installation. Do not reset a live database. Docker application/web images must be rebuilt for the new code and 5 MB upload limit. For XAMPP, set `upload_max_filesize=5M` and `post_max_size=8M` in the PHP configuration used by Apache, then restart Apache. No host PHP settings are changed automatically.

Both Compose definitions retain `storage/app/private` in the named `proof_data` volume. Do not remove that volume. The application encryption key must be retained to read existing buyer codes.

**The existing Backup.cmd/Restore.cmd handle SQL and configuration, not uploaded photos.** Back up the private evidence directory alongside every SQL backup. After stopping writers and taking the SQL backup, copy it with:

```powershell
docker compose cp app:/var/www/html/storage/app/private/delivery-proofs ./backups/your-backup/delivery-proofs
```

For the packaged release, use `docker compose --env-file .env --env-file release.env -f compose.yaml cp` with the same source/destination arguments, from the installation folder. Keep this folder private alongside the SQL/config backup. On restoration, stop application writers, restore SQL and APP_KEY using the existing procedure, and copy the matching files back before resuming work:

```powershell
docker compose cp ./backups/your-backup/delivery-proofs/. app:/var/www/html/storage/app/private/delivery-proofs
```

Copying into an existing directory preserves unrelated files; restored database records control which evidence is accessible. Verify a historical proof before allowing deliveries again. A SQL-only backup does not preserve delivery evidence.

## Limits

A valid image and buyer code provide evidence and responsibility, not an automated determination that a photo is authentic, freshly taken or at the correct address. Slightly edited copies evade exact-byte duplicate detection. Cash declarations remain claims until cashier acceptance. This does not prevent collusion, a buyer sharing a code early, or a cashier accepting uncounted money. Owner review of photos, zero/partial collections, short remittances and payment receipts remains necessary. No facial recognition, location tracking, automatic messaging or retrospective verification is added.
