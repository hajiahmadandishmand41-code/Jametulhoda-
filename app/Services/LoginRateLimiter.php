<?php

declare(strict_types=1);

/**
 * Small PDO-backed login throttle for shared hosting.
 *
 * A row aggregates failures for one email/IP pair. It avoids an external
 * cache and never stores a password, session ID or CSRF token. The configured
 * limit is intentionally low enough to slow guessing without locking a user
 * out permanently; a later successful login clears the aggregate.
 */
final class LoginRateLimiter
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900
    ) {
    }

    public static function configured(): self
    {
        $max = (int) Config::get('auth.max_login_attempts', 5);
        $window = (int) Config::get('auth.login_window_seconds', 900);
        $lockout = (int) Config::get('auth.lockout_seconds', 900);

        return new self(
            max(3, min($max, 20)),
            max(60, min($window, 86400)),
            max(60, min($lockout, 86400))
        );
    }

    public function isBlocked(string $email, ?string $ip = null): bool
    {
        $fingerprint = $this->fingerprint($email, $ip);
        $row = db_one(
            'SELECT `failed_count`, `last_attempt_at`
             FROM `login_attempts` WHERE `fingerprint` = ? LIMIT 1',
            [$fingerprint]
        );
        if ($row === null) {
            return false;
        }

        $last = strtotime((string) ($row['last_attempt_at'] ?? ''));
        if ($last === false || time() - $last >= $this->lockoutSeconds) {
            return false;
        }

        return (int) ($row['failed_count'] ?? 0) >= $this->maxAttempts;
    }

    public function recordFailure(string $email, ?string $ip = null): void
    {
        $email = UserRepository::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254) {
            return;
        }

        $ip = $this->safeIp($ip);
        $fingerprint = $this->fingerprint($email, $ip);
        $now = date('Y-m-d H:i:s');

        db_transaction(function () use ($email, $ip, $fingerprint, $now): void {
            // Opportunistically prune only data outside two lockout windows.
            // This keeps the aggregate table bounded on hosts without cron.
            $cutoff = date(
                'Y-m-d H:i:s',
                time() - (2 * max($this->windowSeconds, $this->lockoutSeconds))
            );
            db_execute(
                'DELETE FROM `login_attempts` WHERE `last_attempt_at` < ?',
                [$cutoff]
            );

            $row = db_one(
                'SELECT `id`, `failed_count`, `first_attempt_at`, `last_attempt_at`
                 FROM `login_attempts` WHERE `fingerprint` = ? LIMIT 1',
                [$fingerprint]
            );

            if ($row === null) {
                db_insert('login_attempts', [
                    'fingerprint' => $fingerprint,
                    'email' => $email,
                    'ip_address' => $ip,
                    'failed_count' => 1,
                    'first_attempt_at' => $now,
                    'last_attempt_at' => $now,
                ]);
                return;
            }

            $last = strtotime((string) ($row['last_attempt_at'] ?? ''));
            $expired = $last === false || time() - $last >= $this->windowSeconds;
            $count = $expired ? 1 : min(255, (int) ($row['failed_count'] ?? 0) + 1);
            $first = $expired ? $now : (string) $row['first_attempt_at'];

            db_update('login_attempts', [
                'failed_count' => $count,
                'first_attempt_at' => $first,
                'last_attempt_at' => $now,
            ], ['id' => (int) $row['id']]);
        });
    }

    public function clear(string $email, ?string $ip = null): void
    {
        $email = UserRepository::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254) {
            return;
        }

        db_delete('login_attempts', ['fingerprint' => $this->fingerprint($email, $ip)]);
    }

    private function fingerprint(string $email, ?string $ip): string
    {
        return hash('sha256', UserRepository::normalizeEmail($email) . "\0" . $this->safeIp($ip));
    }

    private function safeIp(?string $ip): string
    {
        $candidate = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : '0.0.0.0';
    }
}
