# Administrator Assets

Only edit the project-owned assets below. The bundled vendor files should remain
unchanged so updates and bug fixes stay predictable.

## Project-owned JavaScript

| File | Used for |
| --- | --- |
| `delivery-collection.js` | Customer search, location checks, collection forms, invoice rows, uploads, and transaction save requests. |
| `dashboard.js` | Shared dashboard interactions. |
| `CustomerMap.js` | Customer-map interactions. |
| `CustomerTab.js` | Customer tab interactions. |
| `backToTop.js` | Back-to-top control. |
| `notification.js` | Dashboard notification UI. |

## Project-owned CSS

| File | Used for |
| --- | --- |
| `dashboard.css` | Shared admin layout, buttons, forms, tables, and modals. |
| `dashboard_card.css` | Dashboard card layout. |
| `delivery-collection.css` | Delivery, collection, transaction list, detail page, and image modal styles. |
| `CustomerTab.css` | Customer tabs and customer-related views. |
| `Maintenance.css` | Maintenance page styles. |

## Vendor files — do not edit

- `jquery-3.7.1.min.js`
- `leaflet.js`
- `leaflet.css`

## Adding a new page asset

1. Create a clearly named file such as `collection-reports.js` or
   `collection-reports.css`.
2. Add it to `Administrator/Inc/header.php` using the existing `asset()` helper.
3. Keep shared components in `dashboard.css`; keep feature-specific styles in
   the matching feature stylesheet.
4. Keep JavaScript in the order described by `docs/CODE_STYLE.md`.
