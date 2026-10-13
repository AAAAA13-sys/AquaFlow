# AquaFlow documentation map

## Current application and operations

| Document | Use |
|---|---|
| [Root README](../README.md) | Architecture, local startup and API overview |
| [Deployment](DEPLOYMENT.md) | Development Compose/XAMPP setup, test runner and operational guidance |
| [Store installation](../distribution/Getting%20Started.md) | Packaged store setup, update, backup/restore and remote access |
| [Development record](IMPLEMENTATION-2026-10-09.md) | Delivered scope and later login/setup corrections |
| [Master plan](MASTERPLAN.md) | MP backlog and accepted scope; historical details yield to the implementation record |
| [Email setup](EMAIL-VERIFICATION-SETUP.md) | Sender configuration and email-login requirements |

The application serves assets directly from public/css and public/js. There is no production Vite/Tailwind/axios asset pipeline. package.json retains concurrently for composer dev, plus a frontend test command. PHP dependencies remain governed by composer.lock.

## Dated evidence and research

[User-story verification](user-story-verification.md), [evaluation plan/results](EVALUATION.md), [8 October roadmap](erp-readiness-roadmap.md), [11 October QA](QA-2026-10-11.md), [12 October page audit](PAGE-AUDIT-2026-10-12.md), and [8 October update](../update.md) preserve earlier decisions, findings and measurements. Dates, deadlines and instructions embedded in old QA reports are historical context. Check current code/CI before treating an old count, failed test, PIN workflow or pending decision as current status.

The original [stakeholder PDF](../PROBLEM.pdf) and [story catalogue](../user%20stories.csv) remain retained. The PDF was not re-rendered during this cleanup.

## Test and configuration cleanup — 13 October 2026

- Consolidated duplicate legacy PIN-denial cases into AuthTest, including both PIN values, guest-session checks and protected bootstrap denial. The admin-screen assertion was already covered there; US03Test was redundant.
- Removed unused Vite configuration and resources/css/app.css, resources/js/app.js and resources/js/bootstrap.js after checking source, view, launcher and CI references. Removed their five unused npm dependencies; retained concurrently because composer dev calls it.
- Removed unused broadcast/Pulse/Telescope/Nightwatch PHPUnit environment toggles. Database isolation, mail/queue fakes and actual application configuration remain.
- Updated frontend CI paths and targeted auth-test commands. Retained unique checkout, inventory, fulfillment, attendance, OTP, retry, analytics and frontend behavior tests.
- Application/database behavior and historical migrations/data are unchanged. CI results for this increment must be inspected separately.
