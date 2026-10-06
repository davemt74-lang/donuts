# Production deployment

## Requirements
- PHP 8.1+
- PDO SQLite (`pdo_sqlite`) — current certified database runtime
- cURL
- mbstring
- JSON
- HTTPS
- writable `storage/`

## Deploy
1. Copy the repository to the server with the web root pointed at `public/`.
2. Copy `.env.example` to `.env` and set production values.
3. Set `APP_ENV=production` and an HTTPS `APP_URL`.
4. Configure a long random `APP_KEY`.
5. Configure Stripe secret and webhook signing keys.
6. Run `php scripts/migrate.php`.
7. Open `/setup-admin.php` once and create the first Super Admin. The setup route locks itself after creation.
8. Run `php scripts/preflight.php`; every check must pass.
9. Run `php scripts/release-audit.php`; every required check must pass. Missing image assets are reported as warnings until uploaded.
10. Configure Stripe's webhook endpoint as `/stripe-webhook.php`.
11. Schedule `php scripts/send-notifications.php` every few minutes.
12. Schedule `php scripts/recover-reservations.php` every 5 minutes so abandoned Stripe sessions cannot strand inventory.
13. Set `CHECKOUT_HOLD_MINUTES` between 30 and 120 (30 is the default).
14. Upload storefront images into `public/images/`.
15. Verify `/health.php` returns HTTP 200.
16. Trigger the **Release Package** workflow to generate the deploy ZIP and SHA-256 manifest.

## Canonical image paths
- `public/images/hero.png`
- `public/images/gift-box.png`
- `public/images/footer.png`
- `public/images/flavor-smores.png`
- `public/images/flavor-caramel-pretzel.png`
- `public/images/flavor-cookies-cream.png`
- `public/images/flavor-mint-chocolate.png`

Never expose `.env`, database files, Stripe secrets, or writable storage through the web root.


## Database backups
Schedule this at least daily:

```bash
php scripts/backup-database.php scheduled
```

Backups are stored outside the web root in `storage/backups/`, verified with SQLite `PRAGMA integrity_check`, and accompanied by SHA-256 metadata. Configure `BACKUP_RETENTION_DAYS` and `BACKUP_MAX_FILES`.

## Database restore
Restores are CLI-only:

```bash
php scripts/restore-database.php --file=store-YYYYMMDD-HHMMSS-xxxxxx.sqlite --confirm=RESTORE
```

The restore command creates a pre-restore backup, enables maintenance mode, checkpoints and closes SQLite, validates the staged database, swaps it into place, runs a final integrity check, and only then removes maintenance mode.


## Operations monitoring
Schedule the operational health worker every 5 minutes:

```bash
php scripts/check-operations.php
```

Set `ALERT_EMAIL` to an operations mailbox to receive deduplicated health alerts through the transactional email outbox. `OBSERVABILITY_RETENTION_DAYS` controls resolved-event retention. Runtime errors also fall back to `storage/logs/app.log` if the database is unavailable.

The public `/health.php` endpoint exposes only readiness status, database availability, a request ID, and timestamp. Detailed operational state is available to administrators at `/admin-operations.php`.


## Scheduled worker heartbeat expectations
The production scheduler should match these defaults:

- `send-notifications.php`: every 5 minutes
- `recover-reservations.php`: every 5 minutes
- `check-operations.php`: every 5 minutes
- `backup-database.php scheduled`: every 24 hours

The matching `JOB_*_INTERVAL_MINUTES` values are used for stale-worker detection. Admin → Operations shows last success, failure streaks, active runs and stale jobs. Overlapping copies of the same worker are blocked automatically.


## Safe migrations and release certification
Database migrations are tracked by filename and SHA-256 checksum. Never edit or delete an applied migration; add a new migration instead.

Before deployment:

```bash
php scripts/migrate.php --status
php scripts/migrate.php
```

Production migration runs create a verified pre-migration database backup whenever pending migrations exist and refuse to run concurrently. After activating a release:

```bash
php scripts/post-deploy-check.php
```

The post-deploy check verifies migration state, SQLite integrity, required application files/tables, and operational health, then writes `storage/release-certification.json`.


## Shipping and local pickup
After migrations, configure fulfillment in **Admin → Shipping**. Set active shipping methods, rates, free-shipping thresholds, ETA ranges, local-pickup ZIP codes, pickup location/hours, and customer-facing instructions. Checkout always re-quotes the selected method server-side before payment.


