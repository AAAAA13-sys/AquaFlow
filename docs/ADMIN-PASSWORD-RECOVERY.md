# Admin password recovery — 15 October 2026

Current behavior supersedes the older sign-in/recovery description in EMAIL-VERIFICATION-SETUP.md. Ordinary admin sign-in remains username/password. Email OTP is used for forgotten passwords.

At /owner/login, choose Forgot password, enter the email saved on the admin account, request a code, then enter the code and matching new passwords. Accounts without a saved email need the owner to configure one while signed in. Passwords follow CredentialRules: 8–64 printable ASCII characters, uppercase, lowercase and a number.

Codes are hashed, expire after 15 minutes, and are consumed once. Five wrong attempts burn the code. Requests are limited per email (one/minute, five/hour), plus the existing IP limit. Unknown, inactive, bootstrap and non-admin accounts get the same send response. Mail jobs are encrypted and queued on mail; stale jobs skip replaced/consumed/expired codes. Reset updates and token consumption are locked and atomic; existing database sessions are deleted.

## Verification and rollout

- PASS: npm test, focused frontend-admin-login harness, JavaScript syntax, git diff --check.
- BLOCKED: Docker engine pipe unavailable, so PHP feature tests, PHP encoding script and test DB effective configuration could not be verified.
- BLOCKED: browser tool denied access under its URL policy. No visual or real email delivery claim.
- No live migration, mail, credentials or station data changed.
- The new password_reset_otps migration must be applied through the normal deployment process after reviewing pending migrations. Recreate/restart mail-worker after deployment.
- Pending verification: docker compose exec test php artisan test --filter=AdminForgotPasswordTest (confirm aquaflow_test and no cached production config first).

## 16 October update: all accounts

- Recovery is now shared by admin and employee login pages, using the same queued OTP endpoints, expiry, attempt limits and session invalidation. No role or automatic session is granted by a reset.
- Recovery email is mandatory for new accounts and first-run owner setup; account edits cannot clear it. Existing accounts without an email must be updated by an admin before recovery can work.
- New admins must receive a password matching CredentialRules. Promoting a cashier requires a strong password too. Cashiers retain their temporary password and first-login change requirement.
- Add user now pairs fields in two columns (one on mobile), with an admin-only password field and separate help/action rows.
- Verified: 229 feature tests passed, followed by 8 focused tests covering the final promotion safeguard; npm test, JavaScript syntax checks, git diff --check, and the PHP encoding checker passed (308 source files).
- Applied only the recovery table creation and email-column expansion migrations to the local app. The initial expansion attempt exposed the missing table; applying both in order succeeded. No account credentials or store records changed.
- Live browser appearance and actual inbox delivery were not exercised. No email was sent by these checks.
