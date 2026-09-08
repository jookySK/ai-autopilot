=== Jookas AI Autopilot ===
Contributors: jookas
Tags: ai, content generation, seo, articles, automation
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Writes, checks and publishes SEO articles with AI — images, internal links and SEO fields (Yoast, Rank Math, SEOPress, AIOSEO). Any niche.

== Description ==

Jookas AI Autopilot is your content autopilot for WordPress. Every day it:

* picks a topic (your keywords or fresh news headlines from the web),
* writes a full article — outline first, then text, then an AI proofread pass,
* adds relevant images from free stock photo libraries,
* inserts internal links to your products and articles,
* fills SEO fields (Yoast, Rank Math, SEOPress, AIOSEO),
* publishes on your schedule — any hour, any days of the week — as draft or live post.

No more writing by hand, no more empty blogs. Your site stays fresh on autopilot.

**How it works**

1. Paste your API key (Anthropic Claude, OpenAI, Google AI, or OpenRouter — 200+ models with one key).
2. Add your niche keywords, or let the plugin pull current headlines for you.
3. Choose schedule, category, image mode and publish status.
4. Done — Jookas AI Autopilot writes, checks and publishes for you.

**Quality built in**

* Outline → article → AI proofread pipeline, so you get finished text, not raw drafts.
* Quality gate: articles missing internal links are flagged and saved as drafts for your review.
* Images from free stock photo libraries (Pexels / Pixabay / Openverse) — no extra cost per image.
* SEO: meta title, description and keywords pushed to Yoast / Rank Math / SEOPress / AIOSEO.
* Topics can be inspired by what's trending right now (Google News RSS) — any niche, any language.

**Free version (this plugin)**

* Unlimited AI articles per month (fair use), saved as drafts or published live on your schedule
* Any single AI provider of your choice: Anthropic Claude, OpenAI, Google Gemini or OpenRouter
* Images from free stock photo libraries (Pexels / Pixabay / Openverse)
* Schedule by hour and day of week, SEO fields, internal links, article style templates, news-based topics — all included

**More features available in Jookas AI Autopilot Pro (a separate plugin)**

* AI image generation (Gemini Flash Image / GPT Image) instead of stock photos
* Product content from photos (bulk import, with preview & approval)
* Category descriptions and images
* Content series (multi-part article plans)
* AI topic and series suggestions based on SERP research (Serper.dev)
* Google Search Console queries as article topics
* Social publishing via webhooks (Facebook / Instagram / Pinterest), automatic product promotion and AI-written post text

**Privacy**

