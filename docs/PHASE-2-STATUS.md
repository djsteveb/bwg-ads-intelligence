# Phase 2 — Status

Tracks the post-MVP build. MVP milestones (M0–M10 + Security Review) are documented in `docs/BUILD-PLAN.md`.

---

## Why Phase 2 exists

MVP was declared feature-complete on the assumption that a separate Node.js
"EntityIQ" service would handle all ad-surface scraping, screenshots, vision
compliance, and PDF export, called via an async job queue + HMAC-signed
webhook (see the original `docs/ARCHITECTURE.md` §1/§5, before the 2026-08-31
rewrite).

A 2026-08-31 investigation found that side was never built and isn't on
EntityIQ's roadmap (its real focus is unrelated Local SEO/schema tooling).
Concretely: `class-bwg-ai-ad-surface.php` posted to `{entityiq_url}/ads/surface`,
an endpoint that doesn't exist anywhere. Every session that reached Phase 2
Discovery confirm silently got zero ads, forever — the MVP's core value prop
was non-functional.

**Decision:** stop assuming an EntityIQ side exists. Build the remaining
ad-surface / vision / PDF work self-contained in this WordPress plugin, in
PHP, calling external APIs (Meta, Google, Anthropic) directly. No job queue,
no webhook, no shared secret, no headless browser dependency unless a specific
milestone genuinely needs one.

---

## Milestone status

| Milestone | Scope | Status |
|---|---|---|
| M11 | Meta Ad Library — direct Graph API `ads_archive` call, drop EntityIQ job queue/webhook, manual-entry fallback | ✅ Complete (2026-08-31) |
| M12 | Google Ads Transparency (render-provider abstraction) + local screenshot storage subsystem (backup, delete-by-range, delete-by-age) | ✅ Complete (2026-08-31) |
| M13 | Claude vision compliance on ad creative — HIPAA-focused prompt, reuses the M12 render provider for Meta ad snapshots | ✅ Complete (2026-08-31) |
| M14 | PDF export (browser print-to-PDF, same report source) + 4 remaining audience reports | ✅ Complete (2026-08-31) |
| M15 | LinkedIn/TikTok ad surface — after a ToS review spike | Next |
| — | Bing/Microsoft Ads Transparency | Cut — no public data source exists |

Full milestone specs are in `docs/PHASE-2-BUILD-PLAN.md`.

---

## What M11 changed

- **New:** `ads-intel/class-bwg-ai-meta-ad-library.php` — calls
  `GET https://graph.facebook.com/{version}/ads_archive` directly with a
  long-lived `ads_read` token (`bwg_ai_meta_ad_library_token` option,
  encrypted at rest).
- **Rewritten:** `ads-intel/class-bwg-ai-ad-surface.php` is now the
  orchestrator — `queue_job()` schedules `run()` on WP-Cron
  (`bwg_ai_run_ad_surface`), which calls the Meta client synchronously and
  saves results. No async job ID, no polling cron, no webhook.
  `save_manual_ads()` handles the fallback path.
- **Removed:** the `/entityiq-webhook` REST route, `verify_webhook_signature()`,
  and the `bwg_ai_poll_entityiq` cron, and the EntityIQ HTTP client
  (`post_to_entityiq()` / `get_from_entityiq()`) in the ad-surface class.
- **Added:** `ad_snapshot_url` and `source` columns on `wp_bwg_ai_ads`
  (DB version bumped to `1.1.0`). New `POST /manual-ads` REST endpoint.
  Front-end (`ai-form.js`) shows Meta's own hosted ad snapshot link instead
  of a captured screenshot, and offers a paste-URL manual entry form when no
  token is configured.
- **Docs:** `docs/ARCHITECTURE.md` §1 and §5 rewritten to describe the direct
  API-call design; the old EntityIQ webhook/job-queue design is preserved
  there only as a documented historical note.
- **Left over from M11 (fixed in M12):** `bwg_ai_entityiq_url` / `bwg_ai_entityiq_secret`
  remained after M11 because the admin Storage dashboard still referenced
  them for a hypothetical EntityIQ screenshot service. M12 replaced that
  dashboard entirely, so these options, their settings fields, and
  `BWG_AI_Session::update_entityiq_job_id()` were removed outright.

---

## What M12 changed

- **New:** local screenshot storage subsystem
  (`ads-intel/class-bwg-ai-screenshot-store.php`) — files under
  `wp-content/uploads/bwg-ai-screenshots/{date}/{session_id}/`
  (access-blocked via `.htaccess` + `index.php`), served only through a
  short-lived HMAC-signed REST URL (`GET /screenshot/{id}`, 2-hour TTL —
  `bwg_ai_screenshot_url()` in `class-bwg-ai-security.php`). Byte totals
  tracked in `wp_bwg_ai_ads.screenshot_bytes` (new column, DB version
  `1.2.0`) rather than a filesystem walk.
- **New:** `ads-intel/class-bwg-ai-render-provider.php` — vendor-agnostic
  screenshot-render API client (`bwg_ai_screenshot_api_url` /
  `bwg_ai_screenshot_api_key`, Settings → API Keys).
- **New:** `ads-intel/class-bwg-ai-google-transparency.php` — captures the
  Google Ads Transparency Center domain-search page for the advertiser via
  the render provider, saves it through the screenshot store, and returns
  one screenshot-backed ad record. Manual-entry fallback (same pattern as
  Meta) when no render API is configured. `BWG_AI_Ad_Surface::run()` now
  calls Meta and Google independently — one being unconfigured never blocks
  the other; `POST /manual-ads` takes a `platform` param;
  `GET /ad-surface-status/{id}` reports `meta_configured` and
  `google_configured` separately.
