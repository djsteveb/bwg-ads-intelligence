<?php

namespace BWG\ComplianceRules\Tests;

use BWG\ComplianceRules\RuleSets\AdCopyRuleSet;
use PHPUnit\Framework\TestCase;

final class AdCopyRuleSetTest extends TestCase
{
    private function ruleIds(string $adCopy): array
    {
        $findings = AdCopyRuleSet::create()->evaluate($adCopy);
        return array_map(static function ($f) {
            return $f->ruleId;
        }, $findings);
    }

    public function test_hipaa_outcome_guarantee_fires_on_cure_claim(): void
    {
        $this->assertContains('hipaa_outcome_guarantee', $this->ruleIds('We guarantee sobriety for every client who enrolls.'));
    }

    public function test_hipaa_patient_testimonial_fires(): void
    {
        $this->assertContains('hipaa_patient_testimonial', $this->ruleIds('Read our patient testimonial about lasting recovery.'));
    }

    public function test_hipaa_bait_availability_fires(): void
    {
        $this->assertContains('hipaa_bait_availability', $this->ruleIds('Beds available now, call today.'));
    }

    public function test_hipaa_cfr_part2_pattern_fires_when_substance_use_near_named_individual(): void
    {
        $findings = $this->ruleIds('John struggled with substance use disorder before he found our program and the patient recovered fully.');
        $this->assertContains('hipaa_cfr_part2_pattern', $findings);
    }

    public function test_hipaa_unlicensed_claim_fires(): void
    {
        $this->assertContains('hipaa_unlicensed_claim', $this->ruleIds('Our medically supervised detox program is the best in the state.'));
    }

    public function test_policy_before_after_fires(): void
    {
        $this->assertContains('policy_before_after', $this->ruleIds('See our before and after treatment transformation photos.'));
    }

    public function test_policy_admissions_cta_no_disclaimer_fires_without_disclaimer(): void
    {
        $this->assertContains('policy_admissions_cta_no_disclaimer', $this->ruleIds('Call now for treatment and start your new life today.'));
    }

    public function test_policy_admissions_cta_suppressed_by_disclaimer(): void
    {
        $findings = $this->ruleIds('Call now for treatment. Results may vary and not all patients qualify.');
        $this->assertNotContains('policy_admissions_cta_no_disclaimer', $findings);
    }

    public function test_policy_insurance_guarantee_fires(): void
    {
        $this->assertContains('policy_insurance_guarantee', $this->ruleIds('Insurance covers everything -- zero cost to you.'));
    }

    public function test_policy_platform_cert_claim_fires(): void
    {
        $this->assertContains('policy_platform_cert_claim', $this->ruleIds('We are a Google certified treatment provider.'));
    }

    public function test_policy_personal_hardship_targeting_fires(): void
    {
        $this->assertContains('policy_personal_hardship_targeting', $this->ruleIds('Struggling with addiction and feel hopeless? We can help.'));
    }

    public function test_best_practice_absent_rules_fire_on_substantive_copy_missing_them(): void
    {
        $copy = 'Our recovery center offers comprehensive treatment for adults seeking a fresh start in a peaceful environment.';
        $findings = $this->ruleIds($copy);

        $this->assertContains('bp_no_phone_number', $findings);
        $this->assertContains('bp_no_accreditation', $findings);
        $this->assertContains('bp_no_insurance_mention', $findings);
        $this->assertContains('bp_no_contact_cta', $findings);
    }

    public function test_best_practice_absent_rules_do_not_fire_on_short_copy(): void
    {
        // Under the 40-char minimum -- an image-only ad shouldn't generate noise.
        $findings = $this->ruleIds('Get help today.');

        $this->assertNotContains('bp_no_phone_number', $findings);
        $this->assertNotContains('bp_no_accreditation', $findings);
        $this->assertNotContains('bp_no_insurance_mention', $findings);
        $this->assertNotContains('bp_no_contact_cta', $findings);
    }

    public function test_bp_no_phone_number_does_not_fire_when_phone_present(): void
    {
        $copy = 'Call us at (555) 123-4567 to learn more about our accredited, insurance-friendly program today.';
        $this->assertNotContains('bp_no_phone_number', $this->ruleIds($copy));
    }

    public function test_bp_excessive_urgency_fires(): void
    {
        $this->assertContains('bp_excessive_urgency', $this->ruleIds('Act now, limited time offer, spots filling up fast!'));
    }

    public function test_findings_are_sorted_high_to_low(): void
    {
        $copy = 'Guaranteed sobriety! Act now, limited time offer.';
        $findings = AdCopyRuleSet::create()->evaluate($copy);

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        $severities = array_map(static function ($f) use ($order) {
            return $order[$f->severity];
        }, $findings);

        $sorted = $severities;
        sort($sorted);
        $this->assertSame($sorted, $severities);
    }

    public function test_skip_rule_ids_excludes_sibling_reported_rules(): void
    {
        $copy = 'We guarantee sobriety for every client who enrolls.';
        $findings = AdCopyRuleSet::create()->evaluate($copy, [], ['hipaa_outcome_guarantee']);
        $ruleIds = array_map(static function ($f) {
            return $f->ruleId;
        }, $findings);

        $this->assertNotContains('hipaa_outcome_guarantee', $ruleIds);
    }

    public function test_clean_compliant_copy_produces_no_high_or_medium_findings(): void
    {
        $copy = 'Call (555) 123-4567 or visit our accredited, Joint Commission-licensed center. We accept most insurance and offer payment plans.';
        $findings = AdCopyRuleSet::create()->evaluate($copy);
        $severities = array_map(static function ($f) {
            return $f->severity;
        }, $findings);

        $this->assertNotContains('high', $severities);
        $this->assertNotContains('medium', $severities);
    }
}
