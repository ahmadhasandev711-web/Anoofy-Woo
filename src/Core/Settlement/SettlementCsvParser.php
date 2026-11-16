<?php
declare(strict_types=1);

namespace Anoofy\Woo\Core\Settlement;

use Anoofy\Woo\Core\Connection\OrderHandoverItem;

/** Parses courier COD settlement statements (CSV with `,` or `;`). Pure PHP. */
final class SettlementCsvParser
{
    private const TRACKING_HEADERS = ['tracking', 'tracking_number', 'awb', 'waybill'];
    private const AMOUNT_HEADERS = ['amount', 'cod_amount', 'collected', 'total'];
    private const REFERENCE_HEADERS = ['reference', 'ref', 'transaction_id', 'batch'];

    /** @return array{lines: list<SettlementLine>, errors: list<string>} */
    public static function parse(string $csv): array
    {
        $clean = ltrim($csv, "\xEF\xBB\xBF");
        $rawLines = preg_split('/\r\n|\r|\n/', $clean);
        if (!is_array($rawLines)) {
            return ['lines' => [], 'errors' => []];
        }

        $nonEmpty = [];
        foreach ($rawLines as $idx => $raw) {
            if (trim($raw) !== '') {
                $nonEmpty[] = ['num' => $idx + 1, 'raw' => $raw];
            }
        }
        if ($nonEmpty === []) {
            return ['lines' => [], 'errors' => []];
        }

        $delimiter = self::detectDelimiter($nonEmpty[0]['raw']);
        $firstCells = str_getcsv($nonEmpty[0]['raw'], $delimiter, '"', '');
        $mapping = self::detectHeaderMap($firstCells);

        $startIndex = $mapping !== null ? 1 : 0;
        $colMap = $mapping ?? ['tracking' => 0, 'amount' => 1, 'reference' => 2];

        $lines = [];
        $errors = [];

        $count = count($nonEmpty);
        for ($i = $startIndex; $i < $count; $i++) {
            $rowNum = $nonEmpty[$i]['num'];
            $cells = str_getcsv($nonEmpty[$i]['raw'], $delimiter, '"', '');

            $tracking = isset($cells[$colMap['tracking']]) ? trim((string) $cells[$colMap['tracking']]) : '';
            $rawAmount = isset($cells[$colMap['amount']]) ? trim((string) $cells[$colMap['amount']]) : '';
            $ref = ($colMap['reference'] !== null && isset($cells[$colMap['reference']]))
                ? trim((string) $cells[$colMap['reference']])
                : '';

            if ($tracking === '') {
                $errors[] = sprintf('Row %d: missing tracking number.', $rowNum);
                continue;
            }

            $cents = self::parseAmountCents($rawAmount);
            if ($cents === null || $cents < 0) {
                $errors[] = sprintf('Row %d (%s): invalid amount "%s".', $rowNum, $tracking, $rawAmount);
                continue;
            }

            $lines[] = new SettlementLine($rowNum, $tracking, $cents, $ref !== '' ? $ref : null);
        }

        return ['lines' => $lines, 'errors' => $errors];
    }

    private static function detectDelimiter(string $firstLine): string
    {
        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param list<string|null> $cells
     * @return array{tracking: int, amount: int, reference: ?int}|null
     */
    private static function detectHeaderMap(array $cells): ?array
    {
        $trackingIdx = null;
        $amountIdx = null;
        $refIdx = null;

        foreach ($cells as $idx => $cell) {
            $key = strtolower(str_replace([' ', '-'], '_', trim((string) $cell)));
            if ($trackingIdx === null && in_array($key, self::TRACKING_HEADERS, true)) {
                $trackingIdx = (int) $idx;
            } elseif ($amountIdx === null && in_array($key, self::AMOUNT_HEADERS, true)) {
                $amountIdx = (int) $idx;
            } elseif ($refIdx === null && in_array($key, self::REFERENCE_HEADERS, true)) {
                $refIdx = (int) $idx;
            }
        }

        if ($trackingIdx === null || $amountIdx === null) {
            return null;
        }

        return ['tracking' => $trackingIdx, 'amount' => $amountIdx, 'reference' => $refIdx];
    }

    private static function parseAmountCents(string $raw): ?int
    {
        $normalized = preg_replace('/[^0-9.\-]/', '', $raw);
        if (!is_string($normalized) || $normalized === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
            return null;
        }

        return OrderHandoverItem::toCents($normalized);
    }
}
