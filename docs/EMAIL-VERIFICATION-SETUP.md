# Owner email verification

Updated 9 October 2026.

The owner selected Gmail (`aquaflow.verify@gmail.com`) as both the sender and this store's receiving address. The supplied app password is stored only in the ignored local `.env`; it is excluded from Git and Docker build contexts. Do not copy this store's credentials into another installation or release ZIP.

## Store setup

Run `Configure Email.cmd` in a distributed installation to enter the Gmail sender and app password with hidden input. New Setup runs prompt for these settings automatically. Gmail must permit app passwords on the account. Use SMTP on port 587 with TLS; never use the ordinary Gmail password. [Google app passwords](https://support.google.com/mail/answer/185833), [SMTP settings](https://support.google.com/mail/answer/7104828).

A clean installation opens a setup-only account. At `/owner/login`, use `admin@aquaflow.com`, password `Adm1nP4ss!123`, and setup code `571067`. Then enter your own name, email and password, click Send OTP, and verify the emailed code. Only then is a permanent admin created and the temporary account deleted. Finish this step on the store host before enabling remote access. These shared setup credentials never open the dashboard and cannot be used on an existing store. For an existing account, use the trusted store host:

```bash
docker compose exec app php artisan aquaflow:owner-email OWNER_USERNAME OWNER_EMAIL
docker compose exec app php artisan aquaflow:mail-test OWNER_EMAIL
```

For the distributed package, include its Compose file and private environment files as described in Getting Started.md. `mail-test` sends a harmless configuration message. SMTP acceptance is not proof that the message reached the inbox; also inspect spam.

## Sign-in and recovery

Admins open `/owner/login`. Enter your admin email and password, click Send OTP, then enter the six-digit emailed code. Cashiers, riders and refillers use the separate Employee Login at `/` with their username and password. The first verified sign-in displays eight recovery codes exactly once. Save them privately before continuing. Each recovery code works once together with the password, including when email is unavailable.

Codes expire five minutes after issuance, allow five attempts, are bound to the pending browser session and can be consumed once. A replacement is allowed after 60 seconds and invalidates the old code. Login/verification/recovery endpoints are rate limited. PIN input is rejected; admin credentials alone cannot authenticate anyone.

The separate `mail-worker` consumes the `mail` queue. OTP jobs are encrypted using APP_KEY. Delayed jobs skip expired or replaced challenges. SMTP errors do not fall back to logs and the worker reports sanitized failure messages.

```bash
docker compose ps mail-worker
docker compose exec app php artisan queue:failed
```

Keep APP_KEY with a private database backup: encrypted queued jobs cannot be recovered with a different key. Restart/recreate the mail worker after changing configuration or code. Owners must complete the first email verification to obtain recovery codes; a developer cannot confirm inbox ownership for them.

Proton Free remains unsupported as the application's sending service; it is no longer the selected sender. [Proton SMTP requirements](https://proton.me/support/smtp-submission).
