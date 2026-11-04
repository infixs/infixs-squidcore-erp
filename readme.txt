=== SquidCore - ERP & CRM Platform ===
Contributors: Infixs
Tags: erp, nfe, nota fiscal, invoice, brazil
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to the SquidCore ERP: sync products and orders and issue Brazilian NF-e invoices automatically after payment.

== Description ==

**SquidCore - ERP & CRM Platform** connects your WooCommerce store to [SquidCore](https://squidcore.com), the ERP & CRM platform for Brazilian businesses. Your catalog and your orders reach SquidCore on their own, and the electronic invoice (NF-e) of each paid order is issued by SquidCore and delivered back to the order, to the customer area and to the order emails.

A SquidCore account is required. The plugin itself is free; the features available in SquidCore depend on your SquidCore plan.

= Key Features =

* **One-click connection**: click **Connect with SquidCore**, sign in and authorize the store; the WooCommerce REST API keys SquidCore needs are created for you (a manual connection with an access token is still available)
* **Catalog sync**: products, variations and categories are sent to SquidCore when they are created, updated, trashed or deleted
* **Order sync**: new orders, status changes and confirmed payments are sent to SquidCore, including the Brazilian checkout fields (CPF, CNPJ, number, neighborhood)
* **Automatic NF-e after payment**: when the order is paid, SquidCore issues the NF-e and the plugin keeps the order up to date with its status
* **Manual control**: issue, retry or cancel the NF-e from the order screen whenever you need
* **DANFE and XML in the order**: download the DANFE (PDF), the XML and the cancellation XML from the order screen and from the orders list column
* **DANFE and XML for the customer**: download links in My Account > Orders and in the WooCommerce order emails
* **NCM with AI**: an NCM field on every product, also used by Correios Automático, and SquidCore's AI can fill the missing NCM codes of an order before the invoice is issued
* **Products without NCM at a glance**: the dashboard shows how many products still need an NCM, counted in the background, and the products list gets a **Without NCM** view
* **Import from WebmaniaBR**: bring the NCM codes you already saved in the Nota Fiscal Eletrônica WooCommerce plugin (WebmaniaBR), with a preview before anything is saved and an undo
* **One invoice per order**: orders that already have an NF-e issued by WebmaniaBR do not get a second one automatically
* **Attention notices**: invoices that need your action are flagged in the admin
* **Plan limit at a glance**: the dashboard and the Invoices screen show the invoices of the month against your SquidCore plan; when the limit is reached, one notice tells how many orders are waiting, links to the plans and, after you change plan, issues them all again in the background
* **Correios Automático integration**: the NF-e issued by SquidCore is handed to the Correios Automático plugin, so the Correios pre-posting goes out with the NF-e instead of a content declaration
* **Simplified DANFE on the labels**: with Correios Automático 1.12 or later, the simplified DANFE (DANFE Simplificado - Etiqueta) of the NF-e prints with the shipping labels; the orders list gets a link and a bulk action to print it, and the order screen a button
* **Background processing**: sync and invoice jobs run in the background with automatic retries, so checkout is never slowed down

= Getting Started =

1. Install and activate the plugin
2. Open **SquidCore** in the WordPress admin menu and click **Connect with SquidCore**
3. Sign in to SquidCore, choose the company and authorize the store; you are sent back to WordPress already connected
4. Choose which data to sync and turn on automatic NF-e
5. Done: new orders and products flow to SquidCore and invoices come back to the orders

Stores that cannot use the button (for example a WordPress admin without https) can open **Manual connection (token)** in the connection settings and paste a plugin token generated in SquidCore under Integrations > WooCommerce.

= Source code and build =

The plugin code is public at https://github.com/infixs/infixs-squidcore-erp. The admin screens run on compiled JavaScript (assets/dashboard/js/main.js), built with Vite from Vue 3 and TypeScript; the human-readable source of those screens is in the resources/src/views folder of that repository.

The PHP dependencies in vendor/ (the AvelPress framework) are installed by Composer from the included composer.json; the release build of the AvelPress CLI (`avel build`) prefixes their namespaces with `Infixs\SquidCore\` so they never clash with other plugins.

== External services ==

This plugin relies on external services to work. Nothing is sent before you connect it: while the store is not connected, opening the plugin screens sends nothing, and the first request goes out when you click **Connect with SquidCore** (or save a manual token). The deactivation feedback is sent only if you submit it.

= SquidCore API =

The plugin is a connector for the SquidCore ERP platform, provided by Infixs. It talks to the SquidCore API at https://api.squidcore.com.

* **When you click Connect with SquidCore**: the plugin first reads the public OAuth metadata of the API (https://api.squidcore.com/.well-known/oauth-authorization-server) to check that the connection is offered; as with any request WordPress makes, your site address goes in the user agent. The answer is cached for up to 12 hours. Then your browser is sent to SquidCore (https://api.squidcore.com/v1/oauth/authorize, then the SquidCore sign-in and consent screens), with the plugin's public client id, the address of your WordPress admin to return to, the requested permissions and one-time security codes (state and PKCE). You sign in and authorize there; SquidCore sends your browser back to your WordPress admin.
* **When SquidCore sends you back**: the one-time authorization code is exchanged for an access token at https://api.squidcore.com/v1/oauth/token. The plugin then creates a WooCommerce REST API key (read/write) for your WordPress user and sends it, with your site URL, the store name and the plugin version, to https://api.squidcore.com/v1/integrations/apps/woocommerce/connect, so SquidCore can read and update the store through the WooCommerce REST API. The access token is stored in your WordPress database. If SquidCore refuses the connection, the key is deleted and the token is revoked (https://api.squidcore.com/v1/oauth/revoke).
* **When you connect or test the connection**: the access token (from Connect with SquidCore, or the one you entered in the manual connection) is sent as an authorization header, along with your site URL and the plugin version, to validate the connection and read the name of your SquidCore organization.
* **When products, variations or categories are created, updated, trashed or deleted**: the item data is sent (name, SKU, descriptions, prices, stock, dimensions, weight, attributes, image URLs, categories, tags and NCM), along with your site URL.
* **When orders are created, change status or are paid**: the order data is sent, including the customer's name, company, email, phone, billing and shipping addresses, Brazilian fields (CPF, CNPJ, IE, RG, number, neighborhood, cell phone), order items, totals, shipping, fees, coupons, payment method and customer note, along with your site URL.
* **When an NF-e is issued, checked, cancelled or downloaded**: the order identifier and the requested action are sent, and the invoice status, DANFE (PDF) and XML files are received.
* **When you check the NF-e numbering of a store that used WebmaniaBR**: the Import data tab and the NF-e settings read from SquidCore the NF-e series with their next and last numbers (https://api.squidcore.com/v1/tax-invoice/nfe/numeracoes and https://api.squidcore.com/v1/tax-invoice/nfe/numeracoes/emitidos) and the tax defaults of your company, such as the CFOP and the operation nature (https://api.squidcore.com/v1/tax-invoice/fiscal-settings), so you can continue the numbering where WebmaniaBR stopped. Only the access token goes with these reads: nothing about the WebmaniaBR invoices is sent. The answer is cached for 10 minutes.
* **To show the invoices of the month against your plan**: the dashboard and the Invoices screen read from SquidCore how many NF-e and NFC-e your organization issued this month and the invoice limit of its plan (https://api.squidcore.com/v1/integrations/apps/woocommerce/nfe/usage). The read runs in the background, at most every 10 minutes while the SquidCore pages of the WordPress admin are open and shortly after an invoice is authorized, and right away when you click Refresh. Only the access token goes with it. The answer is cached for up to a day.
* **When an NF-e is authorized and Correios Automático 1.12 or later is active**: the authorized XML of the invoice is downloaded once from https://api.squidcore.com/v1/integrations/apps/woocommerce/orders/{order id}/nfe/xml, and only what the simplified DANFE prints is kept in the order: the access key, the authorization protocol and date, the number, series and issue date, the names, CPF or CNPJ, state registrations and states of the issuer and the recipient, and the total (no address, email or phone). When Correios Automático starts printing the simplified DANFE, the invoices authorized in the last 45 days on orders still to ship are read the same way, once, in the background; a label printed before an invoice was read downloads its XML then. Only the order identifier and the access token go with these downloads. The data is removed when the invoice is cancelled and when the plugin is deleted.
* **When you ask the AI to fill missing NCM codes**: the order identifier and the IDs of the products to classify are sent. SquidCore classifies those products with the AI provider your organization connected in SquidCore (for example OpenAI or Anthropic), under the SquidCore Privacy Policy.

Sync can be turned off in the plugin settings, and disconnecting the plugin stops all calls to the SquidCore API and deletes the WooCommerce REST API key the plugin created. Disconnecting a store connected with Connect with SquidCore also tells SquidCore to revoke the authorization (https://api.squidcore.com/v1/integrations/apps/woocommerce/disconnect). The authorization can also be revoked in SquidCore, under Settings > Connected apps. Deleting the plugin does the same as disconnecting and also removes the plugin settings, with the stored tokens, and the simplified DANFE data kept in the orders.

SquidCore [Terms of Service](https://squidcore.com/en/terms) and [Privacy Policy](https://squidcore.com/en/privacy).

= Infixs deactivation feedback =

When you deactivate the plugin on the Plugins screen, a form asks why. Only the **Submit & deactivate** button sends it: the reason, the optional email and description you typed, your site URL, the PHP version and the plugin version go to the Infixs API (https://api.infixs.io/v1/plugin/deactivate), so Infixs can improve the plugin. **Skip & deactivate**, **I changed my mind** and closing the form send nothing.

Infixs [Privacy Policy](https://infixs.com/privacy-policy/).

= WhatsApp support link =

The help menu and the deactivation form have a **WhatsApp Support** link. The plugin sends nothing: only if you click it, your browser opens WhatsApp (https://api.whatsapp.com) with the Infixs support number and a ready-made greeting, and what you then send goes through WhatsApp.

WhatsApp [Terms of Service](https://www.whatsapp.com/legal/terms-of-service) and [Privacy Policy](https://www.whatsapp.com/legal/privacy-policy).

== Installation ==

= Automatic Installation (Recommended) =

1. Log in to your WordPress admin dashboard
2. Navigate to **Plugins > Add New**
3. Search for "SquidCore ERP"
4. Click **Install Now** and then **Activate**
5. Go to **SquidCore** in your admin menu to connect your account

= Manual Installation =

1. Download the plugin zip file
2. Navigate to **Plugins > Add New > Upload Plugin**
3. Choose the zip file and click **Install Now**
4. Click **Activate Plugin**
5. Go to **SquidCore** in your admin menu to connect your account

= Requirements =

* WooCommerce 8.0 or later (HPOS and the classic order storage are both supported)
* A SquidCore account with access to the WooCommerce integration
* For NF-e: fiscal settings and a digital certificate configured in SquidCore

== Frequently Asked Questions ==

= Do I need a SquidCore account? =

Yes. The plugin is a connector: products, orders and invoices are managed by SquidCore. You can create an account at [squidcore.com](https://squidcore.com).

= Who issues the NF-e? =

SquidCore issues the NF-e with the fiscal settings and certificate of your organization. The plugin sends the order, follows the invoice status and makes the DANFE and XML available in WooCommerce.

= When is the NF-e issued? =

With automatic NF-e turned on, the invoice is requested right after the order is paid. You can also issue, retry or cancel it manually from the order screen.

= Where do my customers find the invoice? =

In **My Account > Orders**, on the order details, and in the WooCommerce order emails sent after the invoice is authorized.

= What happens when a product has no NCM? =

You can type the NCM in the product field, or let SquidCore's AI fill the missing NCM codes of the order before the invoice is issued. The SquidCore dashboard shows how many products still need an NCM, and the **Without NCM** view of the products list shows which ones.

= Which NCM field does SquidCore use? =

The **NCM** field in the Shipping tab of the product; variations use the NCM of their product. It is the only NCM field on the product screen: when Correios Automático is active, SquidCore takes the place of its NCM input and saves the same code for it, so the Correios international shipments keep using it. The Correios Automático NCM is copied to the SquidCore field once, in the background, and so is any change made to it outside SquidCore (a CSV import, or its own field while SquidCore was deactivated). If you deactivate SquidCore, the Correios Automático field shows the same code.

= Can I bring the NCM codes I saved in WebmaniaBR? =

Yes. Open **SquidCore > Settings > Import data**: you see what can be imported, choose whether to use the category NCM, check a preview and import in the background, with an undo. The NCM code in the WebmaniaBR **Informações Fiscais** box keeps working for WebmaniaBR invoices, and its data stays in place.

= Does it work with Correios Automático? =

Yes. When both plugins are active, the NF-e issued by SquidCore is used in the Correios pre-posting instead of a content declaration. With Correios Automático 1.12 or later, the simplified DANFE (DANFE Simplificado - Etiqueta) of the NF-e also prints with the labels, sized to the paper of the print profile; the full DANFE (A4 PDF) stays available in the order.

= Does it slow down my checkout? =

No. Sync and invoice requests run in the background, with automatic retries when the SquidCore API is unavailable.

== Screenshots ==

1. Connecting the store to a SquidCore account
2. Sync and NF-e settings
3. NF-e box on the order screen with DANFE and XML downloads
4. NF-e column in the orders list
5. Invoice downloads in My Account
6. NCM field on the product

== Changelog ==

= 1.5.0 - 2026-11-04 =
* Invoices: notice when the monthly invoice limit of the SquidCore plan is reached, with the plans link
* Invoices: "Issue the pending invoices" sends again, one by one, the invoices the plan limit held
* Invoices: invoices used this month against the plan limit, with a warning from 80%
* Invoices: what to check and what to do when SEFAZ refuses an invoice for its number (duplicate, voided or already used)
* Connection: the panel shows at once a connection that SquidCore no longer accepts
* Orders: Correios Automático tracking codes sent with the order
* Translations: delivered by WordPress.org language packs

= 1.4.0 - 2026-10-21 =
* Invoices: simplified DANFE (DANFE Simplificado – Etiqueta) printed with the Correios Automático labels, separate or embedded in the label
* Invoices: the authorized NF-e XML is read once to keep the simplified DANFE data in the order
* Orders: "DANFE" and "Etiqueta" links in the NF-e column and the "Print simplified DANFE (Correios)" bulk action
* Orders: NF-e status of each order in the Correios Automático orders panel, with the full DANFE and XML links

= 1.3.0 - 2026-10-20 =
* Products: a single NCM field from SquidCore; the NCM field of Correios Automático is hidden and kept in sync
* Import: brings the NCM you already filled in WebmaniaBR (product, variation and category), with preview and undo
* Import: opens once after activation and shows a notice while there is data to bring
* Products: count of products without NCM on the dashboard and a "Without NCM" view in the products list
* Invoices: orders already invoiced by WebmaniaBR are not invoiced again automatically
* Invoices: notices about duplicated automatic emission and the NF-e numbering to continue
* Invoices: button to connect an AI in SquidCore to fill the missing NCM

= 1.2.1 - 2026-10-19 =
* Deactivation: Skip & deactivate button that sends nothing
* Connection: SquidCore is only contacted after you click Connect
* Admin: icons bundled with the plugin
* Invoices: shop managers are told where to find the invoices that need attention
* Uninstall: also removes the scheduled jobs and the remaining options
* Security: escaping fixes in the invoice error page and the plain-text emails

= 1.2.0 - 2026-10-19 =
* Dashboard: health banner with what needs your attention
* Dashboard: three-step onboarding while the store is not connected
* Dashboard: activity chart of sends and invoices per day, with a table view
* Dashboard: today's sends, invoices of the last 30 days and automatic refresh

= 1.1.0 - 2026-10-16 =
* Connection: Connect with SquidCore button (OAuth), which creates the WooCommerce REST API keys for you
* Connection: the access token moved to the Manual connection (token) option
* Disconnect: revokes the authorization in SquidCore and deletes the WooCommerce REST API key of the plugin
* Uninstall: deleting the plugin disconnects the store and removes its settings

= 1.0.0 =
* Connection to a SquidCore account with an access token
* Product, variation and category sync
* Order sync, including the Brazilian checkout fields
* Automatic NF-e issuing after payment
* Issue, retry and cancel the NF-e from the order screen
* DANFE, XML and cancellation XML downloads in the order and in the orders list
* DANFE and XML links in My Account and in the order emails
* NCM field on products and NCM filling with AI
* NF-e handed to Correios Automático for the pre-posting
* Background sync with automatic retries
