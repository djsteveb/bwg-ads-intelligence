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
references `bwg_ai_entityiq_url`), Google/LinkedIn/TikTok ad surface,
compliance vision, PDF export. See `docs/PHASE-2-STATUS.md` → "Known
follow-ups."

---

## Milestone 12 — Google Ads Transparency

**Exit criteria:** Sessions get Google ad results alongside Meta, surfaced
the same way in the gallery (confirm/flag, compliance flags run on copy).

**Open question to resolve at the start of this milestone:** Google's Ads
Transparency Center has no first-party bulk API equivalent to Meta's
`ads_archive` (the "Ads Transparency Insights API" referenced in the old
EntityIQ-era docs was never confirmed generally available). Two paths:

1. **Render-provider abstraction** — a small interface
   (`BWG_AI_Render_Provider`) with one method,
   `render( $url ): array{ screenshot_path, html }`, implemented by whichever
   backend is chosen (a paid screenshot API, e.g. urlbox.io/screenshotone, or
   a self-hosted headless-browser endpoint). Used to capture the Transparency
   Center's per-advertiser results page.
2. If Google ships/confirms a real Transparency API by the time this
   milestone starts, prefer a direct API call (same pattern as M11) and skip
   the render-provider entirely for Google.

Whichever path is chosen, do not silently reintroduce an EntityIQ-shaped
dependency (a job queue + webhook to a service that doesn't exist) — if a
render provider is needed, it must be a real, currently-reachable external
API configured with its own credential in this plugin's settings, following
the M11 pattern (direct call, encrypted-at-rest option, manual fallback when
unconfigured).

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-google-transparency.php` (new) | Fetches Google Transparency results per the resolved approach above. Normalizes into the same ad shape as Meta (`platform = 'google'`). |
| `ads-intel/class-bwg-ai-ad-surface.php` | Extend `run()` to call both Meta and Google clients, merge results. |
| `ads-intel/class-bwg-ai-render-provider.php` (new, only if path 1 is chosen) | Thin interface + one concrete implementation. |

---

## Milestone 13 — Claude Vision Compliance

**Exit criteria:** `wp_bwg_ai_ads.vision_analysis` is populated for ads that
have a usable creative (image or Meta ad snapshot), with HIPAA-focused flags
surfacing in the same gallery UI as the text compliance flags.

**Reference implementation:** port the pattern from BWG-Ads-Acount-Audit's
`class-bwg-maa-vision.php` (separate repo — this plugin does not depend on
it; port the *pattern*, not a live cross-plugin call). Same shape as
`class-bwg-ai-compliance.php`'s text rules: `{ rule_id, severity, category,
excerpt, citation }`, but sourced from a vision model call instead of regex.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-vision.php` (new) | Calls the Claude API (`claude-sonnet-5` or newer) with the ad creative (image URL or Meta ad snapshot) and a HIPAA-focused system prompt. Parses structured flags from the response. |
| `includes/class-bwg-ai-security.php` | `bwg_ai_get_claude_api_key()` — new encrypted-at-rest option, same pattern as the other API keys. |
| `admin/partials/admin-settings.php` | Claude API key field in the API Keys tab. |
| `ads-intel/class-bwg-ai-ad-surface.php` | Call vision analysis after text compliance in `save_ads()`, guarded by whether a key is configured (skip silently, don't block ad saving, if not). |

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
