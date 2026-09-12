# bwg-compliance-rules

Shared, versioned healthcare-marketing compliance rule engine. Extracted
per Track A item 1 (see `bwg-comp-pl-one` and `bwg-ads-intelligence`'s own
`TRACK-A-CONSOLIDATION-TODO.md`) from two independently-built rule sets
that were solving the same HIPAA / 42 CFR Part 2 / FTC compliance problem
twice:

- **Ad-copy rules** (`RuleSets\AdCopyRuleSet`) -- 15 pattern-based rules
  across HIPAA/Legal, Platform policy, and Best practice tiers, ported
  from `bwg-ads-intelligence`'s `BWG_AI_Compliance::analyze_ad_copy()`.
- **Site-content rules** (`RuleSets\SiteContentRuleSet`) -- the HIPAA
  notice presence check and the reviews/testimonials detector, ported
  from `bwg-comp-pl-one`'s `BWG_Compliance_Scanner::check_hipaa_notice()`
  and `::check_reviews_and_testimonials()`.

Every rule's regex, severity, citation, and firing logic is unchanged
from its source -- this is an extraction, not a rewrite. See each
plugin's own adapter for how results map back to that plugin's existing
output shape.

## Usage

```php
use BWG\ComplianceRules\RuleSets\AdCopyRuleSet;

$findings = AdCopyRuleSet::create()->evaluate($adCopyText);
foreach ($findings as $finding) {
    // $finding->ruleId, ->severity, ->category, ->description,
    // ->citation, ->excerpt, ->confidence, ->details
}
```

`RuleSets\SiteContentRuleSet::hipaaNotice()` and `::reviewsTestimonials()`
expose the two site-content rules individually (the latter also has an
`analyze()` method returning the raw actionable-match count alongside
findings, since the original check surfaced that even when no violation
fired).

No WordPress dependency -- `src/Text.php` reimplements the two WP text
helpers (`wp_strip_all_tags`, `esc_html`) the ported rules relied on, so
this package can be required by any PHP consumer, not just a WP plugin.

## Consumers

- `bwg-ads-intelligence` -- `class-bwg-ai-compliance.php`'s
  `BWG_AI_Compliance::analyze_ad_copy()` is a thin adapter over
  `AdCopyRuleSet`.
- `bwg-comp-pl-one` -- `class-bwg-compliance-scanner.php`'s
  `check_hipaa_notice()`/`check_reviews_and_testimonials()` are thin
  adapters over `SiteContentRuleSet`.

Neither plugin runs `composer install` on the target WordPress site, so
each vendors this package's autoloader and code into its own committed
`vendor/` directory at release time.

## Development

```bash
composer install
composer test    # runs phpunit
```

## Releasing

Tag a version (`git tag vX.Y.Z && git push --tags`); each consuming
plugin's `composer.json` pins a version constraint against this repo via
a VCS repository entry (not Packagist).

## Out of scope

Per Track A's own scoping, this package does not (yet) cover: the
ad-creative vision/landing-page engine dedup between `bwg-ads-intelligence`
and `BWG-Ads-Acount-Audit`, generalizing rules past addiction-treatment
verticals, or a dedicated REST surface for rule results -- those are
separate, unaddressed TODO items.