## Session and browser security
Production sessions force the Secure and HttpOnly cookie flags with SameSite=Lax. Configure `ADMIN_SESSION_IDLE_MINUTES`, `ADMIN_SESSION_MAX_HOURS`, `USER_SESSION_IDLE_MINUTES`, and `USER_SESSION_MAX_HOURS` to match the deployment policy.

The application emits CSP, frame-denial, MIME-sniffing, referrer, permissions, cross-origin resource, and HSTS headers in production. Keep `APP_URL` on HTTPS; authenticated responses are marked private/no-store.


## Reverse proxy boundary
Leave `TRUST_PROXY_HEADERS=0` unless TLS is terminated by a trusted reverse proxy that overwrites `X-Forwarded-Proto`. Set it to `1` only in that controlled topology; direct client-supplied forwarded headers are otherwise ignored.


## Tax configuration
Configure Stripe tax behavior in **Admin → Tax**. The store currently certifies exclusive tax only, so Stripe tax is added after the server-calculated pre-tax order total and then reconciled against the Stripe Checkout result.

If Stripe Automatic Tax is enabled, configure the required business tax registrations in Stripe separately. Optionally set a Stripe product tax code such as `txcd_99999999`; leaving it blank uses Stripe's configured default tax treatment.


## Customer support
Set `SUPPORT_EMAIL` to the internal mailbox that should receive new-ticket alerts. Customers submit requests at `/contact.php`; administrators manage the queue at **Admin → Support**. Order-linked tickets verify ownership for signed-in customers and verify the order email for guests.


## Batch fulfillment
Admin → Orders supports atomic batch transitions from **Paid → Preparing** and **Preparing → Ready**. Shipping transitions remain individual so carrier and tracking information can be attached safely.

Fulfillment operators can print per-order packing slips, print the ready local-pickup sheet, and export ready shipping orders as CSV. Customer-controlled CSV fields are neutralized against spreadsheet formula execution.


## Accessibility
The launch-critical storefront flow includes skip navigation, visible keyboard focus, labeled form controls, assistive live regions for the pack builder, 44px interactive targets, and reduced-motion support. Keep these semantics intact when changing templates or shared CSS; Section 50 CI checks the critical regressions.


## Frontend performance
The application explicitly marks session-backed and mutating routes as `private, no-store`. Read-only catalog/content routes such as flavor, story, FAQ, policy and sitemap responses use a short public cache window.

Shared CSS and JavaScript URLs are versioned from the deployed file modification time so browsers can safely retain cached assets across requests while receiving a new URL after a deployment.

At the web-server or CDN layer, enable gzip/Brotli compression for HTML/CSS/JS/JSON and set static image caching independently from dynamic PHP responses. Do not override application `private, no-store` headers on cart, checkout, account, admin, payment, support or other session-backed routes.


## HTTP smoke certification
Before production release, run the real HTTP smoke suite against a migrated environment:

```bash
SMOKE_BASE_URL=https://your-store.example php scripts/http-smoke.php
```

It verifies the homepage, active flavor page, FAQ, pack builder, cart, empty-checkout redirect, support page, first-admin setup behavior, Admin redirect, health endpoint, security headers, and public/private cache boundaries. It does not submit a live Stripe payment.


## Branded error recovery
Customer-facing maintenance, expired-form, missing-product, payment-recovery, permission, and unexpected server failures render safe branded pages instead of raw framework or exception output.

Unexpected production errors include an operational request reference that can be matched in Admin → Operations or the fallback application log. Error responses are marked no-store. Do not replace these handlers with raw exception messages in production.


## Runtime performance
File-backed SQLite runs with WAL journaling, a 5-second busy timeout, NORMAL synchronous mode, an in-memory temp store, and an approximately 20MB page cache. Production preflight verifies the concurrency-critical settings.

The bundled Apache `public/.htaccess` enables compression where `mod_deflate` is available. Versioned CSS/JS/font assets may be cached for one year with `immutable`; storefront images use a seven-day cache window. If a CDN is added, preserve the application cache headers rather than replacing private/no-store responses.


## Search visibility
Public catalog/content pages emit canonical URLs and search-engine-safe metadata. Account, cart, checkout, payment, order-status, admin and internal action routes emit `X-Robots-Tag: noindex, nofollow, noarchive`.

