# Store MCP for WooCommerce

Manage a WooCommerce store from Claude, or any MCP client, over the Model Context Protocol.

Ask in plain language, for example "which orders are on hold?", "put the Denim Jacket on sale until Friday", "refund one beanie from order 1234 and restock it", and Claude uses this plugin's tools to do it. You stay in control:
- every call is checked against your API key and settings;
- refunds and deletes need your explicit yes;
- everything is logged with personal data masked.

**Version 1.0.0** · WordPress 6.9+ · WooCommerce 9.0+ · PHP 7.4+ · HPOS on or off.

- **New here?** Follow [STAGING.md](STAGING.md): install on a staging copy and connect Claude step by step.
- **Ideas for what to ask:** see [EXAMPLE-PROMPTS.md](EXAMPLE-PROMPTS.md).

---

## How it works

WooCommerce's MCP support is built on the WordPress **Abilities API** (core since 6.9) and the official **MCP Adapter** plugin. The old `/wp-json/woocommerce/mcp` endpoint was removed in WooCommerce 11.3.

This plugin builds on that stack rather than replacing it:

- It registers its tools as WordPress abilities.
- It creates its **own MCP server** through the MCP Adapter, at `https://your-store/wp-json/wcmcp/mcp`.
- That server only accepts **WooCommerce REST API keys issued by this plugin**.

