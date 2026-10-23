# WooCommerce Claude Management Plugin

A WordPress plugin that lets you manage a WooCommerce store from **Claude** over the Model Context Protocol (MCP).

Ask in plain language and Claude handles the rest:

- "Which orders are still processing?"
- "Put the Denim Jacket on sale until Friday."
- "Refund one item from order 1234 and put it back in stock."
- "How did sales go this month, week by week?"

It builds on WooCommerce's native MCP stack: the WordPress **Abilities API** (core since 6.9) and the official **MCP Adapter**. It adds its own key-authenticated MCP server.

## Features

- **25 tools in switchable packs:** orders, products and variations, customers, coupons, refunds, reports, and store info.
- **Sign-in with WooCommerce REST API keys only** (Read, Write or Read/Write). Read-only keys can never change anything.
- **Every call is checked:** a valid key, the pack is switched on, the key level allows it, and the owner has the WordPress permission.
- **Refunds and deletes need your yes.** Claude shows a preview first, and only a confirmation with a single-use token, tied to the exact arguments, goes through.
- **Action log** with emails, phone numbers and addresses masked, a retention setting, and a clear-logs button.
- **Works with HPOS on or off.** Uses WooCommerce CRUD classes only, with no direct SQL on orders.

## Requirements

WordPress 6.9+, WooCommerce 9.0+, PHP 7.4+, HTTPS, and the [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) plugin. The MCP Adapter isn't on WordPress.org.

## Get started

1. **Install:** upload the MCP Adapter zip, then this plugin's zip, under **Plugins → Add New → Upload Plugin**. Build the plugin zip with [`dev/build-zip.ps1`](dev/build-zip.ps1).
2. **Create a key:** **WooCommerce → Settings → MCP**, then click **Test connection**.
3. **Connect Claude:** paste the Claude Code or Claude Desktop configuration shown on that page.

## Documentation

| | |
|---|---|
| [Full README](store-mcp-for-woocommerce/README.md) | Tools, security model, action log, troubleshooting, development |
| [Staging guide](store-mcp-for-woocommerce/STAGING.md) | Step-by-step install on a staging copy and connecting Claude |
| [Example prompts](store-mcp-for-woocommerce/EXAMPLE-PROMPTS.md) | What to ask Claude, by task |

## Repository layout

```
store-mcp-for-woocommerce/   the plugin (this folder goes in the zip)
  tests/                     PHPUnit tests (not shipped)
dev/                         sample-store seed script and zip build script
.wp-env.json                 local WordPress + WooCommerce test site (Docker)
```

## License

GPL-2.0-or-later.
