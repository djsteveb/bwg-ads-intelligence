<?php

namespace BWG\ComplianceRules\SiteContent;

use BWG\ComplianceRules\Finding;
use BWG\ComplianceRules\Rule;

/**
 * Ported from bwg-comp-pl-one's
 * BWG_Compliance_Scanner::check_reviews_and_testimonials(). Presence
 * detection only -- it never reads actual review/testimonial text
 * semantics, matching the source TODO's own documented limitation.
 *
 * The original method returns `{violations, count}`, where `count` (the
 * actionable-match count) is meaningful even when zero violations fire.
 * `Rule::evaluate()` only returns findings, so callers that need the raw
 * count use `analyze()` directly instead.
 */
final class ReviewsTestimonialsRule implements Rule
{
    private const REVIEW_PATTERNS = [
        'review' => '/\b(review|reviews|reviewed)\b/i',
        'testimonial' => '/\b(testimonial|testimonials)\b/i',
        'rating' => '/\b(rating|ratings|rated)\b/i',
        'feedback' => '/\b(feedback|customer\s+stor(y|ies))\b/i',
        'stars' => '/★|⭐|(\*\*\*+)|(\d+\/5)|(\d+\sstar)/i',
        'review_schema' => '/itemtype=["\']https?:\/\/schema\.org\/(Review|Rating|AggregateRating)/i',
    ];

    private const FALSE_POSITIVE_PATTERNS = [
        '/\breview\s+(your|the|our|this|these|their|my|a|an)\s+(policy|policies|document|documents|coverage|plan|plans|benefits|options|information|details|records|file|files|account|application|form|forms|agreement|contract|terms|conditions|requirements|guidelines|criteria|procedures|materials|resources|data)\b/i',
        '/\breview\s+(and\s+)?(accept|agree|sign|submit|complete|confirm|verify|update|check|read)\b/i',
        '/\b(please|can|could|should|must|will|may|might|to)\s+review\b/i',
        '/\breview\s+(of|for)\s+(your|the|our|this|eligibility|compliance|accuracy|approval)\b/i',
        '/\bpeer\s+review/i',
        '/\butilization\s+review/i',
        '/\bmedical\s+review/i',
        '/\breview\s+process/i',
        '/\breview\s+board/i',
        '/\bunder\s+review/i',
        '/\breview\s+period/i',
    ];

    private const HIGH_CONFIDENCE_INDICATORS = [
        '/\b(5|five)\s*(out\s*of\s*5|\/5|\s*stars?)\b/i' => 30,
        '/\b[4-5]\s*stars?\b/i' => 25,
        '/★|⭐/u' => 30,
        '/\b(highly\s+recommend|would\s+recommend|recommend\s+to)\b/i' => 25,
        '/\b(amazing|excellent|wonderful|fantastic|great)\s+(experience|service|staff|care|treatment)\b/i' => 20,
        '/\b(patient|client|customer)\s+(said|says|wrote|writes|review|story|stories)\b/i' => 25,
        '/"[^"]{20,200}"/i' => 15,
        '/\bverified\s+(patient|customer|review)\b/i' => 30,
        '/\b(posted|written)\s+(on|by)\b/i' => 10,
        '/\bsuccess\s+stor(y|ies)\b/i' => 25,
        '/\breal\s+(patient|customer|client)\s+(review|story|stories)\b/i' => 30,
        '/itemtype=["\']https?:\/\/schema\.org\/(Review|Rating|AggregateRating)/i' => 35,
    ];

    private const REVIEW_PLATFORMS = [
        'google.com/maps' => 'Google Reviews',
        'yelp.com' => 'Yelp',
        'trustpilot.com' => 'Trustpilot',
        'facebook.com/reviews' => 'Facebook Reviews',
        'healthgrades.com' => 'Healthgrades',
        'vitals.com' => 'Vitals',
        'ratemds.com' => 'RateMDs',
        'zocdoc.com' => 'ZocDoc',
        'reviews' => 'Reviews page',
        'testimonials' => 'Testimonials page',
    ];

    public function evaluate(string $content, array $context = []): array
    {
        return $this->analyze($content)['findings'];
    }

