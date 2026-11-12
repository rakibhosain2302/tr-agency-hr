<?php

namespace Nit\AgraniHr\Support;

use Nit\AgraniHr\Accounting\Posting;
use Nit\AgraniHr\Contracts\HrAccounting;

/**
 * No accounting connected: HR works alone and records no postings.
 */
final class NullHrAccounting implements HrAccounting
{
    public function connected(): bool
    {
        return false;
    }

    public function post(Posting $posting): ?string
    {
        return null;
    }

    public function reverse(string $reference, string $date, ?string $narration = null): ?string
    {
        return null;
    }

    public function settlementAccountExists(string $code, int $branchId): bool
    {
        return true;
    }
}