Apache rewrites `/robots.txt` to the dynamic robots policy so the Sitemap directive uses the configured production `APP_URL`. A static `robots.txt` remains as a conservative fallback when rewriting is unavailable. Product structured data must reflect actual sale behavior: individual flavors are described as products available in build-your-own boxes, while purchasable preset boxes include Offer pricing.


## First-party conversion analytics
Anonymous conversion attribution is disabled by default. Set `ANALYTICS_ENABLED=1` only after confirming your published privacy/cookie policy covers first-party analytics storage.

When enabled, the storefront creates a random 128-bit first-party visitor identifier and records only funnel events, page paths, referrer host, UTM campaign fields, and attributed order revenue. It does not store names, email addresses, postal addresses, raw IP addresses, or third-party tracking identifiers.

`ANALYTICS_RETENTION_DAYS` controls event retention. The Operations worker prunes expired anonymous events. Admin → Reports shows the 30-day funnel and attributed acquisition sources.


## Production planning
Admin → Inventory includes a production forecast built from actual paid/fulfilled flavor demand plus current on-hand and reserved stock.

`PRODUCTION_HISTORY_DAYS` controls the default demand lookback and `PRODUCTION_SAFETY_DAYS` adds a configurable safety-stock buffer. Operators can change the forecast horizon interactively and export the current production plan as CSV.


## Cost and margin accounting
Configure flavor unit cost and pack-level packaging cost in **Admin → Costs & Margin**. Paid orders receive an immutable cost snapshot after Stripe payment reconciliation, and the missed-webhook recovery worker creates the same snapshot if it settles the order later.

Gross-margin reporting uses merchandise subtotal after discounts and subtracts flavor + packaging cost. It currently excludes labor, payment processing fees, postage, rent, and other overhead. Fully refunded orders are excluded from the live margin summary while their original snapshots remain available for audit.


## Verified customer reviews
Signed-in customers can review only flavors found in their paid/fulfilled order history. Reviews are pending by default and require approval in **Admin → Reviews** before appearing publicly.

Approved reviews are identity-minimal on the storefront (shown as “Verified customer”). Only approved reviews contribute to public rating averages and Product `aggregateRating` structured data. Editing a review returns it to moderation.


## Buy Again
Signed-in customers can rebuild eligible historical orders from Account → Orders. Reorders never copy historical prices. Every box is rebuilt through the current pack/preset catalog, current flavor eligibility and sold-out state, current surcharges/base prices, and current tracked inventory.

The entire reorder is atomic: if any historical box can no longer be built, no portion of that reorder is added to the live cart. Existing cart contents remain unchanged.


## Gift cards
Digital gift cards are sold at `/gift-cards.php` and delivered only after Stripe's signed checkout webhook confirms payment. Configure available denominations with `GIFT_CARD_AMOUNTS_CENTS` (for example `2500,5000,10000`).

Gift-card codes are generated from cryptographically secure random bytes, stored as a lookup hash plus AES-256-GCM encrypted ciphertext, and are never shown in the Admin card list. `APP_KEY` protects the encrypted code and must never be rotated without a migration/re-encryption plan for existing cards.

At order checkout, gift cards behave as payment tender rather than discounts. Tax is calculated on the full taxable order before stored value is applied. A gift card can cover part or all of the order; any Stripe remainder is reconciled together with the reserved gift-card amount before fulfillment begins.

Refunds return value to the original tenders. The refundable gift-card portion is restored first, and any remainder is sent back through Stripe. Retry keys prevent a repeated refund operation from crediting gift-card value twice. Admin → Gift Cards shows outstanding stored-value liability, reserved value, balances, and enable/disable controls without exposing full codes.


## Stripe disputes and chargebacks
Subscribe the production Stripe webhook endpoint to `charge.dispute.created`, `charge.dispute.updated`, and `charge.dispute.closed` in addition to the existing Checkout events.

Admin → Disputes links chargebacks to orders or gift-card purchases. Open and lost disputes block further fulfillment and manual refunds on affected orders. Disputed gift-card purchases disable the issued stored-value card while the dispute is open or lost and reactivate any remaining balance only when Stripe closes the dispute in the store's favor.

Evidence submission and dispute acceptance remain in Stripe Dashboard; the store records and enforces the operational state locally.


## Customer CRM
Admin → Customers unifies registered customers, guest buyers, support-only contacts, and gift-card purchasers by normalized email identity. Profiles show lifetime order value, refunds, order history, support context, gift-card purchases, internal notes, and service tags.