    /**
     * @return array{findings: Finding[], actionableCount: int, reviewDetails: array<int, array<string, mixed>>}
     */
    public function analyze(string $content): array
    {
        $lines = explode("\n", $content);
        $reviewDetails = [];
        $foundPatterns = [];

        $calculateConfidence = static function (string $line, string $before, string $after): int {
            $confidence = 10;
            $fullContext = $before . ' ' . $line . ' ' . $after;
            foreach (self::HIGH_CONFIDENCE_INDICATORS as $indicatorPattern => $score) {
                if (preg_match($indicatorPattern, $fullContext)) {
                    $confidence += $score;
                }
            }
            return min(100, $confidence);
        };

        $isFalsePositive = static function (string $line, string $patternName): bool {
            if ($patternName !== 'review') {
                return false;
            }
            foreach (self::FALSE_POSITIVE_PATTERNS as $fpPattern) {
                if (preg_match($fpPattern, $line)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($lines as $lineNum => $line) {
            $contextBefore = $lineNum > 0 ? trim($lines[$lineNum - 1]) : '';
            $contextAfter = isset($lines[$lineNum + 1]) ? trim($lines[$lineNum + 1]) : '';

            foreach (self::REVIEW_PATTERNS as $patternName => $pattern) {
                if (!preg_match($pattern, $line, $matches)) {
                    continue;
                }
                if ($isFalsePositive($line, $patternName)) {
                    continue;
                }

                $codeSnippet = trim($line);
                if (preg_match('/<[^>]*' . preg_quote($matches[0], '/') . '[^>]*>/i', $line, $match)) {
                    $codeSnippet = $match[0];
                }

                $confidence = $calculateConfidence($line, $contextBefore, $contextAfter);

                $reviewDetails[] = [
                    'type' => 'pattern',
                    'pattern_name' => $patternName,
                    'matched_text' => $matches[0],
                    'line_number' => $lineNum + 1,
                    'code_snippet' => $codeSnippet,
                    'context_before' => $contextBefore,
                    'context_after' => $contextAfter,
                    'confidence' => $confidence,
                    'confidence_level' => $confidence >= 50 ? 'high' : ($confidence >= 25 ? 'medium' : 'low'),
                ];

                if (!in_array($patternName, $foundPatterns, true)) {
                    $foundPatterns[] = $patternName;
                }
            }

            foreach (self::REVIEW_PLATFORMS as $domain => $platformName) {
                if (stripos($line, $domain) === false) {
                    continue;
                }

                $codeSnippet = trim($line);
                if (preg_match('/<a[^>]*href=["\'][^"\']*' . preg_quote($domain, '/') . '[^"\']*["\'][^>]*>.*?<\/a>/i', $line, $match)) {
                    $codeSnippet = $match[0];
                } elseif (preg_match('/<a[^>]*' . preg_quote($domain, '/') . '[^>]*>/i', $line, $match)) {
                    $codeSnippet = $match[0];
                }

                $confidence = $calculateConfidence($line, $contextBefore, $contextAfter);
                if (!in_array($domain, ['reviews', 'testimonials'], true)) {
                    $confidence = min(100, $confidence + 20);
                }

                $reviewDetails[] = [
                    'type' => 'platform_link',
                    'platform' => $platformName,
                    'domain' => $domain,
                    'line_number' => $lineNum + 1,
                    'code_snippet' => $codeSnippet,
                    'context_before' => $contextBefore,
                    'context_after' => $contextAfter,
                    'confidence' => $confidence,
                    'confidence_level' => $confidence >= 50 ? 'high' : ($confidence >= 25 ? 'medium' : 'low'),
                ];

                $platformKey = 'platform_' . $domain;
                if (!in_array($platformKey, $foundPatterns, true)) {
                    $foundPatterns[] = $platformKey;
                }
            }
        }

        $findings = [];

        if (!empty($reviewDetails)) {
            $patternNames = [];
            $platformNames = [];
            $highConfidenceDetails = [];
            $mediumConfidenceDetails = [];
            $lowConfidenceDetails = [];
            $maxConfidence = 0;

            foreach ($reviewDetails as $detail) {
                $confidence = $detail['confidence'] ?? 10;
                $maxConfidence = max($maxConfidence, $confidence);

                if ($confidence >= 50) {
                    $highConfidenceDetails[] = $detail;
                } elseif ($confidence >= 25) {
                    $mediumConfidenceDetails[] = $detail;
                } else {
                    $lowConfidenceDetails[] = $detail;
                }

                if ($detail['type'] === 'pattern' && !in_array($detail['pattern_name'], $patternNames, true)) {
                    $patternNames[] = $detail['pattern_name'];
                } elseif ($detail['type'] === 'platform_link' && !in_array($detail['platform'], $platformNames, true)) {
                    $platformNames[] = $detail['platform'];
                }
            }

            // Only create a finding if we have medium or high confidence
            // matches -- low confidence matches alone are likely false
            // positives.
            $actionableDetails = array_merge($highConfidenceDetails, $mediumConfidenceDetails);

            if (!empty($actionableDetails)) {
                $description = 'The website may contain customer reviews or testimonials. ';

                $confidenceSummary = [];
                if (!empty($highConfidenceDetails)) {
                    $confidenceSummary[] = count($highConfidenceDetails) . ' high confidence';
                }
                if (!empty($mediumConfidenceDetails)) {
                    $confidenceSummary[] = count($mediumConfidenceDetails) . ' medium confidence';
                }
                if (!empty($lowConfidenceDetails)) {
                    $confidenceSummary[] = count($lowConfidenceDetails) . ' low confidence (likely false positives)';
                }
                $description .= 'Detection confidence: ' . implode(', ', $confidenceSummary) . '. ';

                if (!empty($patternNames)) {
                    $description .= 'Found indicators: ' . implode(', ', $patternNames) . '. ';
                }
                if (!empty($platformNames)) {
                    $description .= 'Found review platforms: ' . implode(', ', $platformNames) . '. ';
                }
                $description .= 'Customer reviews may contain Protected Health Information (PHI) and require careful HIPAA compliance review.';

                $severity = $maxConfidence >= 50 ? 'high' : 'medium';

                $findings[] = new Finding(
                    'reviews_testimonials.detected',
                    $severity,
                    'privacy',
                    $description,
                    null,
                    null,
                    $maxConfidence,
                    [
                        'title' => 'Customer Reviews/Testimonials Detected - Manual Review Required',
                        'recommendation' => 'Manually review all customer reviews and testimonials to ensure: 1) No PHI is disclosed without written authorization, 2) All reviews are properly anonymized, 3) Patient consent was obtained before publishing, 4) Reviews comply with HIPAA Privacy Rule requirements. Consider implementing a review approval process.',
                        'review_details' => $reviewDetails,
                        'confidence_stats' => [
                            'max_confidence' => $maxConfidence,
                            'high_count' => count($highConfidenceDetails),
                            'medium_count' => count($mediumConfidenceDetails),
                            'low_count' => count($lowConfidenceDetails),
                        ],
                    ]
                );
            }
        }

        $actionableCount = 0;
        foreach ($reviewDetails as $detail) {
            if (($detail['confidence'] ?? 10) >= 25) {
                $actionableCount++;
            }
        }

        return [
            'findings' => $findings,
            'actionableCount' => $actionableCount,
            'reviewDetails' => $reviewDetails,
        ];
    }
}
