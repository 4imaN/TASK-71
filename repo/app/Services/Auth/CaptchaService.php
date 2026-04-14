<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local math-challenge CAPTCHA.
 *
 * No external service, no image rendering.
 * Generates a simple arithmetic question; stores the expected answer in the
 * cache (Redis in prod, array in tests) keyed by a UUID token.
 * The token lives in Livewire component state; the answer lives server-side.
 *
 * TTL: 10 minutes. A consumed or expired token must trigger a new generate().
 */
class CaptchaService
{
    private const TTL_SECONDS = 600;

    /**
     * Generate a new challenge.
     *
     * @return array{token: string, question: string}
     */
    public function generate(): array
    {
        $a  = random_int(2, 15);
        $b  = random_int(1, 10);
        $op = (random_int(0, 1) === 0) ? '+' : '-';

        // Ensure the result is non-negative for subtraction
        if ($op === '-' && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        $answer = ($op === '+') ? ($a + $b) : ($a - $b);
        $token  = (string) Str::uuid();

        Cache::put("captcha:{$token}", (string) $answer, self::TTL_SECONDS);

        return [
            'token'    => $token,
            'question' => "{$a} {$op} {$b}",
        ];
    }

    /**
     * Verify that the supplied answer matches the stored answer for the token.
     * Does NOT consume the token — call consume() separately on success or refresh
     * on failure so the component can generate a new challenge.
     */
    public function verify(string $token, string $answer): bool
    {
        if ($token === '') {
            return false;
        }

        $expected = Cache::get("captcha:{$token}");

        if ($expected === null) {
            return false; // expired or already consumed
        }

        return trim($answer) === $expected;
    }

    /**
     * Consume (invalidate) a token so it cannot be reused.
     */
    public function consume(string $token): void
    {
        if ($token !== '') {
            Cache::forget("captcha:{$token}");
        }
    }
}
