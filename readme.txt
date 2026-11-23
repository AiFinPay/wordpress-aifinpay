=== AiFinPay ===
Contributors: aifinpay
Tags: ai, crawlers, paywall, http 402, agents
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI agents read your site and what they would pay. Then charge them per request. Readers stay free.

== Description ==

AI agents and crawlers read your pages. AiFinPay shows you who they are and lets you charge them.

**Shadow mode, the default.** After activation the plugin counts requests from self-identified AI agents (GPTBot, ClaudeBot, PerplexityBot and others) and shows how many requests each operator made. It blocks nothing and sends nothing anywhere. No account is needed.

**Enforce mode.** An AI agent that requests a protected path gets an HTTP 402 with a price. It prepays a small batch of requests through AiFinPay, the payment goes to the payout wallet of your AiFinPay account, and the agent retries with a signed receipt. The plugin verifies the receipt on your server and counts the requests in your database.

**Readers never pay.** A browser and a logged-in user are served as usual. An agent that sends an AiFinPay receipt or agent ID always pays.

**Who reads for free.** Three modes. **AI agents only**, the default: clients that say they are AI agents pay, everyone else reads. To charge scrapers that pretend to be browsers, choose **Strict**: a browser passes a silent Cloudflare Turnstile check once (it may ask a distrusted browser for one click) and reads free for 30 days; Google, Bing, Apple, Yandex and Baidu crawlers verified by their network read free; everyone else gets the price. A browser sees the page at once with the paid part loading after the check. **Everyone pays** charges every request without a receipt, for APIs, catalogs and data feeds. Signed-in users never pay.

**Tell agents what a page is.** The price an agent gets (HTTP 402) can say which page it is paying for: the page title, and the Excerpt you typed for the post. The plugin never takes this text from the post itself, so nothing of what you sell is given away; without an Excerpt only the title is said. Choose **Title and summary** (the default for new sites), **Title** or **Off**. Sites updated from an earlier version start with **Off**.

= What it cannot do =

* With the default setting it reads what a client declares: a scraper that sends a browser User-Agent counts as a reader and is served free. Choose **Strict** to change that.
* Automation in a real browser that passes the check, and a reader cookie copied into a script with the same User-Agent, read free.
* Google's crawler serves both Search and Google's AI answers; a verified Googlebot reads free.
* Search engines are verified by the address WordPress sees. Behind a proxy or CDN that address must be the client's (configure the web server, or the `aifinpay_client_ip` filter); otherwise no search engine is verified and they all pay.
* Files in `wp-content/uploads` are served by your web server without WordPress, so they cannot be protected.
* A protected path covers that URL. If your home page, an archive or a search result shows the full text of a protected post, that text is readable there too. Show excerpts on those pages, or protect them as well.
* Dashboard rules per operator (allow, charge, block) are not applied yet.

== Installation ==

1. Install and activate the plugin. It starts in Shadow mode and observes the whole site.
2. Look at **Settings → AiFinPay** after some traffic has arrived.

To report to your AiFinPay dashboard or to charge:

1. In the dashboard at https://dash.aifinpay.io open your site, then **Profile & keys → API keys → Connect a CMS site**, and copy the code. It works once and expires after ten minutes.
2. Paste it into **Settings → AiFinPay → Connect code** and save.
3. To see agent traffic in the dashboard, tick **Report to AiFinPay**.
4. To charge, list the paths under **Protected paths** and choose **Enforce**.

The plugin accepts Enforce only when AiFinPay approves charging for the connected site: a test site (testnet payments only), or a live site whose domain is verified in the dashboard. Agents are charged only on the host of the site URL set in the dashboard. Both come from AiFinPay, so a change in the WordPress admin cannot turn charging on.

== Frequently Asked Questions ==

= Does it slow my site down or change my page cache? =

In Shadow with the default `/*`, readers are served and cached as before. Agents that a page cache answers are not counted, and the settings page says so if it detects a cache.

A specific path you list is never page-cached, in Shadow and in Enforce, for anyone. A cache keyed by URL would otherwise give an agent the page without paying. That is why `/*` is refused in Enforce: list what you want to charge for.

The plugin works with WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache and Cloudflare APO, and clears them when the mode or the paths change.

= Can each path have its own price? =

Yes. Under **Protected paths**, add `standard`, `complex` or `premium` after a path, for example `/reports/* premium`. A path without one costs the **Price per request** you chose for the site. When two lines cover the same page, the more specific one sets the price: an exact path before a `/prefix/*`, a longer prefix before a shorter one. **Price of each path** on the settings page shows what each one costs.

In Enforce the REST API and the feed show the same posts as your pages, so they cost what your highest-priced page path costs.

An agent prepays at least $0.02 at a time. The batch is counted in units of $0.0005, so the same $0.02 pays for 40 Standard, 10 Complex or 4 Premium requests: a batch bought at a lower price never pays a dearer request at its own price.

= What do I need for "Strict"? =

A Cloudflare account (the free plan is enough). Create a Turnstile widget for your site's domain in managed mode, then paste its site key and secret key under **Who reads for free**. The plugin generates its own reader key. Until both keys are saved the setting acts as **Everyone pays**: protected paths are never free by mistake, and the settings page says so.

