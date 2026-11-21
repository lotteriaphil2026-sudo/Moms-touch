# Mom’s Touch — Laravel member portal

Laravel 12 / PHP 8.4+ application with an English member portal, server-owned wallet balances, administrator approvals, plan assignments, and franchise applications. Ready-to-serve frontend assets are included in `public/assets`; no Node build is required.

## Current launch state

New accounts and newly created administrators start with **₱0.00** available, reserved, invested, and earned. There are no sample users, allocations, transaction history, or default passwords. The seeder is intentionally empty in every environment. Deployment never resets an existing balance or deletes ledger history.

The Sites-hosted publication serves the sign-in design and this source download. **It cannot execute PHP.** Until the Laravel app is deployed to a PHP host, its static sign-in/registration forms are disabled, protected URLs return to sign-in, and no browser-saved sample account is accepted. The former sample credentials are retired.

Registration and internal wallet operations are closed by default. Registration requires a phone number, birthday, residential address, gender, and marital status. Profile details are stored with the account and shown only in that member’s authenticated session; phone numbers are also available in administrator member views. The application is prepared for deployment; it is not a connected bank, payment gateway, or automatic payout system. Host configuration, an actual database, email delivery, and the operator’s final policies remain required before opening accounts.

## First deployment on a PHP host

Use PHP 8.4+, Composer 2, and Laravel’s PHP extensions, including PDO MySQL or PostgreSQL, mbstring, DOM/XML, fileinfo, ctype, tokenizer, and OpenSSL. Use a dedicated MySQL/MariaDB database with InnoDB tables or PostgreSQL for durable wallet row locks. Serve only the `public/` directory over HTTPS; keep the rest of the project outside the web document root.

1. Install the locked dependencies and create a private environment file:

   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   ```

   Copy the template only for a new installation. For an existing installation, preserve its `.env`, application key, database, and financial records. `composer run setup` only installs dependencies and copies a missing template; it does not generate keys, seed accounts, or migrate a database.

2. Set the actual HTTPS `APP_URL`, database credentials, SMTP provider/sender, and secure cookie settings in `.env`. Keep `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, and `SESSION_HTTP_ONLY=true`. The template uses server-side database sessions and cache. Keep `.env` private and out of source control. If TLS terminates at a proxy, configure only the host’s trusted proxy addresses in `bootstrap/app.php`.

3. For a **new installation with an empty APP_KEY only**, generate the key once:

   ```bash
   php artisan key:generate
   ```

   Preserve that key for subsequent releases and backups. Configure automated database and environment backups and verify a restore on a separate database before launch.

4. Apply migrations to the configured database, then create your administrator privately:

   ```bash
   php artisan migrate --force
   php artisan portal:create-admin your-admin-email@your-domain.com --name="Portal Owner"
   ```

   The command privately prompts for a 12–128 character password and confirmation. It hashes the password, creates a zero-balance wallet, refuses an existing email, and never prints the password. Members and administrators use the same sign-in page. There is no seeded admin login.

5. Check settings and database readiness, then cache the application:

   ```bash
   php artisan portal:check-production
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

   `portal:check-production` is read-only and returns a nonzero status for missing production settings, placeholder HTTP URLs, insecure sessions, unsupported wallet databases, incomplete SMTP settings, missing tables/migrations, missing administrators, or legacy sample accounts. It does not print configured secrets or change balances. `--config-only` checks settings without accessing the database and is not a full deployment check. A passing check does not verify TLS, SMTP delivery, backup restoration, payment settlement, or business policies: verify those on the host.

6. Set filesystem ownership so the PHP process can write `storage/` and `bootstrap/cache/`, while `.env` and application source are not publicly readable. Ensure the web server serves assets from `public/`, blocks dotfiles, and forwards other requests to `public/index.php`. Keep server logs private and monitor failures. A generic `/up` endpoint checks application startup, not payment or database health.

7. Publish the operator’s final privacy/contact/retention notice and membership, plan, deposit, withdrawal, maturity/redemption, and eligibility terms. Replace the launch notices in `public/assets/app.js`. Verify reset-email delivery, sign-in/logout, member/admin isolation, and franchise submission on the actual HTTPS host. Then explicitly set `PORTAL_REGISTRATION_ENABLED=true` and run `php artisan config:cache` again.

## Wallet operations and approval rules

`PORTAL_FINANCIAL_OPERATIONS_ENABLED=false` blocks member financial requests, admin reviews, direct credits, plan grants, and interest accrual. It remains off in the deployment template. Only enable it after the operator has established deposit evidence, payout instructions, reconciliation, and the plan/principal rules. `PORTAL_DEPOSIT_QR_DEMO_MODE=false` uses the configured BPI, LANDBANK, and Security Bank QR images; `true` marks deposit requests as demo-only and prevents approval. Rebuild the configuration cache after changing a flag.

When enabled:

- Deposits remain pending and cannot increase a balance until an administrator approves them. The default bank QR flow is explicitly test-only: its QR images encode test text, are not payment instructions, and its requests cannot be approved. Do not collect money using these demo QRs. To accept deposits, replace them with official merchant QR codes, configure the production flow, and verify receipt through the operator’s actual payment records; there is no payment webhook.
- Withdrawals move available funds into a reserved balance immediately. Approval consumes the hold once; rejection releases it once. Admin approval updates the ledger; it does **not** send a bank/e-wallet payment. The operator must separately complete and reconcile the payout.
- Admin credits add available funds. An assigned plan adds principal without charging the member’s available balance. Every action records its actor, member, amount, reason, and timestamp in the audit table.
- All amounts use integer centavos, database transactions, consistent wallet-first locks, UUID request references, and payload fingerprints. Altered retries are rejected. Amounts are limited to ₱100–₱1,000,000 per operation; plans must meet the minimum.
- Registration/profile requests cannot set admin roles or balances. Every admin endpoint requires a server-authenticated admin role, CSRF, and rate limiting. Authentication secrets are excluded from account responses. Browser storage never authorizes an account or owns its ledger.

## Supplied plan terms

| Plan | Minimum | Daily simple interest | Term | Daily at minimum | Term interest at minimum |
| --- | ---: | ---: | ---: | ---: | ---: |
| Mom’s Select | ₱5,000 | 0.60% | 160 days | ₱30 | ₱4,800 |
| Mom’s Prime | ₱50,000 | 0.70% | 130 days | ₱350 | ₱45,500 |
| Mom’s Signature | ₱250,000 | 0.90% | 100 days | ₱2,250 | ₱225,000 |

These are the operator-supplied configuration, not a promise of actual payment. Each allocation snapshots its rate and term. Interest is simple on the original principal, rounded half up to centavos per day, for completed 24-hour periods. Credits begin after 24 hours, catch up idempotently, and stop at the term. Principal remains allocated: no automatic principal redemption, release, or payout policy has been implemented. Finalize that process before enabling financial operations.

`InterestAccrualService` reconciles enabled allocations during member state loads and before wallet mutations. For timely credits without member visits, enable the server scheduler:

```cron
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled command uses overlapping-run protection and stops when wallet operations are paused. All new accounts have no allocations and earn zero until a funded plan is created.

