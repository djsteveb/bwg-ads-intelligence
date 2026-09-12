# Track A: healthcare-compliance consolidation TODOs

Context: prompted by Blee (AI-first marketing compliance platform, $27M raise).
Full scoping lives in `1-map-synposises`:
[HEALTHCARE-COMPLIANCE-CONSOLIDATION.md](https://github.com/djsteveb/1-map-synposises/blob/main/HEALTHCARE-COMPLIANCE-CONSOLIDATION.md).

"Track A" is the cheap, ship-now consolidation of what already exists across
this repo (`BWG_AI_Compliance`), `bwg-comp-pl-one` (`BWG_Compliance`), and
`BWG-Ads-Acount-Audit` (`BWG_MAA`) into one coherent healthcare-marketing
compliance offering — as distinct from "Track B," a new standalone
pre-publish-review gateway service (PRD to be written separately) that will
eventually call into this plugin's checks rather than duplicate them.

## TODOs for this repo

- [ ] **Start actually publishing to the shared cache.** This plugin joined
  the suite bridge (copies `bwg-suite-bridge.php`, consumes the shared
  `google_places_api_key`) but as of now has **zero `bwg_cache_set()` calls
  anywhere in the codebase** — ad-surface discovery and the M13 Claude-vision
  compliance results stay local to this plugin's own tables. Publish at least
  `ad_audit_score`/vision-compliance findings per domain so siblings
  (`bwg-comp-pl-one`, a future Track B gateway) can read them without
  re-running the analysis.
- [ ] **Resolve the duplicate vision/landing-page engine with `BWG_MAA`.**
  `BWG-Ads-Acount-Audit`'s `class-bwg-maa-vision.php` and
  `class-bwg-maa-landing-page.php` were built independently, days apart, doing
  essentially the same thing as this repo's own M13 (Claude-vision ad-creative
  compliance) and M12 (landing-page spider). Before either engine's output
  gets consumed by a consolidated product, decide which is canonical — don't
  build a third pass at the same problem, and don't feed both into downstream
  consumers as if they were independent signals.
- [x] **Extract the 12-rule `BWG_AI_Compliance` ad-copy engine into the shared
  rule package** alongside `bwg-comp-pl-one`'s site-content compliance rules.
  ✅ Done: [`bwg-compliance-rules`](https://github.com/djsteveb/bwg-compliance-rules)'s
  `AdCopyRuleSet` now holds this plugin's rule table (all 15 rules across
  the three severity tiers -- the array held 15, not 12, but that's a
  pre-existing count mismatch in this TODO's own wording, not something
  this extraction changed). `BWG_AI_Compliance::analyze_ad_copy()` is now
  a thin adapter, on branch `track-a/shared-compliance-rules` (not yet
  merged).
- [ ] **Generalize past addiction treatment** if the broader healthcare-
  marketing positioning (med spas, therapy, dental, telehealth) is the goal —
  today's rules (bait-availability, "beds available now", 42 CFR Part 2
  patterns) are written specifically for treatment-center ad copy.
- [x] Keep the REST layer (`class-bwg-ai-rest.php`) in mind as the eventual
  integration point for Track B's gateway service. ✅ Done:
  `POST /bwg/v1/ai/compliance/check-ad-copy` (plus a public
  `GET .../compliance/capabilities` discovery route) lets an external
  caller run the ad-copy rules and read flags synchronously. Uses a
  shared-secret-token auth (`X-BWG-Remote-Token`,
  `bwg_suite_authorize_compliance_rules_request()` in
  `bwg-suite-bridge.php`) rather than `bwg-maa/v1`'s
  logged-in+capability+plugin-slug-allowlist pattern, since the caller
  (Track B's `bwg-content-guardian` gateway) is an external Next.js
  service, not a WP user or another plugin in the same install. On
  branch `track-a/rest-surface` (not yet merged).
