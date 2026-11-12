<?php

namespace Nit\AgraniHr\Accounting;

/**
 * One side of a posting. key names what the money is (a salary component's posting_key, or one of the
 * constants); the host application maps keys to its ledger accounts. A SETTLEMENT line is the cash or bank
 * account named by settlementAccount (the code of the host's cash / bank account).
 */
final readonly class PostingLine
{
    public const SALARY_PAYABLE = 'salary_payable';

    public const EMPLOYEE_ADVANCE = 'employee_advance';

    public const SETTLEMENT = 'settlement';

    public function __construct(
        public string $key,
        public string $debit = '0.00',
        public string $credit = '0.00',
        public ?string $settlementAccount = null,
        public ?string $description = null,
    ) {}

    public static function debit(string $key, string $amount, ?string $description = null, ?string $settlementAccount = null): self
    {
        return new self($key, debit: $amount, settlementAccount: $settlementAccount, description: $description);
    }

    public static function credit(string $key, string $amount, ?string $description = null, ?string $settlementAccount = null): self
    {
        return new self($key, credit: $amount, settlementAccount: $settlementAccount, description: $description);
    }
}
