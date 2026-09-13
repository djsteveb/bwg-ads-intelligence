<?php

namespace BWG\ComplianceRules\Tests;

use BWG\ComplianceRules\RuleSets\EmailSmsRuleSet;
use PHPUnit\Framework\TestCase;

final class EmailSmsRuleSetTest extends TestCase
{
    private function ruleIds(string $channel, string $content, string $vertical = 'addiction_treatment'): array
    {
        $findings = EmailSmsRuleSet::forChannel($channel, $vertical)->evaluate($content);
        return array_map(static function ($f) {
            return $f->ruleId;
        }, $findings);
    }

    // -------------------------------------------------------------------
    // SMS
    // -------------------------------------------------------------------

    public function test_sms_no_stop_instruction_fires_when_missing(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Your appointment is confirmed for tomorrow at 2pm. See you then!');
        $this->assertContains('sms_no_stop_instruction', $ids);
    }

    public function test_sms_no_stop_instruction_does_not_fire_when_present(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Your appointment is confirmed for tomorrow at 2pm. Reply STOP to opt out.');
        $this->assertNotContains('sms_no_stop_instruction', $ids);
    }

    public function test_sms_no_frequency_disclosure_fires_when_missing(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Your appointment is confirmed for tomorrow at 2pm. Reply STOP to opt out.');
        $this->assertContains('sms_no_frequency_disclosure', $ids);
    }

    public function test_sms_no_frequency_disclosure_does_not_fire_when_present(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Appointment reminders. Msg frequency varies. Reply STOP to opt out, HELP for help.');
        $this->assertNotContains('sms_no_frequency_disclosure', $ids);
    }

    public function test_sms_no_help_instruction_fires_when_missing(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Your appointment is confirmed for tomorrow at 2pm. Reply STOP to opt out.');
        $this->assertContains('sms_no_help_instruction', $ids);
    }

    public function test_sms_short_message_does_not_trigger_absent_rules(): void
    {
        // Below the 20-char minLengthForAbsent guard -- shouldn't be flagged as noise.
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'See you soon.');
        $this->assertEmpty($ids);
    }

    public function test_sms_reuses_healthcare_legal_rules_for_the_vertical(): void
    {
        // A same-day-admission bait claim is addiction_treatment's own
        // hipaa_bait_availability rule -- reused here, not reimplemented.
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Beds available now, reply STOP to opt out, HELP for help. Msg frequency varies.');
        $this->assertContains('hipaa_bait_availability', $ids);
    }

    public function test_sms_does_not_reuse_ad_copy_best_practice_rules(): void
    {
        // bp_no_phone_number etc. assume ad-copy framing and would just be
        // noise on a compliant SMS -- must not appear even though the
        // underlying AdCopyRuleSet table for this vertical has them.
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Beds available now, reply STOP to opt out, HELP for help. Msg frequency varies.');
        $this->assertNotContains('bp_no_phone_number', $ids);
        $this->assertNotContains('bp_no_contact_cta', $ids);
    }

    // -------------------------------------------------------------------
    // Email
    // -------------------------------------------------------------------

    public function test_email_no_unsubscribe_mechanism_fires_when_missing(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_EMAIL, 'Thanks for being a valued client. We look forward to seeing you at your next visit. 123 Main Street, Springfield, IL.');
        $this->assertContains('email_no_unsubscribe_mechanism', $ids);
    }

    public function test_email_no_unsubscribe_mechanism_does_not_fire_when_present(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_EMAIL, 'Thanks for being a valued client. 123 Main Street, Springfield, IL. Click here to unsubscribe from these emails.');
        $this->assertNotContains('email_no_unsubscribe_mechanism', $ids);
    }

    public function test_email_no_physical_address_fires_when_missing(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_EMAIL, 'Thanks for being a valued client. Click here to unsubscribe from these emails at any time.');
        $this->assertContains('email_no_physical_address', $ids);
    }

    public function test_email_no_physical_address_does_not_fire_when_present(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_EMAIL, 'Thanks for being a valued client. Click here to unsubscribe. 456 Oak Avenue, Suite 200, Chicago, IL.');
        $this->assertNotContains('email_no_physical_address', $ids);
    }

    public function test_email_reuses_healthcare_legal_rules_for_the_vertical(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_EMAIL, 'We guarantee sobriety for every client. Unsubscribe here. 123 Main Street, Springfield, IL.');
        $this->assertContains('hipaa_outcome_guarantee', $ids);
    }

    // -------------------------------------------------------------------
    // Channel dispatch
    // -------------------------------------------------------------------

    public function test_unrecognized_channel_falls_back_to_email_rules_rather_than_skipping_checks(): void
    {
        $ids = $this->ruleIds('carrier-pigeon', 'Thanks for being a valued client. Click here to unsubscribe from these emails at any time.');
        $this->assertContains('email_no_physical_address', $ids);
    }

    public function test_generic_vertical_does_not_pull_in_addiction_specific_legal_rules(): void
    {
        $ids = $this->ruleIds(EmailSmsRuleSet::CHANNEL_SMS, 'Beds available now, reply STOP to opt out, HELP for help. Msg frequency varies.', 'med_spa');
        $this->assertNotContains('hipaa_bait_availability', $ids);
    }
}
