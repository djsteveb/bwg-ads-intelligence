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

- [x] **Start actually publishing to the shared cache.** ✅ Done:
  `BWG_AI_Report::generate_all()` now publishes a compact per-domain
  compliance summary (risk score, flag counts, flagged-ad count, vision-
  reviewed count) via `bwg_cache_set()` every time a session's reports are
  generated. Deliberately a new `healthcare_ad_compliance` cache
  `data_type`, not the existing generic `compliance_score` key -- that key
  is already owned by an unrelated site-speed/security auditor plugin
  (`BWG_Compliance_Auditor`) and means something else entirely there;
  reusing it would have silently collided two unrelated scores under one
  cache row. Registered in this plugin's own `bwg_suite_active_plugins()`
  entry (`bwg-suite-bridge.php`) as providing it.
- [x] **Resolve the duplicate vision/landing-page engine with `BWG_MAA`.**
  ✅ Investigated and closed — the premise didn't hold once actually
  compared. `BWG_MAA_Vision` isn't a duplicate of this repo's `BWG_AI_Vision`
  (M13): the former does generic ad-design QA (weak hierarchy, illegible
  text, missing CTA) with free-text output, the latter does HIPAA/42 CFR
  Part 2/FTC compliance review with structured `rule_id/severity/category`
  flags matching the rest of the suite's convention. They're two different
  checks that happen to both call a vision API, not one check built twice.
  Likewise, what this repo calls "M12" is actually
  `class-bwg-ai-google-transparency.php` -- a Google Ads Transparency Center
  screenshot capture, not a landing-page spider -- so it has no real
  counterpart in `BWG_MAA_Landing_Page`'s message-match/load-time/pixel
  checks either. Nothing to dedupe. The one real gap M13 had -- no
  opt-in toggle or per-run cost cap, unlike `BWG_MAA_Vision`'s
  `enable_creative_vision`/`max_creatives_analyzed` -- is now closed:
  `bwg_ai_enable_vision` (off by default) and `bwg_ai_max_vision_per_run`
  (default 5), enforced in `class-bwg-ai-ad-surface.php::save_ads()`.
- [x] **Extract the 12-rule `BWG_AI_Compliance` ad-copy engine into the shared
  rule package** alongside `bwg-comp-pl-one`'s site-content compliance rules.
  ✅ Done: [`bwg-compliance-rules`](https://github.com/djsteveb/bwg-compliance-rules)'s
  `AdCopyRuleSet` now holds this plugin's rule table (all 15 rules across
  the three severity tiers -- the array held 15, not 12, but that's a
  pre-existing count mismatch in this TODO's own wording, not something
  this extraction changed). `BWG_AI_Compliance::analyze_ad_copy()` is now
  a thin adapter (merged via `track-a/shared-compliance-rules`, #8).
- [x] **Generalize past addiction treatment.** ✅ Done (decision: broader
  healthcare-marketing positioning is the goal): `bwg/compliance-rules`
  gained `AdCopyRuleSet::forVertical()` and a new
  `genericHealthcareRules()` table for every vertical besides addiction
  treatment (med spas, therapy, dental, home health, telehealth, general
  healthcare) -- see that package's own commit for exactly which rules
  generalize and which don't (42 CFR Part 2 and "beds available now"
  stay addiction-treatment-only; no new per-vertical rules were invented
  without real legal research to back them). This plugin now has a
  `bwg_ai_healthcare_vertical` setting (admin → API settings), defaulting
  to `addiction_treatment` so existing installs see no behavior change;
  `BWG_AI_Compliance::analyze_ad_copy()` runs
  `AdCopyRuleSet::forVertical()` instead of the hardcoded `::create()`.
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
  service, not a WP user or another plugin in the same install (merged
  via `track-a/rest-surface`, #9).
