<?php

declare(strict_types=1);

/**
 * Phase 3 unit coverage: password primitives, session/CSRF helpers and role
 * policy. Database-backed login flows live in AuthenticationTest.
 */
final class AuthTest extends TestCase
{
    public function tearDown(): void
    {
        auth_session_logout();
    }

    public function testPasswordHashAndVerification(): void
    {
        $password = 'phase-three-test-passphrase';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->assertTrue(is_string($hash));
        $this->assertNotContains($password, (string) $hash, 'a password hash must not contain the plaintext password');
        $this->assertTrue(password_verify($password, (string) $hash));
        $this->assertFalse(password_verify('wrong-password', (string) $hash));
    }

    public function testCsrfTokenIsSessionBackedAndIndependentOfSessionId(): void
    {
        auth_session_start();
        $sessionId = session_id();
        $token = csrf_token();

        $this->assertMatches('/^[a-f0-9]{64}$/', $token);
        $this->assertFalse($token === $sessionId, 'CSRF token must not be the session ID');
        $this->assertTrue(csrf_verify($token));
        $this->assertFalse(csrf_verify('invalid-token'));

        $rotated = csrf_regenerate();
        $this->assertFalse($rotated === $token, 'CSRF token should rotate when requested');
        $this->assertTrue(csrf_verify($rotated));
    }

    public function testSessionRegenerationChangesTheIdentifier(): void
    {
        auth_session_start();
        $before = session_id();
        auth_session_regenerate();

        $this->assertTrue(session_id() !== '' && session_id() !== $before);
    }

    public function testRoleChecksAreServerSideAndHierarchical(): void
    {
        SessionManager::authenticate([
            'id' => 7,
            'name' => 'کاربر فارسی',
            'email' => 'role-test@example.test',
            'role' => 'editor',
            'is_active' => true,
        ]);

        $this->assertTrue(isAuthenticated());
        $this->assertTrue(requireAuth());
        $this->assertTrue(requireRole('editor'));
        $this->assertTrue(requireRole('user'));
        $this->assertFalse(requireRole('admin'));
        $this->assertTrue(authorize('content.edit'));
        $this->assertFalse(authorize('users.manage'));
        $this->assertFalse(requireGuest());
    }

    public function testInactiveSessionSnapshotCannotAuthorize(): void
    {
        SessionManager::authenticate([
            'id' => 8,
            'name' => 'کاربر غیرفعال',
            'email' => 'inactive@example.test',
            'role' => 'admin',
            'is_active' => false,
        ]);

        $this->assertFalse(isAuthenticated());
        $this->assertFalse(requireRole('admin'));
        $this->assertTrue(requireGuest());
    }

    public function testSafeRedirectRejectsExternalTargets(): void
    {
        $this->assertSame('/articles/one?tab=body', safe_redirect_path('/articles/one?tab=body'));
        $this->assertSame('/', safe_redirect_path('https://evil.example/steal'));
        $this->assertSame('/', safe_redirect_path('//evil.example/steal'));
        $this->assertSame('/', safe_redirect_path("/ok\r\nLocation: https://evil.example"));
    }
}
