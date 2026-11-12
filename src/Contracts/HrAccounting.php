<?php

namespace Nit\AgraniHr\Contracts;

use Nit\AgraniHr\Accounting\Posting;

/**
 * The accounting connection of HR. The host application binds an implementation that records postings in its
 * accounting package; HR keeps the returned references. Without one (NullHrAccounting) nothing is posted and
 * the references stay empty.
 *
 * Both calls run inside HR's database transaction: when one fails, the HR step is rolled back too.
 */
interface HrAccounting
{
    /** Whether postings are recorded (false for the null connection). */
    public function connected(): bool;

    /**
     * Record the posting; a sourceKey already recorded returns that posting's reference (never a second posting).
     *
     * @return string|null the reference of the recorded posting
     */
    public function post(Posting $posting): ?string;

    /**
     * Reverse the posting with this reference on $date; reversing it again returns the first reversal.
     *
     * @return string|null the reference of the reversal
     */
    public function reverse(string $reference, string $date, ?string $narration = null): ?string;

    /** Whether the cash / bank account code can receive or pay money for the branch. */
    public function settlementAccountExists(string $code, int $branchId): bool;
}
