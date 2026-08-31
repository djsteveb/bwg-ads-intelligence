# Phase 2 Build Plan — Self-Contained (No EntityIQ Dependency)

## Decision

Everything in the Phase 2 scope tier (`docs/PHASE-2-STATUS.md`) will be built **inside `bwg-ads-intel` itself**, in PHP, calling external commercial APIs directly. We are not extending EntityIQ to absorb this work — its real roadmap is Local SEO / schema / entity intelligence, unrelated in purpose, and forcing ad-surface scraping onto it would be scope creep on someone else's product. See `docs/PHASE-2-STATUS.md`'s "Investigation" section for the full reasoning.

## Important finding that changes the priority order

**The current MVP Meta Ad Library integration is not actually functional.** `class-bwg-ai-ad-surface.php` POSTs to `{entityiq_url}/ads/surface` and polls `{entityiq_url}/ads/surface/{job_id}`, expecting a webhook back — but the real `djsteveb/entityiq` repo has no `routes/ads.js` and no such endpoints at all (its routes are `auth`, `citations`, `config`, `entity`, `gbp`, `intelligence`, `report`, `scan`, `schema`, `serp`, `slides` — nothing ad-related). Today, in any real deployment, `/start` → discovery → confirm always ends with `BWG_AI_Session::log( $session_id, 'entityiq_skip', ... )` or an `entityiq_error` unless someone stands up a matching mock service. **Fixing this — not just adding Phase 2 features — is the top priority**, and it's a rewrite of the same code path rather than new scope.

## Architectural changes (supersedes `docs/ARCHITECTURE.md` §1 and §5 for ad-surface work)

- Drop the async job-queue + HMAC webhook pattern for ad-surface entirely. There's no longer a remote service to hand a job to — pulls run synchronously (small platforms, low volume) or via WP-Cron batches (rate-limited platforms), the same pattern already used for `bwg_ai_run_discovery`.
- Meta Ad Library token, Anthropic API key, and any render-provider API key move from "EntityIQ `.env`" to encrypted WP options on this plugin (same `bwg_ai_encrypt_data()` pattern already used for the other 5 credentials per the security review).
- Screenshot storage moves from "EntityIQ local disk + remote storage-dashboard API" to WP's own uploads directory, outside the web root (already a stated security requirement). The admin storage dashboard becomes local `filesize()`/`glob()` stats instead of remote API calls.

## Per-feature plan

### 1. Meta Ad Library ads — fix MVP (highest priority)

