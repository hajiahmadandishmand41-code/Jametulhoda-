<?php

declare(strict_types=1);

/**
 * Authentication service: one login attempt, one session boundary.
 *
 * Login failures intentionally have one public outcome for unknown email,
 * wrong password and inactive account. The service also performs a dummy
 * password verification when no account exists so the common paths are less
 * distinguishable by timing.
 */
final class AuthService
{
    public function __construct(
        private readonly ?UserRepository $users = null,
        private readonly ?LoginRateLimiter $rateLimiter = null
    ) {
    }

    public function login(string $email, string $password): bool
    {
        auth_session_start();
        $email = UserRepository::normalizeEmail($email);

        // Do not create unbounded throttle rows for malformed or excessively
        // long identifiers. They cannot match a valid account.
        if (!$this->validLoginInput($email, $password)) {
            return false;
        }

        $limiter = $this->limiter();
        if ($limiter->isBlocked($email)) {
            return false;
        }

        $userRepository = $this->users();
        $user = $userRepository->findByEmail($email);
        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : $this->dummyHash();
        $verified = password_verify($password, $hash);

        $active = is_array($user) && (int) ($user['is_active'] ?? 0) === 1;
        if (!$verified || !$active) {
            $limiter->recordFailure($email);
            return false;
        }

        // Finish database bookkeeping before exposing authenticated state. If
        // a write fails, the caller gets a safe failure and no partial login.
        db_transaction(function () use ($limiter, $email, $userRepository, $user): void {
            $limiter->clear($email);
            $userRepository->markLogin((int) $user['id']);
        });

        // The old identifier is invalidated before any authenticated state is
        // written. This is the session-fixation boundary.
        auth_session_regenerate();
        SessionManager::authenticate([
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'is_active' => true,
        ]);
        csrf_regenerate();

        return true;
    }

    public function logout(): void
    {
        auth_session_logout();
    }

    /**
     * @return array{id:int,name:string,email:string,role:string,is_active:bool}|null
     */
    public function currentUser(): ?array
    {
        return SessionManager::authenticatedUser();
    }

    public function isAuthenticated(): bool
    {
        $user = $this->currentUser();

        return $user !== null && $user['id'] > 0 && $user['is_active'] === true;
    }

    private function users(): UserRepository
    {
        return $this->users ?? new UserRepository();
    }

    private function limiter(): LoginRateLimiter
    {
        return $this->rateLimiter ?? LoginRateLimiter::configured();
    }

    private function validLoginInput(string $email, string $password): bool
    {
        return strlen($email) <= 254
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && strlen($password) <= 4096;
    }

    private function dummyHash(): string
    {
        // This hash is generated per attempt and is never stored. It prevents
        // an unknown email from skipping password_verify() without embedding
        // a password or credential in the source tree.
        $hash = password_hash(random_bytes(32), PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }
}
