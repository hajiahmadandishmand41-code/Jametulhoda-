<?php

declare(strict_types=1);

/**
 * Phase 3 integration tests against the installed users and login_attempts
 * tables. SchemaSandbox reports which backend was used in the test output
 * documentation; production claims require a MySQL/MariaDB run too.
 */
final class AuthenticationTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;

    public function setUp(): void
    {
        auth_session_logout();
        $this->pdo = SchemaSandbox::fresh();
        SchemaSandbox::clear($this->pdo);
        db_set_connection($this->pdo);
        $this->users = new UserRepository();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.40';
    }

    public function tearDown(): void
    {
        auth_session_logout();
        db_set_connection(null);
    }

    public function testCreateAndRetrieveUserWithPersianName(): void
    {
        $password = 'integration-passphrase';
        $id = $this->users->createWithPassword(
            'نام فارسی آزمایشی',
            'Persian.User@Example.test',
            $password,
            'user'
        );

        $row = $this->users->find($id);
        $this->assertTrue($row !== null);
        $this->assertSame('نام فارسی آزمایشی', (string) $row['name']);
        $this->assertSame('persian.user@example.test', (string) $row['email']);
        $this->assertTrue(password_verify($password, (string) $row['password_hash']));
        $this->assertNotSameValue($password, (string) $row['password_hash']);
        $this->assertFalse(array_key_exists('password', $row));
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $this->users->createWithPassword('اول', 'duplicate@example.test', 'first-pass');
        $threw = false;
        try {
            $this->users->createWithPassword('دوم', 'DUPLICATE@example.test', 'second-pass');
        } catch (DuplicateEmailException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the unique email rule must be exposed as a safe domain error');
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `users`', [], 0));
    }

    public function testSuccessfulLoginRegeneratesSessionAndStoresNoHash(): void
    {
        $this->users->createWithPassword('ورود موفق', 'success@example.test', 'correct-pass', 'editor');
        auth_session_start();
        $before = session_id();

        $this->assertTrue((new AuthService())->login('SUCCESS@example.test', 'correct-pass'));
        $this->assertTrue(session_id() !== '' && session_id() !== $before);
        $this->assertTrue(isAuthenticated());
        $this->assertSame('editor', (string) currentUser()['role']);
        $this->assertFalse(array_key_exists('password_hash', (array) currentUser()));
        $this->assertTrue(
            (int) db_value('SELECT COUNT(*) FROM `users` WHERE `last_login_at` IS NOT NULL', [], 0) === 1
        );
    }

    public function testWrongPasswordAndUnknownEmailHaveSameFailureOutcome(): void
    {
        $this->users->createWithPassword('ورود ناموفق', 'known@example.test', 'correct-pass');
        $service = new AuthService();

        $wrongPassword = $service->login('known@example.test', 'wrong-pass');
        auth_session_logout();
        $unknownEmail = $service->login('unknown@example.test', 'wrong-pass');

        $this->assertFalse($wrongPassword);
        $this->assertFalse($unknownEmail);
        $this->assertFalse(isAuthenticated());
    }

    public function testInactiveUserCannotLogin(): void
    {
        $this->users->createWithPassword('حساب متوقف', 'inactive@example.test', 'correct-pass', 'admin', false);

        $this->assertFalse((new AuthService())->login('inactive@example.test', 'correct-pass'));
        $this->assertFalse(isAuthenticated());
    }

    public function testLogoutDestroysAuthenticationSession(): void
    {
        $this->users->createWithPassword('خروج', 'logout@example.test', 'correct-pass');
        $service = new AuthService();
        $this->assertTrue($service->login('logout@example.test', 'correct-pass'));
        $this->assertTrue(isAuthenticated());

        $service->logout();

        // session_status() must be read BEFORE isAuthenticated(): the guard
        // calls SessionManager::start(), which legitimately opens a fresh
        // (empty) session — that must not read as "still logged in".
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertFalse(isAuthenticated());
    }

    public function testRateLimiterBlocksRepeatedFailuresWithoutExternalService(): void
    {
        $this->users->createWithPassword('محدودشده', 'limited@example.test', 'correct-pass');
        $service = new AuthService();
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->login('limited@example.test', 'wrong-pass'));
            auth_session_logout();
        }

        $this->assertFalse($service->login('limited@example.test', 'correct-pass'));
        $this->assertTrue(
            (int) db_value('SELECT COUNT(*) FROM `login_attempts` WHERE `failed_count` >= 5', [], 0) >= 1
        );
    }

    private function assertNotSameValue(string $unexpected, string $actual): void
    {
        $this->assertTrue($unexpected !== $actual, 'plaintext password must never be stored');
    }
}
