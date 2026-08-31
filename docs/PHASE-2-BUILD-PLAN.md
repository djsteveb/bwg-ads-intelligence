# Phase 2 — Build Plan

Ordered milestones for the post-MVP build, all self-contained in this
WordPress plugin (no EntityIQ dependency — see `docs/PHASE-2-STATUS.md` for
why). Each milestone ends with a commit + push, same convention as
`docs/BUILD-PLAN.md`.

---

## Milestone 11 — Fix Meta Ad Library Integration ✅ Complete

**Why:** MVP's `class-bwg-ai-ad-surface.php` posted to an EntityIQ job-queue
endpoint that was never built. Every session got zero ads. This is the
plugin's core value proposition and was silently broken.

**Exit criteria:** A session with a configured Meta Ad Library token gets
real ads back from the Graph API after confirming discovery. A session
without a token is offered a manual-entry form instead of hanging on an
infinite poll.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-meta-ad-library.php` (new) | Calls `GET https://graph.facebook.com/{version}/ads_archive` with `wp_remote_get()`, using the same conventions as `bwg_ai_get_google_places_key()`. Normalizes results (ad copy, `ad_snapshot_url`, run dates, spend range) into this plugin's ad shape. |
| `includes/class-bwg-ai-security.php` | `bwg_ai_get_meta_ad_library_token()` — decrypts the `bwg_ai_meta_ad_library_token` option, same AES-256-CBC pattern as the other secrets. |
| `ads-intel/class-bwg-ai-ad-surface.php` (rewritten) | Orchestrator. `queue_job()` schedules `run()` via WP-Cron (`bwg_ai_run_ad_surface`) instead of posting to a remote job queue. `run()` calls the Meta client and saves results. `save_manual_ads()` handles pasted-URL entries. No webhook, no HMAC, no polling cron. |
| `ads-intel/class-bwg-ai-rest.php` | Removed `/entityiq-webhook`. Added `POST /manual-ads`. `ad-surface-status` now reports `meta_configured` instead of a job ID. `GET /ads/{id}` includes `ad_snapshot_url` and `source`. |
| `ads-intel/assets/ai-form.js` | Ad gallery shows a "View Ad Snapshot" link (Meta's own hosted render) instead of a screenshot. Manual-entry form (paste URL + optional copy) when `meta_configured` is false. |
| `admin/partials/admin-settings.php` | New "Meta Ad Library Token" field in the API Keys tab. |
| `includes/class-bwg-ai-activator.php` | `ad_snapshot_url`, `source` columns on `wp_bwg_ai_ads`. DB version → `1.1.0`. |

**Deliberately out of scope for M11:** the admin Storage dashboard (still
referenced `bwg_ai_entityiq_url` until M12 replaced it), Google/LinkedIn/TikTok
ad surface, compliance vision, PDF export.

---

## Milestone 12 — Google Ads Transparency + Local Screenshot Storage ✅ Complete

**Exit criteria:** Sessions get Google ad results alongside Meta, surfaced
the same way in the gallery (confirm/flag, compliance flags run on copy).
Screenshots produced along the way are stored locally, with an admin
dashboard for usage stats, ZIP backup/export, delete-by-range, and
delete-by-age (manual and automatic).

**Resolved open question:** Google's Ads Transparency Center has no
first-party bulk API equivalent to Meta's `ads_archive` (the "Ads
Transparency Insights API" referenced in the old EntityIQ-era docs was never
confirmed generally available, and still isn't). Built the **render-provider
abstraction** path: a vendor-agnostic screenshot-render API client capturing
the Transparency Center's per-advertiser results page, rather than a direct
API call. Explicitly did **not** reintroduce an EntityIQ-shaped dependency —
the render provider is a real, currently-reachable external API configured
with its own encrypted-at-rest credential in this plugin's settings, same
pattern as M11's Meta token, with a manual-entry fallback when unconfigured.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-render-provider.php` (new) | Vendor-agnostic screenshot-render client — any provider accepting `?url=&access_key=` and returning image bytes (ScreenshotOne, ApiFlash, urlbox.io, etc.). `bwg_ai_screenshot_api_url` / `bwg_ai_screenshot_api_key` settings. |
| `ads-intel/class-bwg-ai-google-transparency.php` (new) | Builds the Transparency Center domain-search URL from the session's website, captures it via the render provider, saves through the screenshot store, returns one screenshot-backed ad record (`platform = 'google'`) explicitly flagged as a full-page capture, not per-ad detail. |
| `ads-intel/class-bwg-ai-screenshot-store.php` (new) | Local disk storage (`wp-content/uploads/bwg-ai-screenshots/`, access-blocked), byte tracking via `wp_bwg_ai_ads.screenshot_bytes`, `stats()`, `prune_range()` / `prune_older_than()`, `export_zip()`. See `docs/ARCHITECTURE.md` §2. |
| `ads-intel/class-bwg-ai-ad-surface.php` | `run()` calls Meta and Google independently (one unconfigured doesn't block the other); `save_manual_ads()` takes a `platform` param. |
| `ads-intel/class-bwg-ai-rest.php` | New signed `GET /screenshot/{id}` streaming endpoint; `ad-surface-status` reports `meta_configured` + `google_configured` separately; `manual-ads` takes `platform`. |
| `admin/class-bwg-ai-admin.php` | Storage dashboard rewritten against the local store — usage bar + 7-day chart, ZIP backup/export (`admin-post.php` handler), delete by date range, delete older than N days, daily-maintenance auto-prune via `bwg_ai_screenshot_retention_days`. Old EntityIQ-backed storage code removed. |
| `ads-intel/assets/ai-form.js` | Manual-entry form gets a platform picker (Meta/Google); ad cards render the signed `screenshot_url` when present. |

**Also removed as part of this milestone:** `bwg_ai_entityiq_url` /
`bwg_ai_entityiq_secret` (options + settings UI + `update_entityiq_job_id()`)
— the admin Storage dashboard was their last consumer.

---

## Milestone 13 — Claude Vision Compliance ✅ Complete

**Exit criteria:** `wp_bwg_ai_ads.vision_analysis` is populated for ads that
have a usable creative (image or Meta ad snapshot), with HIPAA-focused flags
surfacing in the same gallery UI as the text compliance flags.

**Reference implementation note:** BWG-Ads-Acount-Audit's
`class-bwg-maa-vision.php` (separate repo) was not reachable from this
session, so the flag shape was built to match `class-bwg-ai-compliance.php`'s
existing text rules directly — `{ rule_id, severity, category, description,
excerpt, citation }` plus a `source: 'text'|'vision'` tag added to both so
the merged list is unambiguous — rather than attempting to port unseen code.

**SDK decision:** raw `wp_remote_post()` to `api.anthropic.com/v1/messages`,
not the Anthropic PHP SDK — this plugin has no Composer/`vendor/` tree and
every other external call (Meta, Google Places, Turnstile, the M12
screenshot API) is already a plain `wp_remote_*()` call; adding a Composer
dependency just for this one would be inconsistent with the rest of the
plugin and would require site owners to run `composer install`. See
`docs/ARCHITECTURE.md` §6.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-vision.php` (new) | Calls the Claude API (`claude-opus-5`) with the ad creative and a HIPAA-focused system prompt; parses the JSON-array response into flags. Resolves the creative from an already-captured screenshot, `ad_image_url`, or — for Meta — a fresh render-provider capture of `ad_snapshot_url` (reuses M12's `BWG_AI_Render_Provider`, since that's an HTML page not an image). |
| `includes/class-bwg-ai-security.php` | `bwg_ai_get_claude_api_key()` — new encrypted-at-rest option, same pattern as the other API keys. |
| `admin/partials/admin-settings.php` | Claude API key field in the API Keys tab. |
| `ads-intel/class-bwg-ai-ad-surface.php` | Calls vision analysis after text compliance in `save_ads()` for every ad (API-fetched or manual), guarded by `BWG_AI_Vision::is_configured()` — skips silently, never blocks ad saving, if unconfigured. Merges vision flags into `compliance_flags`; persists the full result to `vision_analysis`. |
| `ads-intel/class-bwg-ai-rest.php` | `GET /ads/{id}` exposes a `vision_analyzed` bool per ad. |
| `ads-intel/assets/ai-form.js` | "👁 AI-reviewed" badge on ad cards; 👁 marker on vision-sourced flags. |
| `admin/partials/admin-detail.php` | Per-ad vision status in the session detail view. |

---

## Milestone 14 — PDF Export + Remaining Audience Reports

**Exit criteria:** All 5 audience reports (not just executive) generate from
the same report-data source, and a PDF can be downloaded from any of them.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-report.php` | Extend `generate()` to accept `audience` values beyond `executive` (e.g. `clinical`, `compliance`, `marketing`, `board`) — same underlying data, different framing/template. |
| `admin/partials/report-template.php` | Split into audience-specific partials or add conditional sections keyed by `audience_type`. |
| PDF export | Browser print-to-PDF against the same HTML report template (`window.print()` + `@media print` CSS) — no server-side PDF library, no headless browser dependency. |

---

## Milestone 15 — LinkedIn / TikTok Ad Surface

**Blocked on:** a ToS review spike — neither platform has a public ad
library API equivalent to Meta's. Confirm before building whether scraping
either platform's ad transparency pages is compliant with their terms of
service, and if not, whether a licensed data provider is a viable
alternative. Do not start implementation until that spike concludes.

**Cut:** Bing/Microsoft Ads Transparency — no public data source exists for
it (confirmed during the M11 investigation); not revisiting unless Microsoft
ships one.

---

## Handoff Protocol

Same as `docs/BUILD-PLAN.md` → "Handoff Protocol": commit with
`[M{N}] {milestone name} — {summary}`, push to the milestone's assigned
branch, update `docs/PHASE-2-STATUS.md` and `CLAUDE.md`'s build status after
each milestone.
