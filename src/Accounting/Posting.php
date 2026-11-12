<?php

namespace Nit\AgraniHr\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Support\Money;

/**
 * What an HR document does to the books (payroll approved, salary paid, advance disbursed or repaid), as
 * balanced lines by posting key. HR says what happened; the accounting package records it.
 *
 * sourceKey identifies the document and step ("hr.payrolls.12.approval"): the same key is posted once.
 */
final readonly class Posting
{
    /** @param  list<PostingLine>  $lines */
    public function __construct(
        public string $sourceKey,
        public string $sourceType,
        public int $sourceId,
        public int $branchId,
        public string $date,
        public array $lines,
        public ?string $narration = null,
        public ?string $reference = null,
    ) {
        $debit = Money::add(...array_map(fn (PostingLine $line): string => $line->debit, $lines));
        $credit = Money::add(...array_map(fn (PostingLine $line): string => $line->credit, $lines));
        if ($lines === [] || Money::compare($debit, $credit) !== 0 || ! Money::isPositive($debit)) {
            throw HrException::invalid(__('The posting for :key does not balance.', ['key' => $sourceKey]), 'unbalanced_posting');
        }
    }

    /** @param  list<PostingLine>  $lines */
    public static function for(Model $source, string $step, int $branchId, \DateTimeInterface|string $date, array $lines, ?string $narration = null, ?string $reference = null): self
    {
        return new self(
            'hr.'.Str::after($source->getTable(), 'hr_').".{$source->getKey()}.{$step}",
            $source->getMorphClass(),
            (int) $source->getKey(),
            $branchId,
            $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date,
            $lines,
            $narration,
            $reference,
        );
    }

    public function total(): string
    {
        return Money::add(...array_map(fn (PostingLine $line): string => $line->debit, $this->lines));
    }
}
