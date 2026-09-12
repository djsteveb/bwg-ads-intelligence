<?php

namespace BWG\ComplianceRules;

interface Rule
{
    /**
     * @param array<string, mixed> $context Rule-specific extra input (e.g. platform, base URL).
     * @return Finding[]
     */
    public function evaluate(string $content, array $context = []): array;
}
