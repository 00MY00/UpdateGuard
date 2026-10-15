<?php

namespace Modules\UpdateGuard\Services\Security;

/**
 * Normalized risk score 0-100 and level. Pure PHP.
 *
 * Each rule counts once (its weight), each check family is capped (Rules::CAPS), the total is capped at 100.
 * Levels (configurable): LOW < medium <= MEDIUM < high <= HIGH < critical <= CRITICAL (defaults 21 / 50 / 80).
 */
class RiskScore
{
    const DEFAULT_THRESHOLDS = ['medium' => 21, 'high' => 50, 'critical' => 80];

    /** @return array ['score' => int, 'level' => string, 'by_check' => [check => points]] */
    public static function compute(array $findings, array $thresholds = [])
    {
        $byRule = [];
        foreach ($findings as $f) {
            $byRule[$f['rule']] = ['check' => $f['check'], 'weight' => (int) $f['weight']];
        }
        $byCheck = [];
        foreach ($byRule as $r) {
            $byCheck[$r['check']] = ($byCheck[$r['check']] ?? 0) + $r['weight'];
        }
        foreach ($byCheck as $check => $points) {
            $byCheck[$check] = min($points, Rules::CAPS[$check] ?? 100);
        }
        $score = (int) min(100, array_sum($byCheck));

        return ['score' => $score, 'level' => self::level($score, $thresholds), 'by_check' => $byCheck];
    }

    public static function level($score, array $thresholds = [])
    {
        $t = array_merge(self::DEFAULT_THRESHOLDS, array_filter($thresholds, function ($v) {
            return (int) $v > 0;
        }));
        if ($score >= (int) $t['critical']) {
            return Rules::CRITICAL;
        }
        if ($score >= (int) $t['high']) {
            return Rules::HIGH;
        }
        if ($score >= (int) $t['medium']) {
            return Rules::MEDIUM;
        }

        return Rules::LOW;
    }

    /** Highest severity of a list of findings ('' when none). */
    public static function maxSeverity(array $findings)
    {
        $max = '';
        foreach ($findings as $f) {
            if (Rules::ORDER[$f['severity']] > Rules::ORDER[$max]) {
                $max = $f['severity'];
            }
        }

        return $max;
    }
}