CRM notes and tags are internal service metadata. They are included in registered-customer privacy exports and automatically purged during account closure. Do not use CRM notes for sensitive profiling or information unrelated to customer service and store operations.


## Checkout recovery
Checkout continuity snapshots contain only the checkout email plus cart/coupon configuration—no shipping address. Signed recovery links are valid for `CHECKOUT_RECOVERY_DAYS` and always rebuild the cart against current catalog availability, pricing, promotions, inventory rules, shipping, and tax.

Schedule:

```bash
php scripts/process-checkout-recovery.php
```

Run it on the cadence configured by `JOB_CHECKOUT_RECOVERY_INTERVAL_MINUTES` (60 minutes by default). A reminder is eligible only after `CHECKOUT_RECOVERY_REMINDER_HOURS` and only when the checkout email is currently explicitly **subscribed** in the marketing-consent ledger. Every recovery reminder contains a signed unsubscribe link. Unsubscribed and unknown addresses are never sent recovery marketing.

Admin → Recovery shows active, restored, converted, expired, and reminder activity. Registered-customer privacy exports include recovery lifecycle metadata, and account closure purges associated recovery snapshots.


## Food compliance
Configure each active flavor in **Admin → Food Compliance** before launch. A profile cannot be published until ingredient statement, allergen statement, storage instructions, shelf life, and net weight are complete. Published flavor pages show that controlled compliance profile; incomplete profiles remain unpublished.

Printable internal product labels are available from the compliance dashboard. The shared-kitchen notice is operational product information and should be reviewed against your actual kitchen processes and applicable food-labeling requirements before production use.


## Production batch traceability
Use **Admin → Batches** to create a production lot for each flavor production run, including production time, best-by date and produced quantity. Assign exact lot quantities to orders while they are **Preparing** or **Ready**. Shipping is blocked when traceability is missing or when an assigned lot is on hold or recalled.

A recall marks the lot unavailable, identifies every assigned order, and queues customer notices through the transactional email outbox. Review the affected-order list and support queue as part of the recall workflow.


## Batch traceability hardening
Section 67 adds safety controls on top of the production-batch workflow:

- Future-dated production lots cannot be created or assigned.
- Expired lots cannot be assigned, released from hold, or shipped.
- Depleted lots cannot be reactivated through the hold workflow.
- Incorrect assignments may be cleared and rebuilt only while an order is Preparing or Ready; consumed quantities are restored atomically.
- Shipped traceability is immutable.
- Recall state is separate from recall messaging. **Queue / retry failed recall notices** safely reuses the existing idempotency key and reopens failed transactional outbox messages.
- The affected-order CSV export uses spreadsheet-formula neutralization.

Migration 038 adds database triggers preventing `quantity_remaining` from exceeding `quantity_produced` without modifying the already-applied Section 66 migration.


## Ingredient and supplier lot traceability
Use **Admin → Ingredients** to record supplier ingredient lots as they are received, including supplier lot code, received date, best-by date, optional received quantity/unit, and internal notes.

Link supplier lots to finished production batches before those batches are used for fulfillment. Linked provenance is enforced end-to-end:

- an ingredient lot received after the finished batch production time cannot be linked;
- when a received quantity is recorded, linked usage cannot exceed that lot quantity and must use the same unit;
- held, recalled, expired, or future-dated ingredient lots block new finished-batch assignment and block shipment of already-assigned orders;
- ingredient provenance becomes immutable once any linked finished batch has shipped;
- missing supplier links remain a non-blocking launch warning during rollout, so historical batches are not retroactively made unshippable.

For a supplier recall, mark the ingredient lot **Recalled**, review the downstream production batches and customer orders, export the affected-order CSV if needed, then use **Queue / retry failed recall notices**. Notification keys are idempotent per ingredient lot/order and failed outbox messages are explicitly reopened for retry.

This traceability workflow supports operations and customer notification; it does not replace any regulatory reporting, recall, or supplier documentation obligations that apply to the business.


## Recipes and bill of materials
Define production formulas in **Admin → Recipes** before creating new production batches. Recipe quantities are stored per finished donut and each new batch snapshots the active recipe version so later formula changes never alter historical traceability.

For BOM-controlled batches, Admin → Batches shows expected vs linked ingredient quantities. **Auto-allocate supplier lots (FEFO)** uses the earliest eligible best-by/received lots first, respects received quantities and units, and refuses partial allocation when enough valid supplier inventory does not exist.

A BOM-controlled batch with missing required ingredient provenance is blocked from downstream order assignment until coverage is complete.


