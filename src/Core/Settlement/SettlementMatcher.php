<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

use Anoofy\Woo\Core\Order\OrderStatus;

/** Pure reconciliation matcher between parsed courier statement lines and store candidates. */
final class SettlementMatcher
{
    /**
     * @param list<SettlementLine>               $lines
     * @param array<string, SettlementCandidate> $candidatesByTracking keyed by exact tracking string
     * @param list<string>                       $parseErrors
     */
    public static function match(array $lines, array $candidatesByTracking, array $parseErrors = []): SettlementPreview
    {
        $normalizedLookup = [];
        foreach ($candidatesByTracking as $tracking => $candidate) {
            $normalizedLookup[strtolower(trim((string) $tracking))] = $candidate;
        }

        $seen = [];
        $rows = [];
        $matched = [];
        $totalCents = 0;

        foreach ($lines as $line) {
            $key = strtolower(trim($line->trackingNumber));
            if (isset($seen[$key])) {
                $rows[] = new SettlementMatch($line, SettlementRowStatus::DuplicateTracking, $normalizedLookup[$key] ?? null);
                continue;
            }
            $seen[$key] = true;

            $candidate = $normalizedLookup[$key] ?? null;
            $status = self::classify($line, $candidate);
            $match = new SettlementMatch($line, $status, $candidate);
            $rows[] = $match;

            if ($status === SettlementRowStatus::Matched) {
                $matched[] = $match;
                $totalCents += $line->collectedCents;
            }
        }

        return new SettlementPreview($rows, $matched, $totalCents, $parseErrors);
    }

    private static function classify(SettlementLine $line, ?SettlementCandidate $candidate): SettlementRowStatus
    {
        if ($candidate === null) {
            return SettlementRowStatus::NotFound;
        }
        if ($candidate->handedOver) {
            return SettlementRowStatus::HandedOverToEngaz;
        }
        if ($candidate->alreadySettled) {
            return SettlementRowStatus::AlreadySettled;
        }
        if ($candidate->opsStatus !== OrderStatus::Delivered) {
            return SettlementRowStatus::NotDelivered;
        }
        if ($candidate->orderTotalCents !== $line->collectedCents) {
            return SettlementRowStatus::AmountMismatch;
        }

        return SettlementRowStatus::Matched;
    }
}
