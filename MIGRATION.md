# MVC / Security Migration Guide

This describes the restructure from the flat file layout to MVC, and the
security fixes applied alongside it. Read this fully before deploying.

## Do these three things first, before deploying anything

1. **Rotate the SQL Server credentials.** The old `config.php` had
   `test12345` / `test12345` hardcoded as a fallback, and this repository is
   public on GitHub -- treat that password (and the `DESKTOP-R71VG0E`
   hostname) as compromised. Create a new SQL login and update your
   environment variables (below) to use it.
2. **Remove or rotate the committed upload files.** `Uploads/Collection/...`
   contains 21 real payment/split attachment images (receipts, checks,
   etc.) that were committed to the public repo. These are real business
   records. Scrub them from git history (see below), and treat any
   sensitive account/reference numbers visible in those images as exposed.
3. **Make the repository private, or scrub history.** The repo currently
   has only 2 commits ("Add files via upload"), so history is small and
   easy to clean:
   ```
   # Easiest: since history is tiny, a fresh repo is simplest.
   # Delete the GitHub repo and re-push a clean copy, OR:
   git filter-repo --path Uploads --invert-paths
   git filter-repo --path config.php --invert-paths   # old version only
   ```
   Either way, do this *before* re-publishing, and rotate the DB password
   regardless (deleting history doesn't un-leak a password that was already
   public).

## What changed structurally

The app is now organized as:

```
bootstrap.php          <- single entry point (env, autoload, DB, session)
config.php              <- reads DB credentials from environment only
app/Core/                <- Database, Security, Router, View
app/Models/               <- DeliveryCollectionRepository, UserManagementRepository,
                             UserAccessModel (all business/data logic)
app/Controllers/           <- one per feature area; handle requests, call Models,
                             render Views
app/Views/                  <- pure templates, no queries or business logic
```

**Every previous URL still works exactly as before** -- `index.php`,
`Administrator/index.php?page=...`, and every `Ajax/ajax_*.php` endpoint are
kept in place as thin compatibility shims that just call into the new
Controllers. Nothing bookmarked, cached, or hardcoded in the mobile app
wrapper should break. The old `ClassModule/*.php` files are kept too, as
deprecated aliases pointing at the new classes, in case anything outside
this repo still references them directly.

The only externally-visible change is the `?page=` value: it used to be a
symmetrically-encrypted blob (`?page=Xk3f...`), it's now a plain string
(`?page=Delivery-Portal`). This is intentional -- see "Why the encryption
was removed" below -- and it's non-breaking because the app always
generates its own links; nothing hardcodes the old encrypted value. An old
bookmarked `?page=<ciphertext>` link will simply fall back to the
Dashboard, which is exactly what happened before too when a `$page` value
didn't match any `case`.

## Security fixes included

1. **No more hardcoded secrets.** `config.php` now reads
   `DB1_SERVER`/`DB1_NAME`/`DB1_USER`/`DB1_PASS` from the environment and
   fails loudly (500, generic message, logged detail) if they're missing --
   it will never again silently fall back to a real-looking default
   password. See `.env.example`.
2. **Centralized module-access control.** Previously, `delivery_portal.php`
   and `collection_portal.php` checked *login* but not the specific module
   grant (unlike `user_management.php` and the transactions pages, which
   did). Any authenticated user could open those two pages directly by
   URL even without the module enabled for their account. Access control
   now happens in exactly one place -- `Router::dispatch()` -- for every
   route, driven by the route table in `app/Core/Router.php`.
3. **Direct-file-access guards.** The old page files
   (`Administrator/delivery_portal.php`, etc.) are now 403-guarded stubs.
   Previously, hitting them directly bypassed the router (and therefore
   bypassed the module-access check above) since PHP will execute any
   `.php` file a webserver is asked for. All real page logic now lives in
   `app/Controllers` + `app/Views`, which never render unless
   `APP_BOOTSTRAPPED` is defined by `bootstrap.php`.
4. **Removed the `EncryptionClassModule` round-trip for login and page
   routing.** It encrypted the userID/password/page-name using a key
   hardcoded in source, then decrypted it in the same request -- this added
   no real protection (HTTPS already protects data on the wire; the
   "encryption" was trivially reversible by anyone with the source, which
   is now public) and was also the root cause of a pre-existing bug: two
   different files used two different hardcoded keys for the same
   mechanism. It's gone from every call site. The class itself is kept as
   an inert, clearly-marked-deprecated file in case something outside this
   repo still references it.
