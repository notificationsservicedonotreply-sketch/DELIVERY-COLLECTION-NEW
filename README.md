# MARS Delivery & Collection

PHP application (MVC) for delivery and collection transactions, used by
salesmen/riders through a browser and an Android WebView wrapper.

For the history of the MVC migration, the security fixes that came with it,
and the deployment checklist, see [MIGRATION.md](MIGRATION.md).

## Project map

| Folder / file | Purpose |
| --- | --- |
| `bootstrap.php` | Single entry point: env loading, autoload, DB connect, secure session. Every PHP entry file requires this first. |
| `config.php` | Reads DB credentials from the environment (see `.env.example`). Never hardcode secrets here. |
| `app/Core/` | `Database`, `Security` (session/CSRF/access-control helpers), `Router` (page access control + dispatch), `View` (template renderer). |
| `app/Models/` | Database repositories and business rules. SQL belongs here, not in Controllers or Views. |
| `app/Controllers/` | Handle requests: validate input, call a Model, hand data to a View. No SQL here. |
| `app/Views/` | Pure templates. `layouts/` holds the shared header/sidebar/navigation/footer; the rest are one folder per feature area. |
| `Administrator/index.php` | Front controller for the admin area -- delegates to `Router::dispatch()`. |
| `Administrator/assets/` | Browser JavaScript, CSS, and vendor libraries (jQuery, Leaflet). |
| `Ajax/` | Thin shims -- each one just requires `bootstrap.php` and calls the matching Controller method. |
| `index.php`, `Inc/sp-login.php` | Login page entry points. |
| `Uploads/` | User-uploaded files (collection payment attachments, delivery store photos). Not committed to git. |
| `database/` | SQL migrations and test-data scripts. Not auto-run -- apply manually, see each file's header comment. |
| `manifest.json`, `service-worker.js` | PWA install support. |
| `deploy/` | Optional Apache/IIS hardening configs. |

## Delivery and collection module

```text
app/Views/delivery/portal.php or app/Views/collection/portal.php
    -> Administrator/assets/delivery-collection.js
    -> Ajax/ajax_delivery_collection.php (thin shim)
    -> app/Controllers/DeliveryCollectionApiController.php
    -> app/Models/DeliveryCollectionRepository.php
    -> SQL Server tables
```

Transaction history and image viewing follow the same repository:

```text
app/Views/collection/transactions.php
app/Views/collection/transaction_detail.php
    -> app/Controllers/CollectionController.php
    -> app/Models/DeliveryCollectionRepository.php
    -> FileAttachment / CollectionSyntax* tables
```

## Code placement rules

- Views: render HTML from data the Controller already prepared. No queries, no business logic.
- Controllers: read input, check access, call a Model, pass data to a View. No SQL here.
- Models: SQL queries, transactions, and database-related business rules.
- Browser JavaScript: DOM events, user feedback, and AJAX requests only.
- Ajax/*.php files: stay as thin shims (require bootstrap, call one Controller method). Put logic in the Controller, not the shim.
- CSS: keep module styles in the matching module stylesheet; use `dashboard.css` only for shared layout styles.
- Third-party files such as `jquery-3.7.1.min.js` and `leaflet.*` are vendor files. Do not reformat or modify them.

## Formatting rules

- PHP: 4 spaces, opening braces on the same line, one logical statement per line.
- JavaScript: 4 spaces, semicolons, named functions grouped by purpose.
- HTML: one element per line when it contains child elements; keep attributes readable.
- CSS: one selector per block and one declaration per line for new or edited styles.
- Use descriptive names: `collectionTransactionDetail`, `attachmentImages`, `paymentRows`.
