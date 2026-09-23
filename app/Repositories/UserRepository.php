<?php

declare(strict_types=1);

/**
 * UserRepository — the only data access class for authentication identities.
 *
 * It accepts a plaintext password only at the creation boundary and hashes it
 * immediately. No method writes a plaintext password to SQL or returns one.
 */
final class DuplicateEmailException extends RuntimeException
{
}

final class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    /** Roles supported by the Phase 3 policy. */
    public const ROLES = ['admin', 'editor', 'user'];

    /**
     * Normalize the login identifier without changing a user's display name.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Find an account by its normalized email address.
     *
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $email = self::normalizeEmail($email);
        if (!$this->validEmail($email)) {
            return null;
        }

        return db_one(
            'SELECT `id`, `name`, `email`, `password_hash`, `role`, `is_active`,
                    `created_at`, `updated_at`, `last_login_at`
             FROM `users` WHERE `email` = ? LIMIT 1',
            [$email]
        );
    }

    /**
     * Create a user. The preferred input is `password`; it is hashed inside
     * this method. `password_hash` is accepted only for importing an already
     * hashed value and is validated as a password hash, never as plain text.
     *
     * @param array<string,mixed> $data name, email, password, role, is_active
     */
    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = self::normalizeEmail((string) ($data['email'] ?? ''));
        $role = (string) ($data['role'] ?? 'user');
        $this->assertUserInput($name, $email, $role);

        $hash = $this->passwordHashFrom($data);
        $active = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        if ($this->emailExists($email)) {
            throw new DuplicateEmailException('That email address is already registered.');
        }

        try {
            return db_insert('users', [
                'name' => $name,
                'email' => $email,
                'password_hash' => $hash,
                'role' => $role,
                'is_active' => $active ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            // The unique index is the final race-safe authority.
            if (str_starts_with((string) $e->getCode(), '23')) {
                throw new DuplicateEmailException('That email address is already registered.', 0, $e);
            }
            throw $e;
        }
    }

    public function createWithPassword(
        string $name,
        string $email,
        string $password,
        string $role = 'user',
        bool $active = true
    ): int {
        return $this->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $email = self::normalizeEmail($email);
        if (!$this->validEmail($email)) {
            return false;
        }

        if ($ignoreId === null) {
            return (int) db_value('SELECT COUNT(*) FROM `users` WHERE `email` = ?', [$email], 0) > 0;
        }

        return (int) db_value(
            'SELECT COUNT(*) FROM `users` WHERE `email` = ? AND `id` <> ?',
            [$email, $ignoreId],
            0
        ) > 0;
    }

    public function markLogin(int $id): bool
    {
        return db_execute(
            'UPDATE `users` SET `last_login_at` = CURRENT_TIMESTAMP WHERE `id` = ?',
            [$id]
        ) > 0;
    }

    public function setActive(int $id, bool $active): bool
    {
        return db_update('users', ['is_active' => $active ? 1 : 0], ['id' => $id]) > 0;
    }

    /**
     * Admin-safe user lookup (never returns password_hash).
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return db_one(
            'SELECT `id`, `name`, `email`, `role`, `is_active`, `created_at`, `updated_at`, `last_login_at`
             FROM `users` WHERE `id` = ? LIMIT 1',
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function adminList(string $search = '', int $limit = 50, int $offset = 0): array
    {
        [$limit, $offset] = $this->paging($limit, $offset, 100);
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(`name` LIKE ? ESCAPE \'=\' OR `email` LIKE ? ESCAPE \'=\')';
            $q = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
            array_push($params, $q, $q);
        }

        return db_all(
            sprintf(
                'SELECT `id`, `name`, `email`, `role`, `is_active`, `created_at`, `updated_at`, `last_login_at`
                 FROM `users`%s ORDER BY `created_at` DESC, `id` DESC LIMIT %d OFFSET %d',
                $where ? ' WHERE ' . implode(' AND ', $where) : '',
                $limit,
                $offset
            ),
            $params
        );
    }

    public function adminCount(string $search = ''): int
    {
        if ($search === '') {
            return (int) db_value('SELECT COUNT(*) FROM `users`', [], 0);
        }
        $q = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';

        return (int) db_value(
            'SELECT COUNT(*) FROM `users` WHERE (`name` LIKE ? ESCAPE \'=\' OR `email` LIKE ? ESCAPE \'=\')',
            [$q, $q],
            0
        );
    }

    /** @param array<string,mixed> $data */
    public function updateUser(int $id, array $data): bool
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = self::normalizeEmail((string) ($data['email'] ?? ''));
        $role = (string) ($data['role'] ?? 'user');
        $this->assertUserInput($name, $email, $role);
        if ($this->emailExists($email, $id)) {
            throw new DuplicateEmailException('That email address is already registered.');
        }

        $payload = [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
        $password = (string) ($data['password'] ?? '');
        if ($password !== '') {
            $payload['password_hash'] = $this->passwordHashFrom(['password' => $password]);
        }

        return db_update('users', $payload, ['id' => $id]) > 0;
    }

    public function countActiveAdmins(?int $ignoreId = null): int
    {
        if ($ignoreId === null) {
            return (int) db_value('SELECT COUNT(*) FROM `users` WHERE `role` = \'admin\' AND `is_active` = 1', [], 0);
        }

        return (int) db_value(
            'SELECT COUNT(*) FROM `users` WHERE `role` = \'admin\' AND `is_active` = 1 AND `id` <> ?',
            [$ignoreId],
            0
        );
    }

    private function passwordHashFrom(array $data): string
    {
        if (isset($data['password'])) {
            $password = $data['password'];
            if (!is_string($password) || $password === '' || strlen($password) > 4096) {
                throw new InvalidArgumentException('Password is empty or too long.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new RuntimeException('Password hashing failed.');
            }

            return $hash;
        }

        $hash = $data['password_hash'] ?? null;
        if (!is_string($hash) || password_get_info($hash)['algo'] === 0) {
            throw new InvalidArgumentException('A valid password hash is required.');
        }

        return $hash;
    }

    private function assertUserInput(string $name, string $email, string $role): void
    {
        if ($name === '' || $this->characterLength($name) > 160) {
            throw new InvalidArgumentException('Name is empty or too long.');
        }
        if (preg_match('//u', $name) !== 1 || !$this->validEmail($email)) {
            throw new InvalidArgumentException('User identity is invalid.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown user role.');
        }
    }

    private function validEmail(string $email): bool
    {
        return strlen($email) <= 254
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);

        return $count === false ? PHP_INT_MAX : $count;
    }
}
