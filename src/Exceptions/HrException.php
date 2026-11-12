<?php

namespace Nit\AgraniHr\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An HR rule was broken (user already linked, termination before joining …). reason is a stable code;
 * rendered as 422 (JSON) or as a form error.
 */
class HrException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function invalid(string $problem, string $reason = 'invalid'): self
    {
        return new self($problem, $reason);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], 422);
        }

        return back()->withInput()->withErrors(['employee' => $this->getMessage()]);
    }
}
