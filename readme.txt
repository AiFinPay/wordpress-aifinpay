=== AiFinPay ===
Contributors: aifinpay
Tags: ai, crawlers, paywall, http 402, agents
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI agents read your site and what they would pay. Then charge them per request. Readers stay free.

== Description ==

AI agents and crawlers read your pages. AiFinPay shows you who they are and lets you charge them.

**Shadow mode, the default.** After activation the plugin counts requests from self-identified AI agents (GPTBot, ClaudeBot, PerplexityBot and others) and shows how many requests each operator made. It blocks nothing and sends nothing anywhere. No account is needed.

**Enforce mode.** An AI agent that requests a protected path gets an HTTP 402 with a price. It prepays a small batch of requests through AiFinPay, the payment goes to the payout wallet of your AiFinPay account, and the agent retries with a signed receipt. The plugin verifies the receipt on your server and counts the requests in your database.

**Readers never pay.** A browser and a logged-in user are served as usual. An agent that sends an AiFinPay receipt or agent ID always pays.

= What it cannot do =

* It reads what a client declares. A scraper that sends a browser User-Agent counts as a reader and is served free.
* Files in `wp-content/uploads` are served by your web server without WordPress, so they cannot be protected.
* A protected path covers that URL. If your home page, an archive or a search result shows the full text of a protected post, that text is readable there too. Show excerpts on those pages, or protect them as well.
* Dashboard rules per operator (allow, charge, block) are not applied yet.

== Installation ==

1. Install and activate the plugin. It starts in Shadow mode and observes the whole site.
2. Look at **Settings → AiFinPay** after some traffic has arrived.

To report to your AiFinPay dashboard or to charge:

1. In the dashboard at https://dash.aifinpay.io open your site, then **Settings → API keys → Connect a CMS site**, and copy the code. It works once and expires after ten minutes.
2. Paste it into **Settings → AiFinPay → Connect code** and save.
3. To see agent traffic in the dashboard, tick **Report to AiFinPay**.
4. To charge, list the paths under **Protected paths** and choose **Enforce**.

The plugin accepts Enforce only when AiFinPay approves charging for the connected site: a test site (testnet payments only), or a live site whose domain is verified in the dashboard. Agents are charged only on the host of the site URL set in the dashboard. Both come from AiFinPay, so a change in the WordPress admin cannot turn charging on.

== Frequently Asked Questions ==

= Does it slow my site down or change my page cache? =

In Shadow with the default `/*`, readers are served and cached as before. Agents that a page cache answers are not counted, and the settings page says so if it detects a cache.

A specific path you list is never page-cached, in Shadow and in Enforce, for anyone. A cache keyed by URL would otherwise give an agent the page without paying. That is why `/*` is refused in Enforce: list what you want to charge for.

The plugin works with WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache and Cloudflare APO, and clears them when the mode or the paths change.

= Is my secret safe? =

The API secret is stored in the WordPress database of this site. It is never shown again, logged or sent to a browser. Connecting rotates the secret, so a secret used elsewhere for the same site stops working. Uninstalling deletes it.

= What happens if AiFinPay is unreachable? =

Readers are unaffected. In Enforce the last site record is used for up to a day. After that, and whenever receipt keys cannot be read, AI agents on protected paths get a 503; they never get the page for free.

== External services ==

This plugin connects to the AiFinPay API at `https://api.aifinpay.io`, operated by Aifinpay OÜ. It needs the service to take payments and to show agent traffic in your dashboard. In Shadow without a connection it makes no external request.

* **Connect:** when you save a connect code, the code is sent to `POST /v1/merchants/connect`. The answer is your merchant ID and API secret.
* **Site record:** once connected, the public record of your site is read from `GET /v1/merchants/{id}` when you open the settings page, in Enforce, and in Shadow when an agent presents a receipt, at most every five minutes. It sends no site data.
* **Receipt keys:** once connected, when an agent presents a receipt, the public signing keys are read from `/.well-known/jwks.json` at most every ten minutes, in Shadow too: Shadow checks the receipt and spends nothing. It sends no site data.
* **Resources:** when connected and you save settings with reporting or Enforce on, your protected path patterns and price tier are sent to `POST /v1/merchants/{id}/resources`.
* **Agent requests, only if you tick Report to AiFinPay:** per AI-agent request on a protected path, the operator, agent class, path pattern and time are sent to `POST /v1/merchants/{id}/gate-events`. Never the User-Agent, IP address, full URL or page content. Readers are not reported.

Terms: https://aifinpay.io/terms — Privacy: https://aifinpay.io/privacy

The pages this plugin serves to agents (the HTTP 402 body, `/llms.txt`) name further AiFinPay addresses, for the agent to use. Your server does not contact them.

== Changelog ==

= 0.2.0 =
* Path reading matches @aifinpay/gate 0.10: an escaped `?` or `#`, a `;` parameter or a dot segment that could hide where a page ends is refused for agents in Enforce; readers are served.
* Sends the receipt's content licence with the page it opened, when the site has one.
* The traffic report counts requests and what was charged; it no longer shows an estimate of what agents would have paid.

= 0.1.0 =
* First version: Shadow and Enforce, connect code, local agent traffic report, reporting to the AiFinPay dashboard.
