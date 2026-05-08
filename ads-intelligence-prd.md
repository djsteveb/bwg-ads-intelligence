# Ads Intelligence System — Technical PRD

**Product:** BWG Ads Intelligence System  
**Owner:** Better Web Group / Treatment Center Agency  
**Status:** Pre-build — architecture & planning  
**Related:** `ads-intelligence-system.html` (rendered product blueprint)

---

## 1. Product Summary

A URL-entry audit platform that auto-discovers a treatment center's ad footprint across 6+ platforms, runs HIPAA and platform-policy compliance analysis, walks the prospect through granting access, and delivers 5 audience-specific reports. The audit is the top of a service ladder from free lead gen to $10k/mo managed services.

**7 Phases:**
1. Discovery Engine — business profile, GBP, social, pixels, tech stack, WHOIS
2. Ad Library Surface — Meta, Google, LinkedIn, TikTok, Bing (public transparency tools)
3. Compliance Analysis — HIPAA, platform policy, AI image review
4. Verification — screenshot gallery, shadow account discovery
5. Access Request Funnel — per-platform instructions, templates, email drip
6. Landing Page Spider — CWV, pixel audit, form checks, message match
7. Journey & Admissions Audit — call quality, CRM, attribution (Phase 2 feature)

---

## 2. Architecture

### 2.1 Two-Tier Design

**Tier 1 — WordPress Plugin (`bwg-ads-intel`)**
- User-facing multi-step form (shortcode)
- Session persistence, access codes, resume tokens
- Email sequences (drip, follow-up, access request templates)
- Admin: list, detail, settings, audit log
- Report portal (5 audience views, public token URLs)
- Fires async REST jobs to Tier 2; receives webhooks on completion

**Tier 2 — EntityIQ Extension (Node.js)**
- Ad library data acquisition (Meta Ad Library API, Google Transparency API, scrapers)
- Playwright/Puppeteer screenshot capture + archiving
- Claude vision API calls for image compliance analysis
- Puppeteer-based PDF report generation (per audience)
- Calls back to WP via webhook on job completion

### 2.2 Data Acquisition Strategy

| Source | Primary Method | Fallback |
|---|---|---|
| Meta Ad Library | Official API (rate-limited) | Puppeteer scraper |
| Google Ads Transparency | Transparency API | Scraper |
| LinkedIn Ad Library | Scraper (no public API) | Manual export instructions |
| TikTok Creative Center | Scraper | Manual export instructions |
| Bing / Microsoft Ads | SERP scrape by brand term | — |
| Client ad account data | Direct access grant | CSV/platform export upload |
| Landing pages | `BWG_SA_Scraper` / custom spider | — |
| GBP / business data | Google Places API | `BWG_SA_Module_GBP` |
| Tech stack / pixels | DOM parse + Wappalyzer patterns | — |
| WHOIS / DNS | RDAP API | WHOIS lookup service |
| LegitScript | Public lookup / partner API | Manual flag |
| Core Web Vitals | PageSpeed Insights API | `BWG_SA_Module_PageSpeed` |

**Browser Extension:** Lightweight Chrome extension that runs a Phase 1–2 surface audit from any treatment center URL. Sends results to the WP plugin REST API to seed a session. Good for agency prospect qualifying on the fly.

---

## 3. WordPress Plugin Module

### 3.1 File Structure

```
bwg-ads-intel/
├── bwg-ads-intel.php              Bootstrap, constants, activation hooks
├── includes/
│   ├── class-bwg-ai-loader.php   Hooks all classes into WP
│   ├── class-bwg-ai-activator.php DB table creation / migrations
│   ├── class-bwg-ai-session.php  Session CRUD, access codes, resume tokens
│   ├── class-bwg-ai-rate-limiter.php
│   ├── class-bwg-ai-security.php
│   └── class-bwg-ai-email.php    All email sends
├── ads-intel/
│   ├── class-bwg-ai-rest.php     All REST endpoints
│   ├── class-bwg-ai-shortcode.php [bwg_ads_intel] renderer
│   ├── class-bwg-ai-discovery.php Phase 1 — business fingerprint
│   ├── class-bwg-ai-ad-surface.php Phase 2 — ad library polling
│   ├── class-bwg-ai-compliance.php Phase 3 — text compliance rules
│   ├── class-bwg-ai-spider.php   Phase 6 — landing page spider
│   ├── class-bwg-ai-report.php   Report generation, token management
│   └── assets/
│       ├── ai-form.js            Front-end (vanilla JS + jQuery, ~1200 lines)
│       └── ai-form.css
├── admin/
│   ├── class-bwg-ai-admin.php
│   └── partials/
│       ├── admin-list.php
│       ├── admin-detail.php
│       ├── admin-settings.php
│       └── report-template.php
└── uninstall.php
```

### 3.2 Database Tables

