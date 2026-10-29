=== Jookas AI Autopilot ===
Contributors: jookas
Tags: ai, content generation, seo, articles, automation
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.14
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

**What's included**

* Unlimited AI articles per month (fair use), saved as drafts or published live on your schedule
* Any single AI provider of your choice: Anthropic Claude, OpenAI, Google Gemini or OpenRouter
* Images from free stock photo libraries (Pexels / Pixabay / Openverse) — or AI image generation (Gemini Flash Image / GPT Image) with your own API key instead of stock photos
* Local PC image generation via Stability Matrix + ComfyUI over your LAN — zero cloud cost per image, new in 1.9.1
* Content series (multi-part article plans)
* Social publishing via webhooks (Facebook / Instagram / Pinterest), automatic product promotion and AI-written post text (your own Make.com / Buffer / Zapier webhook)
* Product content from photos (bulk import, with preview & approval) — new in 1.7.0
* Category descriptions and images — new in 1.7.0
* Google Search Console queries as article topics — new in 1.7.0
* AI topic and series suggestions based on SERP research (Serper.dev) — new in 1.7.0
* Schedule by hour and day of week, SEO fields, internal links, article style templates, news-based topics

**Privacy**

Your API keys are stored only in your own WordPress database and sent only to the AI provider you choose when generating content. Jookas AI Autopilot does not collect your articles or settings. See [External services](#external-services) below for every connection this plugin makes.

== External services ==

This plugin connects to external APIs only when you run a generation (or press one of its buttons). What each service is, what data goes where and when:

* **Anthropic Claude** — AI text generation (outline, article, proofread) when selected as your provider. Sent: your topic/keywords/site context as prompt text; the generated text comes back to your site. [Terms of service](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy).
* **OpenAI** — AI text and image generation when selected as your provider. Sent: prompt text; the response comes back to your site. [Terms of use](https://openai.com/policies/terms-of-use), [Privacy policy](https://openai.com/policies/privacy-policy).
* **Google Gemini API** — AI text, vision and image generation when selected as your provider. Sent: prompt text; the response comes back to your site. [Terms of service](https://ai.google.dev/terms), [Privacy policy](https://policies.google.com/privacy).
* **OpenRouter** — 200+ AI models behind a single key, same data flow as above when selected as your provider. [Terms of service](https://openrouter.ai/terms), [Privacy policy](https://openrouter.ai/privacy).
* **Pexels / Pixabay / Openverse** — free stock photos downloaded into your media library when an article is generated in "stock" image mode (the default). Sent: the search keywords (+ your optional extra phrase) to the photo library API; each downloaded file is stored on your own site. Pexels [terms](https://www.pexels.com/terms-of-service/) and [privacy](https://www.pexels.com/privacy-policy/) · Pixabay [terms](https://pixabay.com/service/terms/) and [privacy](https://pixabay.com/service/privacy/) · Openverse [terms of service](https://docs.openverse.org/terms_of_service.html).
* **Google News RSS** — current headlines for your keywords (Topics tab → "News topics"). Sent: your keyword phrases as a search query; only the headline list comes back, nothing else. Google [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **google.com/ping** — sitemap ping to Google after articles are published so new content is found faster. Sent: your sitemap URL, once per publishing run (non-blocking). Google [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **IndexNow** — direct "new URL available" notification to search engines (Bing, Yandex, Seznam and others) after each published article. Sent: your site's host name, a one-time generated key (served back from your own site at /key.txt for verification) and the new post URL. [IndexNow protocol documentation](https://www.indexnow.org/documentation).
* **Google Search Console API + OAuth** — pulls real search queries from your site's Search Console account to use as article topics (connect it in Settings → SEO & connections). Sent: OAuth tokens and your property ID; the query list comes back to your site. Google for Developers [terms of service](https://developers.google.com/terms) and [privacy policy](https://policies.google.com/privacy).
* **Serper.dev** — SERP research behind AI topic and series suggestions (optional; add your Serper.dev key in Settings). Sent: the search phrases; the result list comes back to your site. [Terms of service](https://serper.dev/terms), [Privacy policy](https://serper.dev/privacy).
* **Your own PC (Stability Matrix / ComfyUI)** — only when you pick "Local PC" image mode: article images are generated on your own computer over your local network via the ComfyUI HTTP API. Sent: prompt text + generation parameters to your machine; the finished image comes back to your site. No third-party service is involved.

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
4. *(Optional)* Connect Google Search Console and/or a Serper.dev key in Settings for extra topic sources — all features are included.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Anthropic Claude, OpenAI, Google Gemini and OpenRouter (200+ models behind one key). You can use any of them from the free version — you select one active text provider at a time in Settings.

= Do my API keys leave my server? =

No. Keys are stored in your WordPress database and are sent only to the provider you selected, only when generating content.

= What are the free plan limits? =

There are no hard monthly limits — the usage counters are fair-use reminders only, they never block generation. All features work out of the box: articles save as drafts or publish live on your schedule, images come from free stock photo libraries or are generated with AI using your own API key, social webhooks go to your own Make.com / Buffer / Zapier endpoints, and product-from-photo analysis, category descriptions plus the search-console/SERP research tools are all included.

= Does it publish directly or save drafts? =

Both. Pick *Publish*, *Draft* or *Approval required* in the settings. The quality gate also saves articles that fail checks (e.g. missing internal links) as drafts automatically.

= Where do the article topics come from? =

Your keywords, fresh news headlines via Google News RSS (any niche, any language), your own query list, Google Search Console queries, SERP research and AI topic/series suggestions — all included.

= Is my content used to train AI models? =

No. Generation runs through your own API key and your content stays on your site.

= Is there still a Pro version? =

Since 1.7.0 everything is in this one plugin — Freemius and the separate Pro build are retired. If you had a Pro subscription, every feature stays free for you; you can cancel it at freemius.com without losing anything.

== Changelog ==

= 1.9.14 =
* Fixed - article text containing prompt/instruction material when using Gemma 4 on ComfyUI (TextGenerate node): thinking mode was not explicitly disabled, so the model wrote its internal reasoning straight into the answer — proofread steps leaked "Role: Slovak language corrector…" style notes between paragraphs and re-printed the whole article. The workflow now sends thinking=false to TextGenerate, drops any separately returned reasoning output (logged), and proofreading falls back to the original text when the corrected version comes back much longer than the input. Proofread token budget raised from 4000 to 8000 so long articles are no longer cut off mid-sentence.
* Fixed - weaker models (Gemma 4 and similar) copying the formatting examples verbatim into the article body ("…" ellipses in list items, tip boxes containing only "…"): format placeholders now read like instructions and every sentence must be finished — no ellipsis filler allowed.

= 1.9.13 =
* Fixed - article generation failing with "Odpoveď AI sa nepodarilo naparsovať na článok" when a thinking model (Qwen3 on LM Studio / Ollama) spent its entire token budget on internal reasoning and returned no ===TITLE===/===CONTENT=== markers: the article text budget is raised from 8000 to 11000 tokens, /no_think is now also appended to the system message, one automatic retry runs when no markers are found at all, the outline step keeps only H2 lines so model thinking can't poison the article prompt, and proofread keeps the original text if it returns plain prose instead of HTML.

= 1.9.12 =
* Fixed - topic generation with Qwen3 models on LM Studio / Ollama still ran out of tokens: llama.cpp-based servers silently ignore the "thinking off" switch (chat_template_kwargs), so the model spent ~4000+ tokens thinking and the JSON answer got cut off. The plugin now appends the official /no_think soft switch to the prompt for Qwen3 models, raises the topic token budget from 4096 to 6144, and the JSON parser can now recover a truncated topic list (it scans all bracket positions instead of only the first one).

= 1.9.11 =
* Fixed - local PC text generation with "thinking" models (Qwen3 and similar on LM Studio / Ollama) failed with the confusing error "Local PC text: HTTP 200": every token went into internal reasoning, so the answer field came back empty. The plugin now disables thinking mode where supported, falls back to the model's reasoning output when it carries the whole answer, raises the topic-generation token budget from 2000 to 4096, and shows a clear error message instead of "HTTP 200".

= 1.9.10 =
* Fixed – "Load models from server" dropdowns showed newly loaded model names as invisible (blank / white on white); the options are now rendered with visible labels.

= 1.9.9 =
* Changed – local model fields (image / text / ComfyUI text) are now real dropdown menus; "Load models from server" fills them reliably instead of an unreliable autocomplete list. Previously saved custom names stay in the list.

= 1.9.8 =
* Improved – "Load models from server" errors are now shown visibly under the button when your local PC cannot be reached (previously only an icon appeared), so firewall or connection problems are easy to diagnose.
= 1.9.7 =
* Fixed – topic/series suggestions are no longer lost when a local model answers with a numbered or bulleted list instead of JSON; the parser now recovers both, plus single-key objects like “topics” wrapping an array.
* Improved – parse failures log a preview (head + tail) of what the AI actually wrote, so the cause is visible in Recent runs without extra debugging.
* Updated – topic and series prompts now state explicitly that the first character must be [ and the last one ].

= 1.9.6 =
* New – the model dropdown next to the AI provider now loads real models for local providers: with “Local PC (ComfyUI)” it offers Auto plus every text encoder on your ComfyUI server, and with “Local PC (Ollama / LM Studio)” it adds your installed models. Picking one fills the corresponding model field automatically.
* Improved – JSON parsing of AI responses is now fault-tolerant: topic/series suggestions are recovered even when a local model wraps the array in extra text or gets cut off at the token limit, instead of failing with “could not parse”.

= 1.9.5 =
* Fixed – ComfyUI text generation now reads the model's output correctly across ComfyUI versions (accepts generated_text, result or text node outputs instead of one fixed key); when nothing readable is found, the error message lists what outputs ComfyUI actually returned, so diagnosis is immediate.
* Improved – automatic text-model pick for local ComfyUI now skips LTX / projection variants (e.g. gemma4-12b-with-proj-ltx) that are built for video conditioning, and falls back to a regular text LLM when one is available.

= 1.9.4 =
* New – live job status in the admin screen: while an article is being generated, a notice at the top of the plugin page shows the current step (topics / outline / writing text / images) and refreshes every 10 s; for local ComfyUI runs it also shows how long the model has been working — no more silent fanspinning with nothing in Debug.
* Improved – Recent runs now gets a "Text ready in N s" timing entry after each article's text generation, so you can see at a glance whether generation was fast this time.
* Fixed – monthly fair-use reminders are logged at most once per calendar month instead of on every run (leftovers from the Free/Pro split that were burying useful log entries). Regenerating an article's text also no longer computes its outline twice.

= 1.9.3 =
* Fixed – “Local PC” and “Local PC (ComfyUI)” AI text providers no longer require a cloud API key — they run over your LAN, so only the server URL is needed (the pre-generation check wrongly asked for a provider key that local modes don't have).
* Improved – automatic model pick for ComfyUI text generation now also matches Qwen 3.x files from your server's list (e.g. qwen3_5_*); Gemma 4 stays preferred when present, and you can always choose any model explicitly in the dropdown.

= 1.9.2 =
* New – text generation directly through ComfyUI: a new "Local PC (ComfyUI)" AI provider writes articles with a text model running inside your own ComfyUI (e.g. Gemma 4) — the same machine as image generation, no separate Ollama/LM Studio needed. The plugin builds the official Comfy-Org text-generation graph (CLIPLoader → TextGenerate → PreviewAny); the model file is picked automatically from ComfyUI's text encoders or chosen via one-click server list; if the URL field stays empty it reuses the local PC address from the image settings.
* Fixed – ComfyUI text generation now sends sampling parameters in the API format (sampling_mode.*) and waits for prompt completion correctly.

= 1.9.1 =
* New – local PC image generation: a new "Local PC (Stability Matrix / ComfyUI)" image mode generates article images on your own computer over LAN via the ComfyUI HTTP API — no cloud image API, zero cost per image. Settings include server URL, a model picker (checkpoints + FLUX 3 diffusion models such as FLUX.3 Klein — auto-detected) with one-click server model list, negative prompt, steps/CFG and output size; if the local machine is offline the article is saved without an image plus a log entry.
* New – Testing tab: generate a single test image (any supported image mode) with a custom prompt directly from the admin screen — no article needed, nothing saved to the media library.
* Improved – regenerating an existing article's image (the "AI image" row/bulk action or the button on the article preview page) now derives a fresh English image prompt from the article content via AI instead of reusing the original subject.
* New – local PC text generation: a new "Local PC (Ollama / LM Studio)" AI provider writes articles with a model running on your own computer over LAN via an OpenAI-compatible endpoint — zero cloud API cost for text, with one-click server model list.

= 1.8.0 =
* New – article preview with per-section regeneration: open any generated article from the queue (eye icon) or from the "Preview" submenu, and rewrite individual H2 sections without touching the rest of the text.
* New – failure e-mail: when a daily run fails, an e-mail with the error message lands in your admin inbox (same address as approval links), so nothing breaks silently.
* Improved – AI prompts now include current date and season context for more timely topics and text.
* Housekeeping – leftover Freemius SDK files removed from the package; PHP syntax lint added to the release pipeline.

= 1.7.0 =
* New – all previously Pro features are now included in this one plugin: product content from photos (bulk import with preview & approval), category descriptions and images, Google Search Console queries as article topics, AI topic and series suggestions based on SERP research.
* Changed – Freemius removed — no more account connection or license activation. Existing Pro users keep every feature free; you can cancel your subscription at freemius.com without losing anything.

= 1.6.13 =
* Fixed – model dropdown labels, the OpenRouter provider option and field captions in Settings now follow the interface language (EN/SK); they were hardcoded Slovak.

= 1.6.12 =
* Updated Freemius SDK to the latest version.

= 1.6.11 =
* New - current-generation AI models across all providers: Claude Sonnet 5 (recommended), Claude Fable 5, Claude Opus 5; GPT-5.6 Terra (recommended) / GPT-6 Astra / Sol / Luna; Gemini Flash latest + 3.8 Flash; OpenRouter presets refreshed (Claude Sonnet 5, GPT-6 Astra). Fresh installs now default to Claude Sonnet 5 — older models stay reachable via "Custom model…".
* New - AI image generators: GPT Image 2.5 Flare (fast & cheap) and Sunburst (quality), plus Gemini 3 Pro Image; Nano Banana 2 (Gemini 3.1 Flash Image) is kept as a fast alternative. Retired Imagen / legacy presets migrate automatically to the current generation, provider failover now uses the new models, and per-image price estimates were updated.
* Fixed - Debug tab: the empty "Last outline" state now follows the interface language (it was hardcoded Slovak).

= 1.6.10 =
* Fixed - empty model dropdown in Settings and onboarding wizard for all AI providers: the JavaScript config was printed after the script ran, so no models were listed. Recommended models per provider are offered again, plus "Custom model…".
* Fixed - the "Suggest keywords from my site" button (news topics) works again; same root cause.
* Fix - background progress bar now shows while jobs run, and saving Settings keeps you on the Settings tab instead of jumping back to Overview.
* Changed - News keywords and AI image style start empty with placeholder hints instead of prefilled example text (existing installs keep their saved values); "Style:" is added to AI image prompts only when a style is set.

= 1.6.9 =
* Fixed - "Recent runs" log messages now follow the selected interface language (English or Slovak); previously they were always Slovak. No functional changes.

= 1.6.8 =
* Free build is now fully trialware-compliant for the WordPress.org directory: AI image generation (via your own API key), content series and social webhooks are fully enabled in this plugin; product-from-photo analysis and category descriptions moved to a separate module that ships only with Jookas AI Autopilot Pro. Monthly usage counters are advisory fair-use reminders — they never block generation.
* Fixed two dead links in the readme: IndexNow protocol documentation now points to https://www.indexnow.org/documentation and Openverse to its current terms of service page (https://docs.openverse.org/terms_of_service.html).
* Plugin-check cleanup: documented direct option-name lookup behind the usage history table (phpcs ignores for DirectDatabaseQuery with justification) — no UI change.

= 1.6.6 =
* New - relevance-based product links: an article only links products whose name actually matches the topic (new "Include product links" toggle, on by default). When nothing matches, the CTA button points to your contact page or homepage instead of a random product.
* New - topic queue deduplication: near-duplicate topics (same long prefix or 2+ shared content words) are skipped for AI-generated, series and news-based topics alike.
* Changed - "Extra search words" setting is now clearly optional; leave it empty so stock photos follow the article topic only.

= 1.6.5 =
* New - usage history: a toggle under the monthly usage card lists every recorded month (articles, AI images, products, categories, estimated cost) plus an all-time total row

= 1.6.4 =
* Fix - plugin check "OutputNotEscaped" notices in the Topics & Articles queue pagination (defensive wp_kses_post on already-escaped rows); no UI change

= 1.6.3 =

* Freemius is now documented in the readme as an external service (what data is sent and when), with terms of service and privacy links.
* Fix for the Freemius "Upgrade" menu item on sites that have a trial plan configured — no more redirect loop; it opens the pricing page directly in trial mode.

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