- **Data source:** Meta Graph API `GET /ads_archive` (the "Ad Library API"). Requires a Meta developer app with `ads_read` permission and business verification — same requirement the old plan already assumed, just called directly instead of proxied through EntityIQ.
- **Screenshots:** the Graph API response includes `ad_snapshot_url`, a link to Meta's own hosted rendered snapshot of the ad. Display that directly (iframe/link) in the gallery instead of capturing and storing our own screenshot. This removes the screenshot-rendering problem for Meta entirely — no headless browser needed for this platform.
- **Fallback when no token configured:** rather than a scraper (ToS risk, needs a headless browser we don't want to run in PHP), degrade to a manual-entry mode — the user pastes Ad Library URLs they found themselves, which the plugin parses for the ad ID and stores. Lower-fidelity, but keeps the plugin dependency-free.
- **New file:** `ads-intel/class-bwg-ai-meta-ad-library.php`. Replaces the EntityIQ-calling logic in `class-bwg-ai-ad-surface.php` (that class becomes the orchestrator that calls this + future platform adapters, instead of calling out to EntityIQ).
- **New option:** `bwg_ai_meta_ad_library_token` (encrypted at rest, same pattern as the other 5 credentials).

### 2. Google Ads Transparency Center

- No public REST API. `adstransparency.google.com` is a JS-rendered SPA — scraping it requires executing JavaScript, which plain `wp_remote_get()` can't do.
- **Approach:** a small pluggable **render-provider abstraction** (`interface BWG_AI_Render_Provider` with a `render( $url )` method returning HTML and/or a screenshot image) with the first implementation calling a commercial headless-rendering-as-a-service (e.g. ScrapingBee, Browserless, ScraperAPI — admin picks one and enters an endpoint + API key in settings). PHP only ever does `wp_remote_post()` to that service; it never runs a browser itself, so no Node/Puppeteer/Chromium dependency is added to the WP plugin or its host server.
- **New files:** `ads-intel/class-bwg-ai-render-provider.php` (interface + factory), `ads-intel/class-bwg-ai-google-transparency.php` (adapter using it).
- **New option:** `bwg_ai_render_provider_url` + `bwg_ai_render_provider_key` (encrypted).

### 3. LinkedIn / TikTok ad libraries

- Both have public ad-library web pages, both JS-rendered — same render-provider abstraction as Google, one adapter class each (`class-bwg-ai-linkedin-ads.php`, `class-bwg-ai-tiktok-ads.php`).
- Lower priority than Meta/Google. Before building, check each platform's current ToS on library scraping — do this as a short spike, not assumed.

### 4. Bing / Microsoft ads

- Microsoft has no public ad-transparency library equivalent to Meta/Google/LinkedIn/TikTok's. **Recommend cutting this from scope** rather than building something that can't actually deliver — flag this back to whoever owns the product roadmap (`readme.md` and the PRD both list it as a Phase 2 target; that assumption doesn't hold up).

### 5. Claude vision image compliance

- Call the Anthropic Messages API directly (vision-capable model, image input) via `wp_remote_post()` from PHP — passing each ad's image or Meta snapshot screenshot and the same HIPAA/42 CFR Part 2/outcome-guarantee rules the text engine already checks for, adapted into an image-analysis prompt.
- **Port, don't design from scratch:** `BWG-Ads-Acount-Audit`'s `class-bwg-maa-vision.php` (see "Update" below) already implements this exact plumbing — fetch image via `wp_remote_get()`, enforce a 5MB size cap, validate/default the content-type, base64-encode, POST to `https://api.anthropic.com/v1/messages` with an image content block, parse `content[].text` blocks from the response, and return a structured `{ad_name, image_url, findings}` or `{..., error}` per creative. Reuse that structure; swap only the prompt text (theirs is a design critique — "weak visual hierarchy... missing CTA" — ours needs to ask about HIPAA phrasing, outcome guarantees, and 42 CFR Part 2 patterns visible in the creative, e.g. testimonials, before/after imagery, specific-outcome claims).
- **New file:** `ads-intel/class-bwg-ai-vision-compliance.php`, invoked from the existing `class-bwg-ai-compliance.php` pipeline alongside (not instead of) the text rule engine — `compliance_flags` JSON gains a `source: 'text'|'vision'` field per flag.
- **New option:** `bwg_ai_anthropic_api_key` (encrypted). Per the 1-map-synposises CAPABILITIES.md audit, an OpenAI/LLM-key entry is already flagged suite-wide as "duplicated across 6+ plugins, not yet in the shared-credential resolver" — worth adding this key to that resolver (`bwg_suite_shared_credential_sources()`) when it's built here, rather than creating a 7th independent copy (`bwg-meta-audit` stores its own Anthropic key too — an 8th, if nothing changes). That's a suite-level change in `bwg-auto-mailing-systems`, tracked as a follow-up, not a blocker for this plugin.

### 6. PDF export + 4 additional audience reports

- **Use browser print-to-PDF, not a server-side library.** `BWG-Ads-Acount-Audit`'s v1.0 PDF export is `window.print()` on a print-styled report template (`templates/pdf-report.php`) — an "Export PDF" button using the browser's native print dialog, saving as PDF. Zero dependencies (no Dompdf, no Puppeteer, no Composer package), works on any OS, and is already proven in a sibling plugin rather than an untested new approach. Build a print-optimized CSS variant of the existing report template the same way.
- The 4 additional audience reports (CMO, Compliance/Legal, Agency Internal, Admissions Director) are new PHP/HTML templates (`admin/partials/report-template-{audience}.php`) reusing the existing report data pipeline (`class-bwg-ai-report.php` already assembles the underlying audit dataset for the Executive report) — no new external dependency at all, just template + data-mapping work.

## Update (2026-08-31): a sibling plugin already has working code for two of these

`djsteveb/BWG-Ads-Acount-Audit` (WP plugin slug `bwg-meta-audit`) was listed in the `1-map-synposises` Phase 3 audit as "markdown spec only, no code written yet" — that's now stale. It ships full working code: Phases 1–6, a suite REST layer, and "V2 modules" including creative vision analysis and a landing-page message-match checker. Two pieces are directly relevant and are folded into §5 and §6 above:

- **`includes/class-bwg-maa-vision.php`** — the vision-compliance reference §5 now points to.
- **`includes/class-bwg-maa-pdf.php`** — the browser print-to-PDF approach §6 now uses instead of Dompdf.

Two more pieces are worth knowing about but don't change scope in this pass:
- **`includes/class-bwg-maa-landing-page.php`** — HTTP-fetch a landing page, time the load, and flag missing pixels / slow load / headline mismatch. Directly relevant to the deferred **Phase 6 Landing Page Spider** (not in this pass, but a real reference for whenever that gets built).
- **A live suite REST API** (`bwg-maa/v1/run-audit`, `bwg-maa/v1/audit/{id}`, gated by an `X-BWG-Plugin-Slug` allowlist + `manage_options`) that accepts a Meta Marketing API insights CSV and returns a structured audit. This is **not** a substitute for anything in this plan — it audits an *already-access-granted* ad account's spend/CTR/CPA performance (via the Marketing API), whereas everything in §1–4 above is about *pre-access* public ad discovery (via the Ad Library API, a different Meta API with a different permission model). But it's a real integration option for **Phase 5 (Access Request Funnel)**: once a prospect grants Meta ad account access, `bwg-ads-intel` could hand the resulting CSV to `bwg-meta-audit`'s REST API (soft dependency, `class_exists()`-gated same as the existing `bwg-speed-sitescout` pattern) instead of building a second account-performance audit engine. Flagged as a follow-up, not built in this pass — the discovery-phase work above doesn't depend on it.

### 7. Screenshot storage / admin dashboard

- Screenshots (from the render-provider for Google/LinkedIn/TikTok; none needed for Meta per §1) get stored directly in WP's uploads directory, outside the web root — consistent with the existing file-upload security requirement.
- Admin storage dashboard (already built in M10 for the EntityIQ-backed version) gets rewired to read local disk usage instead of calling a remote EntityIQ storage API — same UI, new implementation.

### 8. Phase 6 (Landing Page Spider) / Phase 7 (Admissions Audit)

- Out of scope for this pass. Once built, Phase 6's landing-page screenshotting can reuse the same render-provider abstraction from §2 rather than inventing a third screenshot mechanism.

## Suggested milestone sequence

| Milestone | Scope | Priority |
|---|---|---|
| M11 | Fix Meta Ad Library — replace EntityIQ job-queue calls in `class-bwg-ai-ad-surface.php` with direct Graph API calls + manual-entry fallback | **Critical — MVP is currently non-functional without this** |
| M12 | Render-provider abstraction + Google Ads Transparency adapter | High |
| M13 | Claude vision compliance — port `bwg-meta-audit`'s `class-bwg-maa-vision.php` pattern with a HIPAA-focused prompt | High (lower effort than originally scoped — proven reference exists) |
| M14 | PDF export via browser print-to-PDF (`bwg-meta-audit`'s approach) + remaining 4 audience reports | Medium (lower effort — no library/dependency work needed) |
| M15 | LinkedIn/TikTok adapters (after a ToS feasibility spike) | Medium |
| — | Bing/Microsoft ads | Cut from scope — no public data source exists |
| — | Phase 5 integration: call `bwg-meta-audit`'s suite REST API for post-access-grant account audits (soft dependency) | Follow-up, not this pass |

## Before starting

Per `CLAUDE.md`: "Do not add new features without a documented spec change." This doc is that spec change for the Phase 2 scope tier; `docs/ARCHITECTURE.md` §1 and §5 should be updated to match once this plan is agreed, since they currently describe the EntityIQ-dependent design this plan replaces.