## Supplier purchasing
Manage approved vendors in **Admin → Suppliers** and purchase orders in **Admin → Purchase Orders**. Supplier items define the canonical ingredient name, unit, unit cost, lead time, and minimum order quantity.

Receiving a purchase-order line creates the canonical ingredient lot in the supplier traceability ledger in the same database transaction. Partial receipts are supported, over-receipts are blocked, duplicate supplier lot codes roll back cleanly, and the PO moves through Ordered → Partially Received → Received automatically.


## Ingredient procurement planning
**Admin → Procurement Plan** converts the production forecast into ingredient demand using each flavor's active recipe. It subtracts usable active supplier-lot stock plus draft/ordered PO pipeline quantities, then recommends the lowest-cost active supplier item and respects its minimum order quantity and lead time.

Creating a recommended draft PO recomputes the plan server-side before writing the order, so posted quantities are never trusted. Draft POs count as planned supply to prevent duplicate recommendations until they are cancelled or ordered.


## Production scheduling
Use **Admin → Production Schedule** to turn forecasted prep demand into dated kitchen work orders. Each day has a configurable production-capacity ceiling, with optional date-specific overrides for short shifts, closures, or expanded capacity.

Work orders move **Planned → In Progress → Completed**. Completion atomically creates the canonical production batch and links it back to the work order, preserving recipe/BOM snapshot behavior and downstream traceability. Future work orders cannot be started early, planned work cannot exceed the day's remaining capacity, and overdue open work is surfaced as a release-readiness warning.


## Production QA and waste
Use **Admin → QA & Waste** while a production work order is In Progress. Every required QA check must pass before the work order can be completed into its final traceable production batch.

The required checks currently cover appearance/finish, portion/size, allergen-label verification, and sanitation/handling. Failed checks block completion until corrected and re-signed. Waste events remain linked to the work order and finished flavor, and completed work records planned-vs-actual yield for variance monitoring. Configure the yield warning threshold in the QA console.


## Finished-goods FEFO allocation
Use **Admin → Batch Allocation** for Preparing and Ready orders that do not yet have production-batch traceability. Automatic allocation uses first-expire-first-out: earliest best-by date first, then oldest production time.

The allocator skips held, recalled, expired, future-dated, depleted, or ingredient-unsafe batches. It may split one flavor requirement across multiple safe batches. The final assignment still runs through the canonical batch-assignment transaction, which revalidates exact flavor quantities and remaining inventory before consuming any batch quantity. If the full order cannot be satisfied, no partial assignment is written.


## Finished-goods aging and expiry
Schedule `php scripts/process-finished-goods-aging.php` on the cadence configured by `JOB_FINISHED_GOODS_AGING_INTERVAL_MINUTES` (60 minutes by default).

The worker places expired active production batches on hold and raises operational warnings for expired or near-expiry finished goods. **Admin → Aging** controls the warning horizon and records explicit dispositions for expired, quality, damage, donation, sample, or other removals. Disposition only consumes unassigned remaining batch quantity; assigned order quantities are already outside `quantity_remaining` and remain protected by shipment traceability checks.


## Finished-goods replenishment
Admin → Replenishment calculates a make-next queue from open Paid/Preparing/Ready order demand, recent flavor velocity, configured safety-stock days, active unexpired finished goods, and already planned/in-progress work orders.

Only active, unexpired production batches count as safe stock. Held, recalled, expired, or depleted batches are excluded. Creating a replenishment work order still passes through the production scheduler's capacity controls.


## Finished-goods cycle counts
Use **Admin → Cycle Counts** to reconcile physical finished-goods inventory by production batch. The system records the pre-count quantity, physical quantity, variance reason, operator, and timestamp.

Positive adjustments cannot exceed the batch quantity still legally available after current order assignments and prior dispositions, so cycle counts cannot recreate already-consumed stock. Batches that count to zero are moved to depleted automatically.


## Automatic shelf-life dating and batch labels
Every newly created production batch uses the flavor's **published, complete Food Compliance profile**. If the operator leaves Best By blank, the system derives it from the published shelf-life days. An operator may choose an earlier date, but never a date beyond the published shelf-life limit.

At batch creation, ingredients, allergens, storage instructions, net weight, label version, shelf life, produced date, and best-by date are snapshotted permanently. Later compliance edits do not rewrite historical production labels. Print the immutable label from **Admin → Batches → Print batch label**.