When a page shows its paid part in a way the plugin cannot hold back (a theme that prints the post text itself, or a page builder), the visitor gets a short page with the price instead, and the whole page after the check.

= Is my secret safe? =

The API secret, the Turnstile secret key and the reader key are stored in the WordPress database of this site. They are never shown again, logged or sent to a browser. Connecting rotates the secret, so a secret used elsewhere for the same site stops working. Uninstalling deletes it.

= What happens if AiFinPay is unreachable? =

Readers are unaffected. In Enforce the last site record is used for up to a day. After that, and whenever receipt keys cannot be read, AI agents on protected paths get a 503; they never get the page for free.

== External services ==

This plugin connects to the AiFinPay API at `https://api.aifinpay.io`, operated by Aifinpay OÜ. It needs the service to take payments and to show agent traffic in your dashboard. In Shadow without a connection it makes no external request.

* **Connect:** when you save a connect code, the code is sent to `POST /v1/merchants/connect`. The answer is your merchant ID and API secret.
* **Site record:** once connected, the public record of your site is read from `GET /v1/merchants/{id}` when you open the settings page, in Enforce, and in Shadow when an agent presents a receipt, at most every five minutes. It sends no site data.
* **Receipt keys:** once connected, when an agent presents a receipt, the public signing keys are read from `/.well-known/jwks.json` at most every ten minutes, in Shadow too: Shadow checks the receipt and spends nothing. It sends no site data.
* **Resources:** when connected and you save settings with reporting or Enforce on, your protected path patterns, each with its price tier, are sent to `POST /v1/merchants/{id}/resources`.
* **Cloudflare Turnstile, only with "Strict":** on protected pages and, for visitors who have not passed it yet, on other pages, the visitor's browser loads `https://challenges.cloudflare.com/turnstile/v0/api.js`, operated by Cloudflare, Inc. When the check passes, this site sends the check token, your secret key and the visitor's IP address to `https://challenges.cloudflare.com/turnstile/v0/siteverify`. Terms: https://www.cloudflare.com/website-terms/ — Privacy: https://www.cloudflare.com/privacypolicy/
* **Receipt use:** once connected, in Enforce, every five minutes the id of each receipt spent on your site and the units it has left are sent to `POST /v1/merchants/{id}/receipt-use`, so AiFinPay shows the same balance as your site. Never the receipt itself, the agent's request or anything about readers.
* **Agent requests, only if you tick Report to AiFinPay:** per AI-agent request on a protected path, the operator, agent class, path pattern and time are sent to `POST /v1/merchants/{id}/gate-events`. Never the User-Agent, IP address, full URL or page content. Readers are not reported.

Terms: https://aifinpay.io/terms — Privacy: https://aifinpay.io/privacy

The pages this plugin serves to agents (the HTTP 402 body, `/llms.txt`) name further AiFinPay addresses, for the agent to use. Your server does not contact them.

== Changelog ==

= 0.6.0 =
* AiFinPay, its dashboard and the paying agent now see how much of a receipt your site has used. After every paid read the plugin keeps the receipt's id and the units left, the lowest per receipt, and sends them to AiFinPay every five minutes while the site is connected. Never the receipt itself, and nothing about readers.
* If AiFinPay does not take them yet, they are dropped and sent again after the next paid read; a page is never slowed or refused because of it. Agent request reporting is unchanged.

= 0.5.0 =
* The three choices of **Who reads for free** are now named **AI agents only**, **Strict** and **Everyone pays**. What a site has saved is unchanged.
* New setting **Tell agents what a page is**: the HTTP 402 of a single post or page can carry its title and the Excerpt you typed for it, never text taken from the post. Default **Title and summary** for new sites, **Off** for sites updated from an earlier version. The price, the payment and the receipt do not change.
* Developers: the filter `aifinpay_about` supplies the title and summary for any request, archives and the REST API included.

= 0.4.0 =
* New setting **Who reads for free**. The default keeps what the plugin did before: AI agents that name themselves pay, everyone else reads. **Readers who passed a check** lets through browsers that passed a Cloudflare Turnstile check, verified search engines and signed-in users, and gives everyone else the price; a browser gets the page with the paid part loading after the check. **Everyone pays** charges every request without a receipt.
* With either new choice, a paid post's text is held back from the excerpt, feeds, the REST API and other pages too, and a page that still shows it is replaced by a short page with the price.

= 0.3.0 =
* Each protected path can have its own price: add standard, complex or premium after it. The settings page shows what each path costs.
* The HTTP 402 no longer carries `protocol_fee_bps`. The fee is set per merchant and stated by the quote, as in @aifinpay/gate 0.11.0.
* The connect code help names the dashboard page it is on: Profile & keys → API keys → Connect a CMS site.

= 0.2.0 =
* Path reading matches @aifinpay/gate 0.10: an escaped `?` or `#`, a `;` parameter or a dot segment that could hide where a page ends is refused for agents in Enforce; readers are served.
* Sends the receipt's content licence with the page it opened, when the site has one.
* The traffic report counts requests and what was charged; it no longer shows an estimate of what agents would have paid.

= 0.1.0 =
* First version: Shadow and Enforce, connect code, local agent traffic report, reporting to the AiFinPay dashboard.
