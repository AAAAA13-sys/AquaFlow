# AquaFlow for your store

## First installation

1. Install Docker Desktop for Windows with WSL 2 and Linux containers. Start Docker and wait until it is ready. Docker installation needs internet; the packaged AquaFlow images do not.
2. Extract the entire AquaFlow ZIP to a permanent, private folder (not a shared network folder). Keep several GB free for Docker images, the database and backups.
3. Double-click **Setup.cmd**. It loads the packaged images, prepares the database and asks for your owner name, username, password and PIN. Passwords require at least eight characters with uppercase, lowercase and a number. Keep your PIN private.
4. Open the owner login from the login page. Review station settings, prices and inventory thresholds. Add actual suppliers, employees and cashier accounts; record opening stock through stock entries before selling.

There are four POS products. Setup supplies their catalog and consumption rules, but no sample accounts, customers, employees, sales or opening stock. Running Setup again preserves an existing owner's credentials and catalog. Do not use Setup to upgrade; use Update.

## Daily use

Run **Start AquaFlow.cmd** and keep Docker running. The browser opens at http://localhost:8080. **Stop AquaFlow.cmd** stops services without deleting data. Docker must be running after each Windows sign-in before using the launchers.

This package supports one store installation per computer and uses a separate `aquaflow-store` Docker project. Stop any development app already using port 8080, or change `AQUAFLOW_HTTP_PORT` and `APP_URL` in the generated `.env` together.

The admin PC hosts Docker and must stay on (disable sleep during store hours). Cashier PCs may use Ethernet and cashier phones may use Wi-Fi on the same router. They only need a browser, not Docker or an app installation. Start displays the available LAN/Wi-Fi addresses, such as `http://192.168.1.25:8080`. Use the address for the store network, not a VPN. Reserve the host's IP in the router if you want it to stay fixed.

Allow the chosen port through Windows Firewall on the private network only. Guest Wi-Fi or client isolation may prevent phones from reaching the host. LAN access works without internet and without ngrok. No database port is exposed.

## Optional internet access with ngrok

1. Create a store-owned ngrok account and get its authtoken from https://dashboard.ngrok.com/get-started/your-authtoken. Prefer a separate token per store PC. Never distribute your personal token in a release ZIP.
2. Run **Configure Remote Access.cmd** on the host and paste the token into the hidden prompt. It is saved only in this installation's private `.env` (and its private backups).
3. Run **Start AquaFlow.cmd**. The optional ngrok container starts inside Docker; once connected, the launcher prints **INTERNET CASHIER LINK: https://...**. Share that link with the cashier. Keep the launcher window open to read/copy it; closing the window does not stop the services.
4. Run **Disable Remote Access.cmd** to close the tunnel while keeping LAN access, or **Stop AquaFlow.cmd** to stop everything. While enabled, Docker may restart the tunnel after a host restart.

Internet access needs the host online and ngrok available. Account limits and ngrok's browser interstitial may apply. Start never invents a URL: it reads the running agent, and reports when no HTTPS link is ready. Run Start again after connectivity recovers. Local access remains usable if the tunnel cannot connect.

The public link exposes both login pages; the application's account permissions still apply. Use real store accounts with private passwords and owner PINs. The tunnel is HTTPS, with request capture disabled and the agent API bound to the host's loopback only. This adds remote access, not separate databases for each cashier.

References: https://ngrok.com/download/docker and https://ngrok.com/docs/gateway/agent/api

## Backups

Run **Backup.cmd** regularly and copy the entire resulting backup folder to a private external drive. It contains SQL, the store encryption key and database credentials; protect it like the store's records. Backups remain local unless you copy them. The release ZIP is not a data backup.

Run **Restore.cmd** to replace current records from a backup of the same release. It asks for a backup folder and explicit confirmation, stops application services and saves the current database before importing. A failed restore leaves services stopped for investigation; do not resume selling until recovery succeeds.

For a replacement PC, obtain the same release listed in the backup's `release.env`, extract it and run Setup to create the new database with temporary owner credentials. Then run Restore with the original backup. Restore recovers the original records and application key while retaining the new PC's database credentials. Original owner credentials apply after restoration.

Do not delete Docker's `aquaflow-store_db_data` volume, factory-reset Docker or run `down -v`: those actions delete store data. The launchers never remove that volume.

## Updates

1. Close cashier browsers and run Backup. Copy the backup somewhere safe.
2. Extract the new release over this installation folder, keeping `.env`, `installed.env` and `backups`. Release packages never contain those files.
3. Run **Update.cmd**. It stops services, takes another backup, loads images and runs database migrations before restarting. It never seeds demo records or resets stock.
4. If an update fails, retain its backup and error output. Do not downgrade images against an upgraded database; use the matching older release with its pre-update backup to recover.

Charts are packaged locally. Typography uses installed system fonts, so internet is not needed for the UI. Forecasts run locally in Python; there are no AI API keys.