WooCommerce's own abilities and the adapter's default server are left untouched.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.9 |
| WooCommerce | 9.0 (tested with 11.2) |
| PHP | 7.4 |
| MCP Adapter | 0.7.x, from [GitHub releases](https://github.com/WordPress/mcp-adapter/releases), **not WordPress.org** |
| HTTPS | Required, except on `localhost`, `*.localhost` and `*.test` |

## Install

1. Install and activate **WooCommerce**.
2. Download `mcp-adapter.zip` from <https://github.com/WordPress/mcp-adapter/releases> (tested: v0.7.1). Upload it under **Plugins → Add New → Upload Plugin** and activate it. Keep the folder name `mcp-adapter`.
3. Upload `store-mcp-for-woocommerce.zip` the same way and activate it.
4. Open **WooCommerce → Settings → MCP**.

The full walkthrough, including staging checks and going live, is in [STAGING.md](STAGING.md).

## Connect Claude

On **WooCommerce → Settings → MCP → Connection**:

1. **Create an API key.**
   - **Owner:** the key acts as this user and can never do more than they can. A dedicated **Shop Manager** user is safest.
   - **Permissions:** **Read** to look only, **Read/Write** to make changes. *Write*-only keys can't read, matching WooCommerce's REST rules, so they're rarely useful.
2. **Copy the secret immediately.** It is shown once. The configurations below the key already contain it.
3. **Add it to Claude:**
   - **Claude Desktop** (needs Node.js): *Settings → Developer → Edit Config*, merge the JSON shown, then restart Claude. The JSON uses the official `@automattic/mcp-wordpress-remote` proxy. On Windows, if `npx` won't start, use `"command": "cmd"` with `"/c", "npx"` at the start of `args`.
   - **Claude Code**: run the `claude mcp add --transport http …` command shown. It connects directly.
4. **Click Test connection.** It checks the requirements, HTTPS, whether your server passes the login header, and, with a key pasted, a full sign-in, the tool list and a Store info call.
5. Ask Claude: *"What store am I connected to and what can this key do?"*

## Tools

Tools are grouped into **packs** that you switch on and off under **Tool packs**:
- **On by default:** Orders, Products, Customers, Reports.
- **Off by default:** Coupons, Refunds.
- **Always on:** Store info.

### Read tools (Read or Read/Write key)

| Pack | Tool | What it does |
|---|---|---|
| Core | `store-info` | Store name, currency, timezone, units, WooCommerce version, HPOS, enabled packs, what this key can do |
| Orders | `orders-list` | List/search by status, customer, date range or text (number, name, email, address, product). Sort by date, ID or total |
| | `orders-get` | One order: totals, line items, shipping, coupons, addresses, refunds, latest 20 notes |
| Products | `products-list` | List/search by name, SKU, status, type, category, stock status. Sort by date, title, price, popularity |
| | `products-get` | One product or variation: prices, stock, plain-text descriptions, categories, tags, attributes, dimensions, images, first 25 variations |
| | `product-variations-list` | All variations of a variable product |
| Customers | `customers-list` | List/search registered customers by name, email, username, company, phone |
| | `customers-get` | One customer: addresses, order count, total spent, latest order |
| Coupons | `coupons-list`, `coupons-get` | Coupons with type, amount, expiry, limits and usage |
| Reports | `reports-sales` | Orders, items, gross, refunds, tax, shipping, discounts, net sales and average order value, optionally per day, week or month |
| | `reports-top-products` | Best sellers by revenue or quantity, optionally per variation |
| | `reports-top-customers` | Biggest spenders in a period (guests grouped by email) |
| | `reports-low-stock` | Stock-tracked items at or below a threshold, lowest first |

### Write tools (Read/Write key)

| Pack | Tool | What it does |
|---|---|---|
| Orders | `orders-update-status` | Change status, with an optional private note. WooCommerce's normal emails and stock rules apply. Not *refunded*: use refunds |
| | `orders-add-note` | Private note, or customer note (emailed to the customer) |
| Products | `products-create` | Simple or variable product, **draft by default** |
| | `products-update` | Name, status, SKU, prices, sale dates, descriptions, categories, stock (set, or add/remove), weight |
| | `product-variations-create`, `product-variations-update` | Add or change variations, e.g. `{"Size":"XL"}` |
| Customers | `customers-update` | Name, account email, billing/shipping address (merged field by field). Never passwords, usernames or roles |
| Coupons | `coupons-create`, `coupons-update` | Percent, fixed-cart or fixed-product coupons with expiry, limits, spend limits, product and email restrictions. `status: draft` switches a coupon off |

Every write tool returns a change list, `[{ "field", "from", "to" }]`, showing exactly what changed. Only the fields you pass are changed, and an empty string clears optional values such as a sale price or expiry.

### Destructive tools (Read/Write key, confirmation required)

| Pack | Tool | What it does |
|---|---|---|
| Refunds | `refunds-create` | Refund an amount, or specific line items and quantities (amount and tax worked out automatically), optionally restocking them. **Records the refund only**, unless `refund_payment: true` sends money back through a gateway that supports it |
| Coupons | `coupons-delete` | Move a coupon to the trash (restorable) or, with `permanently: true`, delete it for good |

These never act on the first call:

1. **Preview.** Without `confirm`, the tool checks everything and changes nothing. It returns exactly what would happen (amount, items, restock, whether money moves) and a `confirmation_token`.
2. **Your yes.** The tool description tells Claude to show you the preview and ask, and never to confirm on its own.
3. **Confirm.** Only a call with the **same arguments**, `"confirm": true` and the token goes through. The order or coupon is checked again at that moment.

The token is bound to the API key, the tool and the exact arguments:

- **Expiry:** 10 minutes.
- **One use only:** it can't be replayed.
- **Locked to the preview:** changing the amount, switching from trash to permanent delete, or using another key's token is refused.

## Security

### Authentication
- **Only keys issued from this screen work.** Ordinary WooCommerce REST keys, Application Passwords and browser logins are all refused on the MCP endpoint, and the key is ignored on every other route.
- **HTTPS only.** Keys over plain HTTP are refused on public hosts. The `wcmcp_allow_insecure_transport` filter exists for private test servers.
- **Brute-force protection.** After 10 failed sign-ins, the IP address is locked out for 15 minutes.
- **Key owners must stay store managers.** A key whose owner loses `manage_woocommerce` stops working.
- **Secrets are shown once** and never stored by the plugin outside WooCommerce's own keys table.

### Every call is checked
In this order, both before running and again inside the tool:
1. a valid key;
2. the pack is switched on;
3. the key level allows it (**Read keys can never create, update or delete**);
4. the key owner has the WordPress capability.

**Disabled packs and disallowed tools are hidden and refused.** They are removed from the tool list, and refused if a client calls them anyway.

Required capabilities (Shop Managers have them all):

| Area | Capability |
|---|---|
| Orders and refunds | `edit_shop_orders` |
| Products | `edit_products` (`publish_products` to publish) |
| Customers | `list_users`, plus `edit_users` for that customer when updating |
| Coupons | `edit_shop_coupons` (`publish_shop_coupons` to publish, delete permission for that coupon) |
| Reports | `view_woocommerce_reports` |
| Store info | `manage_woocommerce` |

### Data in responses
- **Never returned:** API keys, password hashes, payment transaction IDs or card data, raw order/user meta. Payment info is limited to the method name.
- **Customer tools only see users with the `customer` role**, so admin and staff accounts are never exposed. The `wcmcp_customer_roles` filter changes this.
- **Lists are paginated:** 10 by default, 50 at most. Text is plain and length-limited.
- **Customer-written text is treated as data.** Tool descriptions tell Claude that names, addresses and notes are data, never instructions, in case an order note says "ignore previous instructions".

### Input
- **Validated against a schema.** Unknown arguments are rejected.
- **Values are checked before saving:**
  - prices of 0 or more, and sale below regular;
  - real dates;
  - unique SKUs and coupon codes;
  - existing categories and products;
  - percent coupons no higher than 100;
  - valid countries;
  - an email not used by another account.
- **Text is sanitised:** product descriptions keep basic HTML, with scripts removed.

### Admin screens
- **Access:** require `manage_woocommerce`.
- **Forms:** every action uses a WordPress nonce.
- **Output:** everything shown is escaped.

## Action log

**WooCommerce → Settings → MCP → Logs** records each call: date, tool, key (last 7 characters), owner, result and details.

- **What's logged:**
  - changes, previews, confirmed refunds and deletes, errors, and refused calls;
  - successful read-only lookups only if you switch on *Log read-only calls*.
- **Results:** Success, Preview, Denied or Error.
- **Masking before saving:**
  - emails become `j***@example.com`;
  - phone numbers keep their last two digits;
  - street, postcode, city and state become `[masked]`;
  - emails and phone numbers inside notes are masked too.
- **Never stored:** confirmation tokens and API secrets.
- **Retention:** 1–365 days (default 30). Older entries are deleted daily.
- **Clear logs:** a button that deletes everything (it asks you first).

## Troubleshooting

| Problem | Fix |
|---|---|
| Test: *Authorization header — server removes it* | Apache/LiteSpeed: add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess` above the WordPress block. Nginx + PHP-FPM: `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` |
| Test: *Could not reach its own endpoint* | Your host blocks loopback requests. Test from Claude instead. |
| Test: *Unexpected response* | A security plugin or firewall is filtering `/wp-json/`. Allow `/wp-json/wcmcp/`. |
| *HTTPS* fails behind a proxy or CDN | Make WordPress detect HTTPS. Add the standard `HTTP_X_FORWARDED_PROTO` check to `wp-config.php`. |
| *MCP endpoint not registered* | Check that the MCP Adapter is active, then look in **WooCommerce → Status → Logs** (source `store-mcp`). |
| Claude says a tool doesn't exist | The pack is off, or the key level doesn't allow it (Read keys don't see write tools). |
| *Too many failed authentication attempts* | Wait 15 minutes, and check the key and secret. |
| Claude Desktop shows no tools | Check that Node.js is installed, the JSON is valid, and Claude was fully restarted. On Windows, try the `cmd /c npx` form. |

## Uninstall

- **Deactivate:** the endpoint and log cleanup stop, and settings, keys and logs are kept.
- **Delete:** removes the plugin's options and transients, its log table, its cleanup schedule, and every MCP API key it created. Multisite is supported.

## Development

The project folder (not the zip) contains:

- `.wp-env.json`: WordPress, WooCommerce and MCP Adapter in Docker for local testing.
- `dev/seed-store.php`: sample data: 9 products including a variable hoodie, 5 customers, 40 orders, a refund.
- `dev/build-zip.ps1`: builds the upload zip without tests and development files.

```bash
npx @wordpress/env start
npx @wordpress/env run cli wp eval-file wp-content/wcmcp-dev/seed-store.php
```

Site: http://localhost:8888 (admin / password).

To test both order storage modes:

```bash
npx @wordpress/env run cli wp wc hpos compatibility-mode enable
npx @wordpress/env run cli wp wc hpos disable
npx @wordpress/env run cli wp wc hpos enable
```

### PHPUnit tests

The `tests/` folder holds 32 tests, run inside wp-env's test environment. They cover:
- read-only keys can't write;
- disabled packs are blocked at execution;
- destructive tools do nothing without a valid confirmation;
- token misuse (other arguments, other key, replay, expired, forged);
- bad, revoked and ordinary WooCommerce keys, demoted owners, plain HTTP and rate limiting;
- tool-list filtering, log masking and retention.

```bash
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/store-mcp-for-woocommerce composer install
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/store-mcp-for-woocommerce vendor/bin/phpunit
```

### Code notes

- **Standards:** WordPress coding standards, and everything is prefixed `wcmcp` / `WCMCP_`.
- **WooCommerce CRUD only:** `wc_get_orders`, `WC_Order`, `WC_Product`, `WC_Customer`, `WC_Coupon`, `wc_create_refund`. There is no direct SQL on orders, so it works with HPOS on and off. HPOS compatibility is declared.
- **Direct SQL is limited to:**
  - WooCommerce's API keys table, which has no CRUD class;
  - the plugin's own log table;
  - one product-only stock lookup, because `wc_get_products()` drops custom meta queries.
- **Known limits:**
  - Reports read at most 5,000 orders per call and say so if capped.
  - The MCP Adapter is pre-1.0. If a future release renames its HTTP transport class, the connection test will show "MCP endpoint not registered".

## Licence

GPL-2.0-or-later.
