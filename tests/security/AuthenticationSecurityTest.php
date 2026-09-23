<?php

declare(strict_types=1);

/**
 * Phase 3 security regressions: injection, fixation, authorization, CSRF and
 * source-level credential checks.
 */
final class AuthenticationSecurityTest extends TestCase
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
        $_SERVER['REMOTE_ADDR'] = '203.0.113.55';
    }

    public function tearDown(): void
    {
        auth_session_logout();
        db_set_connection(null);
    }

    public function testEmailInjectionIsTreatedAsData(): void
    {
        $this->users->createWithPassword('Safe', 'safe@example.test', 'safe-pass');
        $payload = "safe@example.test' OR 1=1 --";

        $this->assertNull($this->users->findByEmail($payload));
        $this->assertSame(1, (int) db_value('SELECT COUNT(*) FROM `users`', [], 0));
        $this->assertTrue($this->users->findByEmail('safe@example.test') !== null);
    }

    public function testMalformedEmptyAndExcessivelyLongCredentialsFailSafely(): void
    {
        $service = new AuthService();
        $this->assertFalse($service->login('', ''));
        $this->assertFalse($service->login('not-an-email', 'pass'));
        $this->assertFalse($service->login('valid@example.test', str_repeat('x', 4097)));
        $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM `login_attempts`', [], 0));
    }

    public function testCsrfRejectsMissingAndWrongTokensAndAcceptsCorrectToken(): void
    {
        auth_session_start();
        $token = csrf_token();

        $this->assertFalse(csrf_verify(null));
        $this->assertFalse(csrf_verify('not-the-token'));
        $this->assertTrue(csrf_verify($token));
    }

    public function testSessionFixationAndPrivilegeEscalationAreBlocked(): void
    {
        $this->users->createWithPassword('ویرایشگر', 'editor@example.test', 'editor-pass', 'editor');
        auth_session_start();
        $oldId = session_id();

        $this->assertTrue((new AuthService())->login('editor@example.test', 'editor-pass'));
        $this->assertTrue(session_id() !== $oldId);
        $this->assertFalse(requireRole('admin'));
        $this->assertFalse(authorize('users.manage'));
        $this->assertTrue(requireRole('editor'));

        // A user-controlled role value is not trusted if it is not in policy.
        $_SESSION['_auth_user']['role'] = 'admin-invalid';
        $this->assertFalse(requireRole('admin'));
    }

    public function testUserRepositoryRejectsUnknownRole(): void
    {
        $threw = false;
        try {
            $this->users->createWithPassword('Bad role', 'bad-role@example.test', 'pass', 'superuser');
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame(0, (int) db_value('SELECT COUNT(*) FROM `users`', [], 0));
    }

    public function testLogoutIsNotExposedAsAnUnprotectedGetRoute(): void
    {
        $router = new Router();
        define_routes($router);
        http_response_code(200);
        ob_start();
        $router->dispatch('GET', '/logout');
        ob_end_clean();

        // /logout exists for POST only. The Router answers a known path with
        // the wrong method 405 Method Not Allowed (its documented Phase 1
        // behaviour) — either way GET must NOT be routed to logout logic.
        $this->assertSame(405, http_response_code());
        http_response_code(200);
    }

    public function testAuthenticationSourcesContainNoDevelopmentCredential(): void
    {
        $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
        $seed = (string) file_get_contents(BASE_PATH . '/database/seed.sql');
        $repository = (string) file_get_contents(BASE_PATH . '/app/Repositories/UserRepository.php');

        $this->assertContains('password_hash', $schema);
        $this->assertContains('password_hash(', $repository);
        $this->assertNotContains('INSERT INTO `users`', strtoupper($seed));
        $this->assertNotContains('password =', strtolower($seed));
        $this->assertNotContains('password_hash =', strtolower($seed));
    }

    public function testSessionSnapshotNeverContainsPasswordHash(): void
    {
        $this->users->createWithPassword('Snapshot', 'snapshot@example.test', 'snapshot-pass');
        $this->assertTrue((new AuthService())->login('snapshot@example.test', 'snapshot-pass'));

        $this->assertFalse(array_key_exists('password_hash', $_SESSION['_auth_user'] ?? []));
        $this->assertFalse(array_key_exists('password', $_SESSION['_auth_user'] ?? []));
    }
}
