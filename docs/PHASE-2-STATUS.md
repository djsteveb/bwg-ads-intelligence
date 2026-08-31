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
| M12 | Google Ads Transparency, via a render-provider abstraction (API vs. hosted render — not yet decided) | Next |
| M13 | Claude vision compliance on ad creative — port the pattern from BWG-Ads-Acount-Audit's `class-bwg-maa-vision.php`, HIPAA-focused prompt | Planned |
| M14 | PDF export (browser print-to-PDF, same report source) + 4 remaining audience reports | Planned |
| M15 | LinkedIn/TikTok ad surface — after a ToS review spike | Planned |
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
  the `bwg_ai_poll_entityiq` cron, and the EntityIQ HTTP client
  (`post_to_entityiq()` / `get_from_entityiq()`) in the ad-surface class.
  `bwg_ai_entityiq_url` / `bwg_ai_entityiq_secret` remain — the admin storage
  dashboard (M10) still references them for a hypothetical screenshot service;
  that dashboard is not addressed by M11 and is effectively also dead code
  today. Revisit when/if a screenshot-hosting need actually arises (Meta
  doesn't need one — see `docs/ARCHITECTURE.md` §1).
- **Added:** `ad_snapshot_url` and `source` columns on `wp_bwg_ai_ads`
  (DB version bumped to `1.1.0`). New `POST /manual-ads` REST endpoint.
  Front-end (`ai-form.js`) shows Meta's own hosted ad snapshot link instead
  of a captured screenshot, and offers a paste-URL manual entry form when no
  token is configured.
- **Docs:** `docs/ARCHITECTURE.md` §1 and §5 rewritten to describe the direct
  API-call design; the old EntityIQ webhook/job-queue design is preserved
  there only as a documented historical note.

---

## Known follow-ups (not blocking, not in M11 scope)

- Admin Storage dashboard (`admin/class-bwg-ai-admin.php`, "Storage" settings
  tab) still calls `entityiq_request()` against `bwg_ai_entityiq_url`, which
  is unconfigured on any real install now. It will show connection errors.
  No screenshot storage currently exists to report on (Meta ad surface uses
  hosted snapshot links, not captured screenshots) — the dashboard's scope
  needs to be re-evaluated once M12/M13 land and it's clear whether any
  pipeline actually produces stored screenshots.
- `wp_bwg_ai_sessions.entityiq_job_id` column is now unused dead schema.
  Left in place rather than a destructive migration; safe to drop in a future
  DB version bump once nothing reads it (admin-detail.php no longer displays
  it as of M11).
