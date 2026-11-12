<?php

namespace Nit\AgraniHr\Tests\Fixtures;

use Nit\AgraniHr\Accounting\Posting;
use Nit\AgraniHr\Contracts\HrAccounting;
use RuntimeException;

/**
 * An accounting connection for tests: keeps the postings and reversals, records a source key once, knows the
 * cash / bank accounts CASH and BANK, and can be told to refuse.
 */
final class RecordingHrAccounting implements HrAccounting
{
    /** @var array<string, Posting> reference => posting */
    public array $postings = [];

    /** @var array<string, array{reference: string, date: string, narration: ?string}> original reference => reversal */
    public array $reversals = [];

    public int $calls = 0;

    public bool $refuse = false;

    public function connected(): bool
    {
        return true;
    }

    public function post(Posting $posting): ?string
    {
        $this->calls++;
        if ($this->refuse) {
            throw new RuntimeException('The books are closed.');
        }
        foreach ($this->postings as $reference => $recorded) {
            if ($recorded->sourceKey === $posting->sourceKey) {
                return $reference;
            }
        }
        $reference = 'JE-'.(count($this->postings) + 1);
        $this->postings[$reference] = $posting;

        return $reference;
    }

    public function reverse(string $reference, string $date, ?string $narration = null): ?string
    {
        if (! isset($this->postings[$reference])) {
            throw new RuntimeException("Unknown posting {$reference}.");
        }

        return ($this->reversals[$reference] ??= ['reference' => 'REV-'.$reference, 'date' => $date, 'narration' => $narration])['reference'];
    }

    public function settlementAccountExists(string $code, int $branchId): bool
    {
        return in_array($code, ['CASH', 'BANK'], true);
    }

    /** @return array<string, array{debit: string, credit: string}> key => totals of the posting */
    public function sides(string $reference): array
    {
        $sides = [];
        foreach ($this->postings[$reference]->lines as $line) {
            $sides[$line->key] = ['debit' => $line->debit, 'credit' => $line->credit];
        }
        ksort($sides);

        return $sides;
    }
}