Your API keys are stored only in your own WordPress database and sent only to the AI provider you choose when generating content. Jookas AI Autopilot does not collect your articles or settings. See [External services](#external-services) below for every connection this plugin makes.

== External services ==

This plugin connects to external APIs only when you run a generation (or press one of its buttons). What each service is, what data goes where and when:

* **Anthropic Claude** — AI text generation (outline, article, proofread) when selected as your provider. Sent: your topic/keywords/site context as prompt text; the generated text comes back to your site. [Terms of service](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy).
* **OpenAI** — AI text generation when selected as your provider (and AI image generation in the Pro plugin). Sent: prompt text; the response comes back to your site. [Terms of use](https://openai.com/policies/terms-of-use), [Privacy policy](https://openai.com/policies/privacy-policy).
* **Google Gemini API** — AI text, vision and (in the Pro plugin) image generation when selected as your provider. Sent: prompt text; the response comes back to your site. [Terms of service](https://ai.google.dev/terms), [Privacy policy](https://policies.google.com/privacy).
* **OpenRouter** — 200+ AI models behind a single key, same data flow as above when selected as your provider. [Terms of service](https://openrouter.ai/terms), [Privacy policy](https://openrouter.ai/privacy).
* **Pexels / Pixabay / Openverse** — free stock photos downloaded into your media library when an article is generated in "stock" image mode (the default). Sent: the search keywords (+ your optional extra phrase) to the photo library API; each downloaded file is stored on your own site. Pexels [terms](https://www.pexels.com/terms-of-service/) and [privacy](https://www.pexels.com/privacy-policy/) · Pixabay [terms](https://pixabay.com/service/terms/) and [privacy](https://pixabay.com/service/privacy/) · Openverse [legal page](https://openverse.org/about/legal/).
* **Google News RSS** — current headlines for your keywords (Topics tab → "News topics"). Sent: your keyword phrases as a search query; only the headline list comes back, nothing else. Google [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **google.com/ping** — sitemap ping to Google after articles are published so new content is found faster. Sent: your sitemap URL, once per publishing run (non-blocking). Google [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **Google Search Console API + OAuth** *(Jookas AI Autopilot Pro)* — pulls real search queries from your site's Search Console account to use as article topics. Sent: OAuth tokens and your property ID; the query list comes back to your site. Google for Developers [terms of service](https://developers.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **Serper.dev** *(Jookas AI Autopilot Pro)* — SERP research behind AI topic and series suggestions. Sent: the search phrases; the result list comes back to your site. [Terms of service](https://serper.dev/terms), [Privacy policy](https://serper.dev/privacy).

== Screenshots ==

= Overview =
Action cards, monthly AI usage and article history in one screen.

= Topics & articles =
The topic queue with statuses, plus AI-suggested topics ready to add with one click.

= Settings =
Schedule, API keys, news keywords and social sharing — everything organized in tabs.

== Installation ==

1. Upload `jookas-ai-autopilot.zip` to the `/wp-content/plugins/` directory, or use *Plugins → Add New → Upload Plugin*.
2. Activate the plugin and complete the short setup wizard (brand, language, API key).
3. Add your niche keywords, pick a schedule and generate your first article — or let it run daily.
4. *(Pro)* Install Jookas AI Autopilot Pro (a separate plugin) to unlock AI images, research tools and social publishing.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Anthropic Claude, OpenAI, Google Gemini and OpenRouter (200+ models behind one key). You can use any of them from the free version — you select one active text provider at a time in Settings.

= Do my API keys leave my server? =

No. Keys are stored in your WordPress database and are sent only to the provider you selected, only when generating content.

= What are the free plan limits? =

There is no hard monthly limit on articles — fair use applies. This version uses stock photos for images; AI image generation and the research/social features live in Jookas AI Autopilot Pro (a separate plugin).

= Does it publish directly or save drafts? =

Both. Pick *Publish*, *Draft* or *Approval required* in the settings. The quality gate also saves articles that fail checks (e.g. missing internal links) as drafts automatically.

= Where do the article topics come from? =

Your keywords, fresh news headlines via Google News RSS (any niche, any language), your own query list — and in Jookas AI Autopilot Pro: Google Search Console queries, SERP research and AI topic suggestions.

= Is my content used to train AI models? =

No. Generation runs through your own API key and your content stays on your site.

= I want the extra features — where do I get Pro? =

Jookas AI Autopilot Pro is a separate plugin (14-day free trial, no credit card required). After installing it you activate your license from its settings screen.

== Changelog ==

= 1.6.2 =

* Topics & Articles queue now paginates (10 rows per page in both sections), so long published-article lists stay fast and readable.

= 1.6.1 =

* Fix PHP parse error on the Topics & Articles tab (stray quote in the delete-topic button row), so the admin page loads again for sites running 1.6.0.

= 1.6.0 =

* Renamed to Jookas AI Autopilot for the WordPress.org directory (new slug: `jookas-ai-autopilot`).
* Trialware rework: Pro features now ship in a separate plugin distribution — nothing is locked behind a license key, trial period or quota inside this build. The free version publishes live on your schedule (no forced drafts).
* All JS/CSS moved from inline blocks to properly enqueued files (plugin-check clean).
* Every external service documented with Terms of Service and Privacy Policy links.
* FAQ fixed: the free plan has no hard article limit.

= 1.5.11 =

* Free/Pro split reworked (wp.org trialware-ready): Free is now unlimited — articles saved as drafts, images from free stock photo libraries; Pro adds AI image generation and automatic live publishing on your schedule.
* Monthly hard limits (5 articles / 6 images) replaced by a fair-use notice in the log.

= 1.5.10 =

* Added plugin-directory screenshots (Overview, Topics & articles, Settings).

= 1.5.9 =

* Pinterest and Instagram images are no longer hard-cropped: the full image is now centered on a blurred, darkened background with a thin white frame, so wide photos keep all their content.

= 1.5.8 =

* Settings now document the three webhook image fields: image (original, Facebook), image_square (1080x1080, Instagram), image_pin (2:3, Pinterest).

= 1.5.7 =

* Fix: the onboarding wizard save handler no longer aborts unrelated admin form submissions ("The link you followed has expired.") — nonce verification now runs only for the wizard's own form.

= 1.5.6 =

* Final plugin-check cleanup: nonce verification moved ahead of all form-data reads in the onboarding wizard.

= 1.5.5 =

* Further input sanitization in the onboarding wizard and settings (WordPress.org plugin-check clean).

= 1.5.4 =

* Added the `License` header required by WordPress.org and fixed the readme metadata (tags, short description, tested up to 7.1).
* Security hardening: output escaping (esc_html / esc_attr / wp_kses_post), unslashed/sanitized request input, `wp_delete_file()` instead of `unlink()`.

= 1.5.3 =

* Added the standard WordPress `readme.txt` (required by wp.org and Freemius for metadata).

= 1.5.2 =

* Fix: leftover "Opt in to make AI Autopilot better" notice for sites with an active Pro license — the notice is now removed and the stale activation flag is cleared.
* Fix: the opt-in link now points to the real settings page instead of an unregistered URL.
* Renamed the main plugin file to `ai-autopilot.php`.

= 1.5.1 =

* Freemius integration (free + Pro plans, 14-day trial).
* Fixed admin menu registration and activation flow.

= 1.5.0 =

* Free/Pro license layer: free plan allows 5 articles and 6 AI images per month, Pro is unlimited.
* Freemius-ready build with gatekeeper for the wp.org free version.

= 1.4.15 =

* 1.4.x series: topics from the web (Google News RSS), OpenRouter support (200+ models), day-of-week scheduling, article style templates, bulk product editing with preview & approval, social publishing via webhooks (Facebook / Instagram / Pinterest), automatic product promotion with multiple daily time slots and AI-written post text.
