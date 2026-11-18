# Africa Trade OS — deployment and operations

## What changed in this hardening release
- Seller product photos are optional. Sellers can upload a main photo, choose from 12 bundled product illustrations, take a camera photo, add up to 8 gallery photos, or publish with no image.
- Product uploads validate actual MIME type, image dimensions and a 5MB per-image limit.
- Stock is reserved atomically at checkout to prevent overselling; unpaid reservations expire after two hours when the CLI expiry worker is scheduled.
- Buyer permission is restricted to active, email-verified `buyer` accounts. Seller, supplier, logistics and admin accounts cannot purchase. Self-purchase is blocked server-side.
- ID front, ID back and selfie captures are validated by actual image format, size and minimum dimensions. Files are stored in a protected uploads directory with direct web access denied; only authenticated administrators can view them through `admin/kyc-image.php`.
- ID numbers are encrypted at rest with AES-256-GCM using `ATO_KYC_ENCRYPTION_KEY`; keep this key backed up separately. Identity approval is manual. The app does not pretend that a browser fingerprint prompt or a captured selfie is a biometric identity match or liveness test.
- Order progression is controlled: successful payment -> seller confirmation -> carrier assignment -> carrier collection -> admin dispatch approval -> carrier arrival -> admin delivery confirmation -> payment record release.
- Emails are recorded in `email_outbox`; failed deliveries can be retried by the CLI worker.
- Login attempts are rate-limited. OTP values are hashed and expire after 15 minutes.
- The original database import no longer drops tables or creates public `password` demo accounts.

## Fresh installation
1. Create an empty MySQL/MariaDB database named `africa_trade_os`.
2. Import `database/africa_trade_os.sql`.
3. Use `.env.example` as a template for environment variables (it is not auto-loaded by PHP); configure them in your hosting panel, including `APP_ENV=production` (do not upload real secrets into public source control).
4. Create the first administrator from a terminal in the project directory:
   `php scripts/create-admin.php`
   The admin tool is CLI-only and requires a password of at least 14 characters.
5. Configure SMTP and Daraja credentials in the hosting environment. The Daraja endpoint and callback PHP path are retained; configure the callback URL for the deployed domain.
6. Ensure PHP has PDO MySQL, Fileinfo, OpenSSL and cURL enabled. Set `upload_max_filesize` and `post_max_size` high enough for KYC image captures (at least 10M and 30M respectively).
7. Use HTTPS. Keep `uploads/ids`, `uploads/documents`, `app/config` and `database` blocked from direct web access. Apache `.htaccess` rules are included; apply equivalent Nginx rules if you use Nginx.
8. Schedule `php scripts/process-email-queue.php` every 5 minutes and `php scripts/expire-unpaid-orders.php` hourly using server cron jobs.

## Upgrading an existing installation
1. Take a full database backup and a copy of the current project. Preserve existing `uploads/products` files. Back up `uploads/ids` separately and migrate those private identity files into the protected upload directory; do not expose or publish them in a public ZIP.
2. Import `database/production-upgrade.sql` **once**. It adds workflow and security columns and does not drop existing records. Do not rerun it without checking the schema first.
3. The original public demo accounts `admin@africatradeos.com` and `seller@africatradeos.com` are suspended by this upgrade if present. Create a new administrator using the CLI tool and register a new seller account.
4. Existing seller accounts should have their password and KYC status reviewed. Existing KYC uploads from the old project were stored under a public web path; move them to protected storage and ensure the included `uploads/ids/.htaccess` is active.
5. Set `APP_URL` to the HTTPS deployment URL and set the actual database, SMTP, Daraja and `ATO_KYC_ENCRYPTION_KEY` environment variables. For an older database, run `php scripts/encrypt-legacy-kyc.php` after the key is configured.

## Important payment and escrow limitation
The existing Daraja STK Push integration and callback endpoint are retained. A normal M-Pesa PayBill/Buy Goods collection sends funds to the configured merchant/shortcode; changing an order row to `funded_held` does **not** itself place money in a legally segregated escrow account. Before taking real customer funds, contract a regulated escrow/custody or marketplace settlement provider and integrate its verified hold/release API. Until then, the app's escrow fields are workflow records only, not proof that money is held by a third party. Never advertise guaranteed escrow custody or automatic bank settlement based only on these database statuses.

## Verification and deployment checklist
- Rotate any SMTP App Password, Daraja consumer secret and passkey that were previously present in source files or ZIP archives.
- Keep `MPESA_ALLOW_SIMULATION=false` on every public environment. Simulation is for isolated local testing only.
- Verify the Daraja callback with a real provider test and reconcile the callback amount and receipt against the transaction report.
- Test all emails using real recipient inboxes and monitor the outbox.
- Test seller confirmation, carrier collection, admin dispatch, carrier arrival and admin delivery approval with separate accounts.
- Review applicable Kenyan and cross-border privacy, consumer protection, KYC/AML, payments, tax and data-retention obligations before launch.