- **Rewritten:** the admin Storage dashboard (`class-bwg-ai-admin.php`) —
  disk usage + 7-day chart from `BWG_AI_Screenshot_Store::stats()`; ZIP
  backup/export by date range (`handle_storage_export()`, streamed via
  `admin-post.php`); delete by date range; delete older than N days; daily
  maintenance auto-prunes per the new `bwg_ai_screenshot_retention_days`
  setting (0 = keep indefinitely, the default) instead of the old
  EntityIQ-backed stats/export/delete calls, which are gone.
- **Removed:** `bwg_ai_entityiq_url`, `bwg_ai_entityiq_secret` (options,
  settings UI, and `BWG_AI_Session::update_entityiq_job_id()`) — see the
  M11 "left over" note above.
- **Docs:** `docs/ARCHITECTURE.md` §2 rewritten for local storage; new §5b
  for the Google/render-provider design; Storage Admin Dashboard spec
  updated to match.

---

## What M13 changed

- **New:** `ads-intel/class-bwg-ai-vision.php` — sends each ad's creative to
  the Claude API (`claude-opus-5`, raw `wp_remote_post()` to
  `api.anthropic.com/v1/messages` — no PHP SDK, matching every other
  external API call in this plugin, since there's no Composer/`vendor/`
  tree to add one to) with a HIPAA/42-CFR-Part-2/FTC-focused system prompt,
  parses the JSON-array response into the same flag shape as text
  compliance (tagged `source: 'vision'`).
- Creative resolution order: an already-captured local screenshot (Google
  Transparency) → `ad_image_url` (not currently populated, kept for future
  sources) → for Meta, **reuses the M12 render-provider** to screenshot
  `ad_snapshot_url` (an HTML page, not a raw image) and persists it through
  the same screenshot store so it isn't re-captured later. No creative
  available → that ad's vision step is skipped, never blocks saving it.
- **New setting:** `bwg_ai_claude_api_key` (Settings → API Keys), encrypted
  at rest. Empty key → `BWG_AI_Vision::is_configured()` is false → vision is
  skipped entirely, same silent-fallback pattern as Meta/Google.
- **Wired into** `BWG_AI_Ad_Surface::save_ads()` right after text
  compliance, for every ad regardless of source (API-fetched or manually
  pasted). Vision flags are merged into `compliance_flags` (now every flag
  carries `source: 'text'` or `'vision'`) and the full result is also kept
  separately in `wp_bwg_ai_ads.vision_analysis` (column existed unused since
  M0 — M13 is its first real writer).
- **UI:** ad cards show a "👁 AI-reviewed" badge when vision ran; vision-
  sourced flags get a 👁 marker in the mini flag list; admin session detail
  shows per-ad vision status.
- **Docs:** `docs/ARCHITECTURE.md` new §6.

---

## What M14 changed

- **Extended** `class-bwg-ai-report.php` with `AUDIENCES` (the 5 audience
  keys/labels from `ads-intelligence-prd.md` §6) and `generate_all()`,
  which loops `generate()` once per audience — each gets its own
  `wp_bwg_ai_reports` row and `report_token`. Every audience shares the
  same core computations (risk score, wasted spend, top actions, platform
  snapshot, what's working) and adds one audience-specific `audience_data`
  block: platform mix + attribution gaps + a 90-day roadmap (marketing);
  every unique flag itemized with citations + a remediation checklist
  (compliance); an account map + upsell signals + onboarding checklist
  (agency); channel volume + an explicit note that call-quality data needs
  a not-yet-built call-tracking integration (admissions).
- **`POST /email-report`** now calls `generate_all()` instead of generating
  just the executive report; still emails the executive link (report copy
  updated to mention the other 4 views + PDF download).
- **`GET /report/{token}`** looks up sibling reports for the same
  `session_id` and passes them to the template as `$sibling_reports`, so
  every report page can link to the other generated audience views.
- **One template, all 5 audiences:** `report-template.php` didn't get 5
  separate files — it renders one audience-specific focus card based on
  `$audience`/`$audience_data`, keyed off the same design system as the
  existing executive sections. Added a toolbar (audience switcher +
  "Download PDF" button) and an `@media print` block.
- **PDF export:** `window.print()` against that same HTML page — no
  server-side PDF library, no headless browser. Consistent with this
  plugin's established pattern of calling external APIs directly or not at
  all, never standing up a rendering service.
- **Fixed in passing:** the report-ready email said links expire in 30
  days; the actual `expires_at` has always been +90 days (`generate()`).
  Copy now matches.

---

## Known follow-ups (not blocking)

- `wp_bwg_ai_sessions.entityiq_job_id` column is still unused dead schema
  (harmless — nothing reads or writes it since M11). Left in place rather
  than a destructive migration; safe to drop in a future DB version bump.
- Google Transparency captures are one screenshot per advertiser standing in
  for the whole result set, not per-ad records like Meta. Revisit if a real
  per-ad Google data source becomes available.
- Vision analysis for manually-pasted ads runs synchronously inside
  `POST /manual-ads` (bounded by the existing 25-ad cap). Fine at current
  volumes; move off the request thread if that stops being true.
- The Admissions Performance report is intentionally partial — it has
  channel volume but no call-quality/coaching-gap data, since that needs a
  call-tracking integration (Phase 6 landing-page spider, Phase 7
  admissions/call audit) neither of which is built yet. Revisit once either
  ships.
- All 5 audience reports share the same core sections (risk gauge, wasted
  spend, top actions, platform snapshot, what's working) plus one
  audience-specific focus card, rather than 5 fully bespoke layouts. A
  deliberate scope tradeoff for M14 — revisit if a specific audience needs
  a materially different structure, not just a different focus section.