| Table | Key Columns |
|---|---|
| `wp_bwg_ai_sessions` | id, access_code, email, website_url, step_completed, status, resume_token, resume_token_expires, entityiq_job_id |
| `wp_bwg_ai_discovered` | session_id, gbp_*, social_*, pixel_*, whois_*, legitscript_status, discovery_confidence (JSON), discovery_flags (JSON) |
| `wp_bwg_ai_ads` | session_id, platform, advertiser_id, ad_id, ad_copy, ad_image_url, screenshot_path, run_dates, spend_range, compliance_flags (JSON), vision_analysis (JSON) |
| `wp_bwg_ai_access` | session_id, platform, access_status (pending/granted/export), grant_email_sent_at, access_granted_at, export_uploaded_at |
| `wp_bwg_ai_pages` | session_id, url, screenshot_path, cwv_scores (JSON), pixel_flags (JSON), compliance_flags (JSON), message_match_score, crawled_at |
| `wp_bwg_ai_reports` | session_id, audience_type, report_token, report_data (JSON), pdf_path, generated_at, emailed_at, expires_at |
| `wp_bwg_ai_ratelimits` | key, count, expires_at |
| `wp_bwg_ai_audit_log` | session_id, action, message, context (JSON), created_at |

### 3.3 REST Endpoints

Base: `/wp-json/bwg/v1/ai/`  
Auth: `X-WP-Nonce` on all except `/resume`

| Endpoint | Method | Phase | What |
|---|---|---|---|
| `/ai/start` | POST | 1 | Create session, queue discovery |
| `/ai/discovery-status/{id}` | GET | 1 poll | Returns discovery progress |
| `/ai/confirm-discovery` | POST | 1 save | User corrections to discovered data |
| `/ai/ad-surface-status/{id}` | GET | 2 poll | Returns ad library fetch progress |
| `/ai/ads/{id}` | GET | 2 | Returns found ads for gallery |
| `/ai/confirm-ads` | POST | 4 | User confirms / flags ads |
| `/ai/add-accounts` | POST | 4 | Additional account/agency entries |
| `/ai/access-status` | POST | 5 | Mark platform access granted |
| `/ai/upload-export` | POST | 5 | Upload platform export file |
| `/ai/spider-status/{id}` | GET | 6 poll | Landing page crawl progress |
| `/ai/report/{token}` | GET | 7 | Public report viewer |
| `/ai/email-report` | POST | 7 | Email report link to client |
| `/ai/resume` | POST | any | Resume by token or access code |
| `/ai/entityiq-webhook` | POST | internal | EntityIQ calls back on job complete |

### 3.4 WP-Cron Events

| Hook | When Scheduled | Handler |
|---|---|---|
| `bwg_ai_run_discovery` | 5s after start | `BWG_AI_Discovery` |
| `bwg_ai_poll_entityiq` | On EntityIQ job create, every 30s | `BWG_AI_REST` |
| `bwg_ai_send_access_followup` | Hourly | `BWG_AI_Email` |
| `bwg_ai_daily_maintenance` | Daily | `BWG_AI_Admin` |

### 3.5 Shortcode

`[bwg_ads_intel]` — renders the public multi-step audit form

`window.bwgAI` localized object:

| Key | Value |
|---|---|
| `restUrl` | `rest_url('bwg/v1/ai')` |
| `nonce` | `wp_create_nonce('wp_rest')` |
| `resumeToken` | `$_GET['resume']` if present |
| `captcha.*` | Provider + site key |
| `scheduleUrl` | Booking link for sales call CTA |

---

## 4. EntityIQ Extension (Node.js)

### 4.1 New Routes

```
entityiq-vone/routes/ads.js

POST /ads/surface          Queue ad library fetch job for a session
GET  /ads/surface/:jobId   Job status + partial results
POST /ads/screenshot       Screenshot a URL array (Playwright)
POST /ads/vision           Run Claude vision compliance on image URLs
POST /ads/pdf              Generate audience PDF from report data
```

### 4.2 New Lib Files

```
entityiq-vone/lib/
├── ad-scraper.js          Platform scrapers (Meta, Google, LinkedIn, TikTok, Bing)
├── meta-ad-library.js     Meta Ad Library API client + scraper fallback
├── google-transparency.js Google Ads Transparency API client
├── screenshot.js          Playwright browser manager, screenshot queue
├── vision-compliance.js   Claude vision API — image compliance analysis
└── pdf-report.js          Puppeteer report renderer (5 audience templates)
```

### 4.3 Job Flow

```
WP Plugin                          EntityIQ
   │                                   │
   ├─POST /ads/surface ──────────────► │
   │  { session_id, website_url,        │  Queue job
   │    platforms, advertiser_hints }   │  Run scrapers (async)
   │                                   │  Screenshot found ads
   │◄── 202 { job_id } ───────────────  │
   │                                   │
   │  (WP polls /ads/surface/:jobId)   │  AI vision on images
   │                                   │  Callback on complete
   │◄── POST /ai/entityiq-webhook ──── │
   │  { session_id, job_id, ads[] }    │
   │  WP saves to wp_bwg_ai_ads        │
```

---

## 5. Email Sequences