5. **Durable brute-force lockout.** Login throttling was session-based, so
   it reset whenever an attacker cleared cookies (this was called out in
   the original code's own comments as a known weakness). There's now an
   optional `LoginAttempts` table (`database/login_attempts.sql`) keyed by
   a hash of (IP + attempted userID), which survives across sessions and
   devices. If you don't run that migration, it degrades gracefully to the
   original session-based counter -- nothing breaks either way, but running
   it is recommended.
6. **Upload directory permissions tightened** from `0777` to `0755`.
7. **Baseline HTTP hardening** added in `bootstrap.php`: HTTPS enforcement
   in production (toggle via `APP_FORCE_HTTPS`), `X-Content-Type-Options`,
   `X-Frame-Options`, `Referrer-Policy`.
8. **A real path bug was caught and fixed during the move**: an internal
   upload-path calculation in the collection repository used
   `dirname(__DIR__)` to find the repo root, which was correct at its old
   location (`ClassModule/`, one directory deep) but would have resolved to
   the wrong directory once the file moved to `app/Models/` (two
   directories deep). It now uses the `APP_ROOT` constant instead. The same
   class of bug was caught and fixed in a login-redirect path during this
   migration too.

## Performance ("fast reaction") changes

1. **Per-page JS loading.** Every admin page used to load all 8 shared
   script bundles (map, delivery-collection, dashboard, user-management,
   etc.) regardless of whether that page used them. `app/Views/layouts/footer.php`
   now loads only what each Controller declares it needs.
2. **`defer` on all script tags.** Safe here because every script already
   waits for `DOMContentLoaded` internally, and inline `<script>` blocks
   that set page config (e.g. `window.collectionCategories`) run before any
   deferred script regardless of tag order, since inline scripts execute
   immediately at parse time and deferred scripts always run after the
   document is fully parsed.
3. **Extracted the ~230-line inline attachment-viewer script** out of
   `collection_transaction_details.php` into
   `Administrator/assets/attachment-viewer.js`, so it's now cacheable and
   only loaded on the one page that uses it, instead of being re-sent as
   part of the HTML on every visit to that page.
4. **Fixed a latent cache-busting bug**: the original `asset()` helper
   computed each CSS/JS file's last-modified time against the wrong base
   directory for everything under `Administrator/assets/`, so the `?v=`
   cache-busting query string silently never appeared on those files (the
   browser still loaded them fine via the relative URL, it just never got
   a fresh `?v=` on deploy). A second helper, `adminAsset()`, fixes the
   path calculation so cache-busting actually works for those files now.
5. The existing per-request memoization in `DeliveryCollectionRepository`
   (radius, location-lock, customer-unlock caches) was left as-is -- it was
   already a solid pattern from earlier bug-fix rounds.

## Environment variables (new requirement)

Copy `.env.example` to `.env` and fill in real values, **or** set these as
real environment variables at the OS/IIS/Apache level (recommended for
production):

```
DB1_SERVER=...
DB1_NAME=SyntaxDatabase
DB1_USER=...
DB1_PASS=...
APP_ENV=production
APP_FORCE_HTTPS=1
```

`.env` is already excluded via `.gitignore` -- never commit it.

## Multi-branch customer/invoice search (DATABASENAME)

Customer and invoice search in both portals is now scoped to the signed-in
user's own `DATABASENAME` (their branch/company partition within this one
database), read from `UserList.DATABASENAME` at login.

**Important caveat:** I could only directly confirm `UserList.DATABASENAME`
and `InvoiceList.DATABASENAME` exist (the latter was already being selected,
unused, in the existing invoice search query). I could not confirm whether
`Customers.DATABASENAME` exists, so that one is checked at runtime via
`INFORMATION_SCHEMA.COLUMNS` before it's ever used in a query -- if the
column isn't there, customer search silently falls back to unscoped
behavior (same as today) instead of throwing a fatal SQL error. Once you
confirm `Customers.DATABASENAME` exists (or add it), scoping will start
applying automatically the next time each user logs in -- no code change
needed.

This does **not** currently scope `saveCollection()`'s own internal
eligible-invoice check, or `collectionInvoiceExists()` (the "already
collected" check) -- both were left as-is since scoping the save path
wasn't part of what was asked, and changing it carries more risk. Ask if
you want that extended too.

## Delivery Transactions (new)

A "Delivery Transactions" history page was added, alongside the existing
Collection Transactions -- same idea, but for delivered/not-received
invoices instead of saved collections. It reuses the same access-control
pattern:

- `Delivery-Transactions` module: a rider sees only their own resolved
  deliveries (matched against their own USERID).
- `Delivery-Transactions-Admin` module: sees every rider's delivery history.

Like every other module in this app, these are fail-closed -- nobody sees
the sidebar link or the page until granted. **Grant these from the User
Management page** (Add/Edit user \u2192 "Delivery transactions" /
"Delivery transactions administrator" checkboxes) -- no SQL needed. If you
ever need to grant it directly against the database instead:

```sql
INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES ('<userid>', 'Delivery-Transactions', 1);
-- or, for someone who should see every rider's history:
INSERT INTO UserAccess (USERID, MODULE, ACCESS) VALUES ('<userid>', 'Delivery-Transactions-Admin', 1);
```

## Deployment checklist

- [ ] Rotate DB credentials (see above)
- [ ] Set environment variables (`.env` locally, or real env vars in prod)
- [ ] Run `database/login_attempts.sql` (optional but recommended)
- [ ] If Apache: copy `deploy/.htaccess.example` to `.htaccess` at the repo root
- [ ] If IIS: merge `deploy/web.config.example` into your `web.config`
- [ ] Confirm `Uploads/` is writable by the web server process
- [ ] Scrub committed upload images from git history, or start a fresh repo
- [ ] Smoke-test: login, both portals, transactions list + detail, user
      management add/edit/delete, file upload on a collection
- [ ] Set the repo to private, or confirm it should stay public knowingly
