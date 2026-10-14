# Smart Account POS backend

Laravel 13 API for the matching POS frontend. The committed lockfile uses Symfony 8, so deploy with PHP 8.4 or later, Composer 2, and SQLite or a production database supported by Laravel.

## New installation

```sh
composer install
cp .env.example .env
php artisan key:generate
# For SQLite only: create database/database.sqlite before migrating.
php artisan migrate
php artisan pos:setup-store "Your store" --code=MAIN --address="Your address"
php artisan pos:create-admin owner@example.com --name="Store owner"
php artisan serve
```

The owner command prompts securely for a password. It does not load demonstration records. Create suppliers, products, customers and staff from the application. The first Cash account starts at zero. `pos:setup-store` is idempotent and preserves existing balances. Use the existing finance/account provisioning process for additional payment accounts.

For local demonstrations only, run `php artisan db:seed`; it creates known-password test accounts and sample financial data. The demo seeder refuses production execution.

## Upgrade existing stores

Back up the database, deploy this API, run `php artisan migrate --force`, and then deploy the matching frontend. The upgrade adds catalog status/reorder controls, sale retry keys, stock adjustment history and permission definitions. Existing roles receive no new powers automatically: an owner must grant the desired purchasing, returns, expenses and transfer permissions in Staff & permissions. Ordinary checkout staff also need `inventory.view` for the product/bootstrap data.

Do not rerun demo seeding over a live store. Historical incorrect balances are not rewritten automatically; reconcile them against actual cash, customer advances and stock counts.

## Production configuration

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the backend HTTPS origin, `FRONTEND_URL` to the exact frontend HTTPS origin (comma-separated allowlist supported), and `APP_TIMEZONE` to the store's timezone. Generate and preserve `APP_KEY`. Empty JWT_SECRET/JWT_ENCRYPTION_KEY fall back to APP_KEY; set dedicated secrets if desired and preserve them across releases. Rotation invalidates existing sessions. Configure database/cache/queue settings before `php artisan optimize`. Use a shared persistent cache for token revocation and refresh locks on multi-instance deployments.

Expose only Laravel's `public/` directory through the web server. The local development server is for local verification. Do not commit .env, credentials, logs, databases or vendor files.

## Validation

```sh
php artisan test
composer audit
vendor/bin/pint --test --dirty
```

The workflow suite covers stock and money rollback, duplicate checkout prevention, advances, held orders, count conflicts, RBAC, collections, purchase and sales returns, free purchase units, payouts, true daily revenue and atomic customer imports. SQLite integration tests do not establish production MySQL/PostgreSQL locking or multi-worker load behavior.

## Data boundaries

Inventory and payment accounts are global across outlets in the existing schema. Outlet selection is recorded on transactions; this is not independent branch stock accounting. Internal warehouse transfers preserve global stock, company outbound transfers deduct it. Returns reference original documents and the API caps cumulative returned quantities and financial credits. Sales returns currently require a registered customer; walk-in return support needs an explicit account policy/schema extension. SMS and payment-terminal integrations are not connected and no success is claimed for them.

### Staff administration follow-up

`POST /api/v1/auth/register` accepts an optional existing `designation_id` and saves it with the staff account in one transaction. Deploy this API version before the corporate-workspace frontend, which sends the selected role during registration. Role permission updates accept an empty array to revoke all permissions. Role reassignment rejects removal of the last administrator; assign another administrator first. This follow-up requires no additional migrations beyond the initial POS upgrade. Regression coverage: 44 tests / 235 assertions.

### Employee email verification

Staff registration now requires confirmation of the employee's mailbox. An administrator with `users.manage` sends `POST /api/v1/auth/register/request-code` with `{ "email": "employee@company.com" }`. The employee receives a six-digit code and gives it to the administrator. The registration request must include that code as `verification_code` along with the existing staff fields.

No user is created until the code is accepted. Successful registration sets `email_verified_at` and returns the created user; it no longer returns employee access/refresh tokens to the administrator. Codes expire after ten minutes, allow five incorrect attempts, and can be resent after one minute. They are tied to the requesting administrator and normalized email, stored as keyed hashes, and consumed after successful creation. The code endpoint also has a request limit. Existing users are not automatically marked verified.

Configure actual delivery before deploying the matching frontend. `MAIL_MAILER=log` is for local testing only and is rejected for production registration. Set these variables in the backend host (for example, Vercel project Environment Variables), using the configured Gmail sender `arnob4all@gmail.com`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=arnob4all@gmail.com
MAIL_PASSWORD="<Google App Password stored only in deployment secrets>"
MAIL_FROM_ADDRESS=arnob4all@gmail.com
MAIL_FROM_NAME="Smart Account POS"
```

For this Gmail account, enable 2-Step Verification and create a dedicated App Password in [Google Account settings](https://myaccount.google.com/apppasswords). Store it as `MAIL_PASSWORD` in the backend host's secret environment variables, never in chat or source control. This SMTP integration uses an App Password, not the ordinary Gmail sign-in password; see [Google's App Password instructions](https://support.google.com/accounts/answer/185833). Set all of the variables above on the backend host, remove any stale `MAIL_URL` override, and redeploy or refresh the configuration cache. Gmail uses STARTTLS on port 587 with `MAIL_SCHEME=smtp`, as documented in [Google's SMTP settings](https://support.google.com/mail/answer/7104828). The checked-in example keeps `MAIL_MAILER=log` until a real secret is supplied. No real delivery has been verified yet.

Use the provider's required port and scheme (for example `smtps` with port 465 if required). The sender/domain must be authorized by the mail provider. Keep credentials in deployment secrets, never Git. Use a shared persistent cache such as Redis or a database cache accessible to every API instance; serverless `/tmp` and array caches cannot carry verification across instances. Preserve `APP_KEY`. Refresh cached configuration after changing environment values.

Deploy the backend and frontend together after mail configuration. No additional database migration is required for this feature. Local tests use Laravel mail fakes/log output; successful SMTP acceptance and actual inbox delivery must be checked with a real staff mailbox after deployment.