| Trigger | Email | Timing |
|---|---|---|
| Session start | Save-spot (access code) | Immediate |
| Phase 2 complete | "We found X ads — here's a preview" | Immediate |
| No Phase 4 confirm after 24h | Reminder + compliance teaser | Day 1 |
| No Phase 4 confirm after 72h | "Still found X issues..." | Day 3 |
| No Phase 4 confirm after 7d | Final outreach | Day 7 |
| Phase 5 — per platform | Access request email (templated, forwardable) | On-demand |
| Report ready | Report link + PDF attachment | On generation |

---

## 6. Report Outputs

Five audience reports generated from the same underlying audit data:

| Audience | Report Name | Key Sections |
|---|---|---|
| Executive / Owner | "What Does It Mean" | Risk score, wasted spend estimate, 3 urgent actions |
| CMO / Marketing Dir | Strategic Performance | Platform mix, benchmarks, attribution gaps, 90-day roadmap |
| Compliance / Legal | Compliance Risk | HIPAA exposure itemized, platform flags cited, remediation checklist |
| Agency Internal | Agency Intake | Account map, access status, upsell flags, onboarding checklist |
| Admissions Director | Admissions Performance | Channel → call → admission path, call quality, coaching gaps |

Each report available as: in-browser portal view, tokenized public URL, PDF download.

---

## 7. MVP Scope (Phase 1 Build)

Ship these first:

- [ ] Phase 1 Discovery (GBP, social, pixels, WHOIS, NAP, LegitScript flag)
- [ ] Phase 2 Meta Ad Library surface (API + scraper fallback, screenshots)
- [ ] Phase 3 Text compliance analysis (ad copy rules only, no vision yet)
- [ ] Phase 4 Screenshot gallery + confirm UI
- [ ] Phase 5 Access request email generator (Meta + Google templates)
- [ ] Phase 5 Export upload portal (Meta and Google CSV parsers)
- [ ] Session persistence, access codes, resume tokens
- [ ] Email drip sequence (3 follow-ups)
- [ ] Executive report output (in-browser, one audience to start)
- [ ] Admin: session list, detail view, manual status updates

Deferred to Phase 2:

- [ ] Google Transparency, LinkedIn, TikTok, Bing scraping
- [ ] Claude vision image compliance analysis
- [ ] Phase 6 landing page spider (CWV, pixel audit, message match)
- [ ] Phase 7 admissions / call audit
- [ ] All 5 audience reports + PDF export
- [ ] Chrome extension
- [ ] Competitor surveillance / continuous monitoring
- [ ] CRM integration hooks (Salesforce, HubSpot)

---

## 8. Reuse from bwg-speed-sitescout

When the `bwg-speed-sitescout` plugin is active, the following classes are called directly. If it is not active, they are bundled into `includes/` of this plugin.

| Class | Source | Used For |
|---|---|---|
| `BWG_CPA_Discovery` | `cpa-assessment/class-bwg-cpa-discovery.php` | Phase 1 base (extend, don't copy) |
| `BWG_SA_Scraper` | `site-audit/class-bwg-sa-scraper.php` | HTTP fetcher for landing page spider |
| `BWG_SA_Module_PageSpeed` | `site-audit/class-bwg-sa-module-pagespeed.php` | Core Web Vitals (Phase 6) |
| `BWG_Compliance` | `includes/class-bwg-compliance.php` | HIPAA compliance checks |
| `BWG_CPA_Rate_Limiter` | `cpa-assessment/class-bwg-cpa-rate-limiter.php` | Rate limiting pattern |

---

## 9. Tech Dependencies

| Dependency | Purpose | Notes |
|---|---|---|
| Google Places API | GBP matching, address extraction | Shared with bwg-speed-sitescout |
| PageSpeed Insights API | Core Web Vitals | Shared with bwg-speed-sitescout |
| Meta Ad Library API | Official ad data | Rate-limited; scraper fallback needed |
| DataForSEO | SERP / ad signals | Already in EntityIQ |
| Playwright | Screenshots, scraping, PDF | Node.js only — runs in EntityIQ |
| Claude API (Anthropic) | Vision compliance, ad copy scoring | Already in EntityIQ (`lib/claude.js`) |
| SendGrid / Postmark | Email sequences | |
| Cloudflare Turnstile | Anti-abuse captcha | Same pattern as CPA module |
| RDAP / WHOIS API | Domain intel | |
| LegitScript API or public lookup | Certification status | |
| Wappalyzer patterns | Tech stack fingerprint | Open-source pattern library |

---

## 10. Service Expansion Funnel (Reference)

| Tier | Deliverable | Price |
|---|---|---|
| Free | Phase 1–2 public audit (URL entry) | Lead gen |
| Paid report | Compliance snapshot report (Phase 3) | $497–$997 |
| Full audit | All platforms, 5 reports, landing page spider | $2,500–$5,000 |
| Journey audit | Phase 7 admissions + call coaching | $7,500–$15,000 |
| Retained | Monthly managed services + intel | $3,000–$10,000/mo |
