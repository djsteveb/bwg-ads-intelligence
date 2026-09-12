<?php

namespace BWG\ComplianceRules\Tests;

use BWG\ComplianceRules\RuleSets\SiteContentRuleSet;
use PHPUnit\Framework\TestCase;

final class SiteContentRuleSetTest extends TestCase
{
    public function test_hipaa_notice_missing_when_no_hipaa_language_present(): void
    {
        $findings = SiteContentRuleSet::hipaaNotice()->evaluate('<p>Welcome to our clinic. We offer great care.</p>');

        $this->assertCount(1, $findings);
        $this->assertSame('hipaa_notice.missing', $findings[0]->ruleId);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertSame('Missing HIPAA Compliance Notice', $findings[0]->details['title']);
    }

    public function test_hipaa_notice_manual_review_when_hipaa_language_present(): void
    {
        $content = "Line one\nWe comply with HIPAA regulations.\nLine three";
        $findings = SiteContentRuleSet::hipaaNotice()->evaluate($content);

        $this->assertCount(1, $findings);
        $finding = $findings[0];
        $this->assertSame('hipaa_notice.manual_review_required', $finding->ruleId);
        $this->assertSame('medium', $finding->severity);
        $this->assertSame(1, $finding->details['match_count']);
        $this->assertStringContainsString('HIPAA-related language was detected', $finding->description);
    }

    public function test_hipaa_notice_counts_multiple_matches(): void
    {
        $content = "We follow HIPAA rules.\nOur PHI safeguards are strict.\nMore protected health information details here.";
        $findings = SiteContentRuleSet::hipaaNotice()->evaluate($content);

        $this->assertSame(3, $findings[0]->details['match_count']);
    }

    public function test_reviews_testimonials_detects_high_confidence_star_rating(): void
    {
        $content = "Our patients love us.\n<p>5 out of 5 stars, highly recommend this center!</p>\nContact us today.";
        $result = SiteContentRuleSet::reviewsTestimonials()->analyze($content);

        $this->assertNotEmpty($result['findings']);
        $this->assertSame('high', $result['findings'][0]->severity);
        $this->assertGreaterThanOrEqual(50, $result['findings'][0]->confidence);
    }

    public function test_reviews_testimonials_ignores_verb_form_false_positives(): void
    {
        $content = "Please review your policy documents before submitting.\nReview and accept the terms and conditions.";
        $result = SiteContentRuleSet::reviewsTestimonials()->analyze($content);

        $this->assertEmpty($result['findings']);
        $this->assertSame(0, $result['actionableCount']);
    }

    public function test_reviews_testimonials_detects_platform_link(): void
    {
        $content = '<a href="https://www.yelp.com/biz/our-clinic">See our Yelp reviews</a>';
        $result = SiteContentRuleSet::reviewsTestimonials()->analyze($content);

        $this->assertNotEmpty($result['findings']);
        $platformNames = array_column($result['reviewDetails'], 'platform');
        $this->assertContains('Yelp', $platformNames);
    }

    public function test_reviews_testimonials_low_confidence_alone_produces_no_finding(): void
    {
        // A single low-confidence "rating" mention with no boosting
        // indicators nearby should not cross the actionable threshold.
        $content = "Our staff rating process happens quarterly for internal quality purposes.";
        $result = SiteContentRuleSet::reviewsTestimonials()->analyze($content);

        $this->assertEmpty($result['findings']);
    }

    public function test_site_content_rule_set_evaluates_both_rules_together(): void
    {
        $content = "<p>5 out of 5 stars, highly recommend!</p>\nWe are HIPAA compliant.";
        $findings = SiteContentRuleSet::create()->evaluate($content);

        $ruleIds = array_map(static function ($f) {
            return $f->ruleId;
        }, $findings);

        $this->assertContains('reviews_testimonials.detected', $ruleIds);
        $this->assertContains('hipaa_notice.manual_review_required', $ruleIds);
    }
}
