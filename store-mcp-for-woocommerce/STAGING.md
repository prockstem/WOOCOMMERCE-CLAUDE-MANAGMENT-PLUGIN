# Install on a staging copy and connect Claude

Try the plugin on a **staging copy** of your store before using it on the live site. A staging copy is a private clone with the same products, orders and customers, where mistakes don't matter.

Time needed: about 20 minutes.

---

## 1. Get a staging copy

Use whichever your host offers:

- **Managed WordPress hosts** (SiteGround, Kinsta, WP Engine, Cloudways, Hostinger and others) have a *Staging* button in the hosting dashboard. It creates a copy at an address like `staging.yourstore.com`.
- **No staging button?** Use a plugin such as *WP Staging* or *Duplicator* to clone the site to a subdomain.

Then check the copy:

- [ ] It opens over **https://**. The plugin refuses keys over plain HTTP.
- [ ] It isn't sending real emails to customers. Many hosts turn email off on staging; if yours doesn't, install a plugin like *WP Mail SMTP* and turn off delivery, or *Disable Emails*. Status changes and customer notes trigger WooCommerce emails.
- [ ] Payment gateways are in **test/sandbox mode**. The refund tool only sends money through a gateway when you confirm `refund_payment: true`, but staging should never be able to anyway.

## 2. Check versions

In the staging admin go to **Dashboard → Updates** and **WooCommerce → Status**:

- [ ] WordPress **6.9 or newer**. The Abilities API this plugin builds on is part of WordPress 6.9.
- [ ] WooCommerce **9.0 or newer**.
- [ ] PHP **7.4 or newer** (shown under *Server environment*).

## 3. Install the MCP Adapter

The MCP Adapter is WordPress's official bridge between abilities and MCP clients. It is **not in the WordPress.org plugin directory**.

1. Download `mcp-adapter.zip` from <https://github.com/WordPress/mcp-adapter/releases>. Tested with **v0.7.1**.
2. **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then **Activate**.
3. Check that the folder is named `mcp-adapter` (Plugins screen, hover → the edit link shows the folder). WordPress's dependency check needs that name.

## 4. Install Store MCP for WooCommerce

1. Get `store-mcp-for-woocommerce.zip`. Build it with `dev/build-zip.ps1`, which leaves out tests and development files.
2. **Plugins → Add New → Upload Plugin**, upload, **Activate**.
3. If a red notice says something is missing, fix that first. It names exactly what.

## 5. Create an API key

Go to **WooCommerce → Settings → MCP → Connection**.

1. **Recommended:** first create a dedicated user, e.g. `claude-assistant`, with the **Shop Manager** role (**Users → Add New**). Keys act as their owner and can never do more than that user.
2. Under **Create an API key**:
   - *Description*: e.g. "Claude Desktop – staging"
   - *Owner*: the Shop Manager user
   - *Permissions*: start with **Read**. Switch to **Read/Write** once you're happy.
3. **Create API key**. Copy the consumer key and secret now: **the secret is never shown again.**

## 6. Run the connection test

Still on the Connection page:

1. The new key is already filled in under **Test connection**. Click **Test connection**.
2. Every line should say **Pass**. If one doesn't:

| Fails on | Fix |
|---|---|
| HTTPS | Turn on SSL for the staging site in your host's dashboard. |
| Authorization header | Your server strips the login header. Apache/LiteSpeed: add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` near the top of `.htaccess`, above `# BEGIN WordPress`. Nginx: ask your host to add `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. |
| Could not reach its own endpoint | Your host blocks the site calling itself. That's fine; test from Claude in step 7 instead. |
| Unexpected response | A security plugin or firewall (Wordfence, Cloudflare…) is blocking `/wp-json/`. Allow `/wp-json/wcmcp/`. |

## 7. Connect Claude

The Connection page shows ready-made configurations that already contain your key.

### Claude Desktop

1. Install **Node.js** (LTS) from <https://nodejs.org> if you haven't.
2. In Claude Desktop: **Settings → Developer → Edit Config**. This opens `claude_desktop_config.json`.
3. Paste the **Claude Desktop** block from the Connection page. If the file already has `"mcpServers"`, add the `"woocommerce-store"` entry inside it instead of creating a second `"mcpServers"`.
4. **Windows only:** if Claude can't start the server, change `"command": "npx"` to `"command": "cmd"` and put `"/c", "npx",` at the start of the `"args"` list.
5. Quit Claude Desktop completely and reopen it. The tools menu should list **woocommerce-store**.

### Claude Code

Run the **Claude Code** command from the Connection page in a terminal. It connects directly, with no Node.js needed:

```bash
claude mcp add --transport http woocommerce-store https://staging.yourstore.com/wp-json/wcmcp/mcp --header "Authorization: Basic <shown on the page>"
```

Then start `claude` and type `/mcp` to check it is connected.

## 8. First conversation

Ask Claude:

> What store am I connected to and what can this key do?

It should name your store, its currency, and say the key is Read-only. Then try a few read prompts from [EXAMPLE-PROMPTS.md](EXAMPLE-PROMPTS.md).

## 9. Try changes safely

1. Create a second key with **Read/Write** permissions and update Claude's configuration with it. Or edit the old entry and restart Claude.
2. Try a write, e.g. *"Add a private note to order 123: testing MCP"*. Check the order in WooCommerce.
3. To try refunds or coupons, switch the packs on under **WooCommerce → Settings → MCP → Tool packs**. Both are off by default.
4. Ask for a refund and **say no** at the preview. Check nothing happened. Then try again and say yes.
5. Look at **WooCommerce → Settings → MCP → Logs** to see what was recorded.

## 10. Going live

When you're happy on staging:

- [ ] Repeat steps 3–7 on the live site with a **new key**. Keys are not copied between sites by the plugin, and staging keys should stay on staging.
- [ ] Turn on only the packs you need.
- [ ] Prefer a **Read** key for day-to-day questions and a separate **Read/Write** key when you mean to make changes.
- [ ] Revoke keys you no longer use (**Connection → Revoke**).
- [ ] Never paste a key into a shared document, chat or repository.

## Removing it

- **Deactivate**: everything stops working immediately, and settings, keys and logs are kept.
- **Delete** (Plugins screen): removes its settings, the log, and every MCP API key it created.
