<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config/security_config.php';

final class SecurityConfigTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    public function testSanitizeInputEscapesHtml(): void
    {
        $dirty = '<script>alert("x")</script>';
        $clean = sanitize_input($dirty);
        $this->assertStringNotContainsString('<script>', $clean);
        $this->assertStringNotContainsString('</script>', $clean);
    }

    public function testValidatePasswordStrength(): void
    {
        $weak = validate_password_strength('short');
        $this->assertNotEmpty($weak, 'Weak password should return errors');

        $strong = validate_password_strength('Str0ng!Pass');
        $this->assertEmpty($strong, 'Strong password should not return errors');
    }

    public function testCsrfTokenIsConsistent(): void
    {
        $token1 = generate_csrf_token();
        $token2 = generate_csrf_token();
        $this->assertSame($token1, $token2);
        $this->assertTrue(validate_csrf_token($token1));
    }

    public function testSessionTimeout(): void
    {
        $_SESSION['last_activity'] = time() - 4000;
        $this->assertFalse(check_session_timeout(3600));

        $_SESSION['last_activity'] = time();
        $this->assertTrue(check_session_timeout(3600));
    }

    public function testCsrfTokenInvalid(): void
    {
        generate_csrf_token();
        $this->assertFalse(validate_csrf_token('invalid'));
    }
}
