<?php

namespace BWG\ComplianceRules\RuleSets;

use BWG\ComplianceRules\RuleSet;
use BWG\ComplianceRules\SiteContent\HipaaNoticeRule;
use BWG\ComplianceRules\SiteContent\ReviewsTestimonialsRule;

/**
 * The site-content compliance rule set -- ported from bwg-comp-pl-one's
 * check_hipaa_notice() and check_reviews_and_testimonials(), the two
 * checks Track A item 1 named for extraction into this shared package.
 */
final class SiteContentRuleSet
{
    public static function create(): RuleSet
    {
        return new RuleSet([
            self::hipaaNotice(),
            self::reviewsTestimonials(),
        ]);
    }

    public static function hipaaNotice(): HipaaNoticeRule
    {
        return new HipaaNoticeRule();
    }

    public static function reviewsTestimonials(): ReviewsTestimonialsRule
    {
        return new ReviewsTestimonialsRule();
    }
}
