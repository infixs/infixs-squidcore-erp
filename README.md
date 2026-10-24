# SquidCore - ERP & CRM Platform (WordPress plugin)

This plugin connects WooCommerce to [SquidCore](https://squidcore.com), the ERP & CRM platform by
[Infixs Technology](https://infixs.com). It syncs products and orders, and it issues Brazilian NF-e invoices automatically
after payment.

- The release notes and the full description are in [`readme.txt`](readme.txt).
- WordPress.org slug: `infixs-squidcore-erp`.

## Layout

| Path | What it is |
|---|---|
| `infixs-squidcore-erp.php`, `infixs-squidcore.php`, `uninstall.php` | Plugin bootstrap |
| `src/` | PHP code (AvelPress framework) |
| `assets/` | Compiled admin assets; `assets/dashboard/js/main.js` is the Vite build of the dashboard |
| `resources/src/views/` | Vue 3 + TypeScript source of the dashboard screens |
| `languages/` | Translation template (`.pot`); translations are delivered by WordPress.org language packs |

## Dependencies

- PHP 7.4+, WordPress 6.0+, WooCommerce.
- The PHP dependencies (AvelPress) are installed with Composer from `composer.json`.
- The release build (`avel build`) prefixes their namespaces with `Infixs\SquidCore\`.

## License

GPLv2 or later. See [`LICENSE`](LICENSE).