## Authentication, security, and email

Laravel web sessions and CSRF protect mutations. Passwords are hashed; login rotates the session and returns a new CSRF token. Login, registration, reset requests, admin actions, and member mutations are throttled. Data access is scoped to the current user; admins have explicit server-enforced access. Dynamic responses use `private, no-store`, same-origin CSP with a script nonce, frame protection, and security headers; HTTPS production responses include HSTS. Only the configured application host is accepted outside local/testing environments.

Password resets use Laravel’s password broker and the configured SMTP provider. Public reset responses are identical for known and unknown emails, including when mail delivery fails. Verify real delivery and the reset URL on the host. No live email was sent during automated verification.

Every account has a unique referral code and a referrer. Existing accounts and new signups without a referral link are assigned to the Mom’s Touch platform root; valid shared links attribute new accounts to the referring member. Referral tracking currently has no bonuses or payouts. The portal’s public page includes Open Graph and Twitter metadata plus a 1200×630 share image for Messenger previews; social networks may cache previews, so refresh the URL in their sharing debugger after deployment if needed.

Successful member sign-ins (including the initial session after registration) record the request IP address, user-agent string, and approximate country, region, and city when supplied by the hosting edge. The app reads optional `CF-*`, `CloudFront-Viewer-*`, or `X-Appengine-*` geolocation headers and does not send IP addresses to a third-party lookup service; unavailable location fields remain blank. Configure the trusted proxy/edge to overwrite these headers, and never use this approximate location as an authorization signal. Failed sign-ins and administrator sign-ins are not recorded. Authorized administrators can see the 20 most recent records on a member profile. Records older than 30 days are removed by the scheduled `portal:prune-sign-ins` command, so configure Laravel Cloud to run `php artisan schedule:run` every minute. Update the live privacy notice before collecting this metadata. Registration collects phone number, birthday, residential address, gender, and marital status; these are available in the member’s authenticated session, and administrators can also see phone numbers.

## Franchise applications and content

Authenticated members can submit contact/location details, an optional budget and timeline, and contact consent. The server owns references, member ownership, and application status. Members can see their own history; administrators can view submitted details. An application is an expression of interest, not a franchise award. No franchise notification or staff status-editing workflow is connected.

Events and menu content remain illustrative. Replace them with approved operator content before advertising live offers. Brand/product photography comes from the official Mom’s Touch Mongolia press kit; confirm usage for the intended publication. DM Sans and Manrope font licenses are included in `public/assets/licenses/`.

## Verification and package contents

```bash
composer install
php artisan test --compact
```

PHP feature tests use isolated, in-memory SQLite and cover authentication/CSRF, permissions and isolation, sign-in metadata retention, approval/hold arithmetic, idempotency, interest bounds, franchise validation, private admin creation, empty seeding, session response headers, reset failure handling, and read-only deployment checks. Six JavaScript checks cover exact plan quotes, clean zero state, retired browser demo storage, login guards, disabled static forms, and refused static API actions. Test the configured MySQL/PostgreSQL host before enabling wallet operations; no live database, SMTP, or payout provider has been configured here.

The ZIP excludes `.env`, encryption keys, installed dependencies, databases, sessions, logs, runtime caches, and test-result caches. `composer.lock` is included for reproducible dependency installation. Existing account data is not part of the source package.
