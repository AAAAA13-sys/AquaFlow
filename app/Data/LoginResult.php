<?php

namespace App\Data;

use App\Models\User;

/**
 * Outcome of an authentication attempt.
 *
 * Carries the failure message and HTTP status so the controller can stay a thin
 * mapper and the rules stay testable in one place.
 */
final readonly class LoginResult
{
    private function __construct(
        public ?User $user,
        public ?string $message,
        public int $status,
    ) {
    }

    public static function ok(User $user): self
    {
        return new self($user, null, 200);
    }

    public static function failed(string $message, int $status = 401): self
    {
        return new self(null, $message, $status);
    }

    public function succeeded(): bool
    {
        return $this->user !== null;
    }
}
