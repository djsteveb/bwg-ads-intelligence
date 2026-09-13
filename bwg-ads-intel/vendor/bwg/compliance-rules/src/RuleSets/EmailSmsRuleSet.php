<?php

namespace BWG\ComplianceRules\RuleSets;

use BWG\ComplianceRules\PatternRule;
use BWG\ComplianceRules\Rule;
use BWG\ComplianceRules\RuleSet;

/**
 * Compliance rules for outbound email and SMS campaigns -- the "extend the
 * gate" roadmap item covering channels AdCopyRuleSet never addressed (that
 * one assumes ad-platform copy, not a message someone is actually sent).
 *
 * Two kinds of rule live here:
 *  - Channel-mechanics rules new to this file: the CTIA/TCPA baseline for
 *    SMS (STOP/HELP instructions, message-frequency disclosure) and the
 *    CAN-SPAM Act's two unambiguous technical requirements for email (a
 *    working unsubscribe mechanism, a physical postal address). These are
 *    real, well-established legal/industry requirements, not invented ones
 *    -- same "don't fabricate a citation" principle AdCopyRuleSet's own
 *    doc comment states.
 *  - The calling org's healthcare-vertical rules, reused from
 *    AdCopyRuleSet::forVertical() rather than re-implemented: an outcome
 *    guarantee or a non-consensual patient testimonial means the same
 *    thing in a marketing email as it does in an ad. Only the HIPAA/Legal
 *    and Platform policy categories are pulled in, not AdCopyRuleSet's
 *    "Best practice" rules (no phone number / no contact CTA / excessive
 *    urgency) -- those assume ad-copy framing and would just add noise to
 *    an email or SMS body that already has to carry STOP/unsubscribe text.
 */
final class EmailSmsRuleSet
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    /**
     * @return string[] Recognized values for the `channel` param this
     *   class and its REST-layer callers accept.
     */
    public static function channels(): array
    {
        return [self::CHANNEL_EMAIL, self::CHANNEL_SMS];
    }

    /**
     * Builds the rule set for one message: the channel-mechanics rules for
     * $channel, plus $vertical's HIPAA/Legal and Platform policy rules.
     * An unrecognized $channel falls back to the email rule set (fail-safe:
     * an unrecognized channel still gets checked against *something*,
     * never silently skipped).
     */
    public static function forChannel(string $channel, string $vertical): RuleSet
    {
        $channelRules = self::CHANNEL_SMS === $channel ? self::smsRules() : self::emailRules();

        return new RuleSet(array_merge(self::healthcareRulesForVertical($vertical), $channelRules));
    }

    /**
     * @return Rule[]
     */
    private static function healthcareRulesForVertical(string $vertical): array
    {
        $rules = AdCopyRuleSet::forVertical($vertical)->rules();

        return array_values(array_filter($rules, static function (Rule $rule): bool {
            return !($rule instanceof PatternRule) || 'Best practice' !== $rule->getCategory();
        }));
    }

    /**
     * @return PatternRule[]
     */
    public static function smsRules(): array
    {
        return [
            new PatternRule(
                'sms_no_stop_instruction',
                'high',
                'TCPA / CTIA',
                'No STOP/opt-out instruction in the message',
                'TCPA, 47 U.S.C. § 227; CTIA Messaging Principles and Best Practices',
                null,
                '/\b((reply|text)\s+stop\b|stop\s+to\s+(opt[\s\-]out|unsubscribe|cancel)\b|stop\s*=\s*(opt[\s\-]out|cancel)\b)/i',
                null,
                20
            ),
            new PatternRule(
                'sms_no_frequency_disclosure',
                'medium',
                'TCPA / CTIA',
                'No message-frequency disclosure (e.g. "msg frequency varies")',
                'CTIA Messaging Principles and Best Practices',
                null,
                '/\b((msg|message)s?\s*(&|and)?\s*data\s+rates?\s+may\s+apply|(msg|message)\s+freq(uency)?\s+(varies|may\s+vary)|up\s+to\s+\d+\s+(msgs?|messages?)\s*(per|\/)\s*(month|week))\b/i',
                null,
                20
            ),
            new PatternRule(
                'sms_no_help_instruction',
                'low',
                'TCPA / CTIA',
                'No HELP instruction in the message',
                'CTIA Messaging Principles and Best Practices',
                null,
                '/\b(reply|text)\s+help\b/i',
                null,
                20
            ),
        ];
    }

    /**
     * @return PatternRule[]
     */
    public static function emailRules(): array
    {
        return [
            new PatternRule(
                'email_no_unsubscribe_mechanism',
                'high',
                'CAN-SPAM',
                'No unsubscribe/opt-out mechanism in the message',
                'CAN-SPAM Act, 15 U.S.C. § 7704(a)(3)-(5)',
                null,
                '/\b(unsubscribe|opt[\s\-]out|manage\s+(your\s+)?(email\s+)?preferences|update\s+(your\s+)?(email\s+)?preferences)\b/i'
            ),
            new PatternRule(
                'email_no_physical_address',
                'high',
                'CAN-SPAM',
                'No physical postal address in the message',
                'CAN-SPAM Act, 15 U.S.C. § 7704(a)(5)',
                null,
                '/\b\d{1,6}\s+([A-Za-z0-9.\'\-]+\s+){1,5}(Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr|Way|Court|Ct|Suite|Ste|Parkway|Pkwy|Highway|Hwy)\b\.?/i'
            ),
        ];
    }
}
