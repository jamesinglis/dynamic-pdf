<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HostValidationTest extends TestCase
{
    public static int $calls = 0;
    public static array $received = [];

    protected function setUp(): void
    {
        self::$calls = 0;
        self::$received = [];
    }

    public function testNoHookKeyKeepsTheIncomingResult(): void
    {
        $this->assertTrue(host_arguments_valid(true, [], [], 'default'));
    }

    public function testEmptyHookKeepsTheIncomingResult(): void
    {
        $this->assertTrue(host_arguments_valid(true, ['validate_arguments_callback' => ''], [], 'default'));
    }

    public function testNonCallableHookKeepsTheIncomingResult(): void
    {
        $this->assertTrue(host_arguments_valid(true, ['validate_arguments_callback' => 'a10_no_such_function'], [], 'default'));
    }

    public function testHookReturningTruePasses(): void
    {
        $this->assertTrue(host_arguments_valid(true, ['validate_arguments_callback' => 'a10_hook_true'], [], 'default'));
        $this->assertSame(1, self::$calls);
    }

    public function testHookReturningFalseFails(): void
    {
        $this->assertFalse(host_arguments_valid(true, ['validate_arguments_callback' => 'a10_hook_false'], [], 'default'));
    }

    public function testHookReturningNullPassesLikePerArgumentValidation(): void
    {
        $this->assertTrue(host_arguments_valid(true, ['validate_arguments_callback' => 'a10_hook_null'], [], 'default'));
    }

    public function testHookReceivesArgumentsHostConfigurationAndHostName(): void
    {
        $host = ['slug' => 'X', 'validate_arguments_callback' => 'a10_hook_record'];
        $arguments = ['name' => ['name' => 'name', 'original' => 'Zoë', 'active' => 'ZOË']];

        host_arguments_valid(true, $host, $arguments, 'certificate.example.com');

        $this->assertSame([$arguments, $host, 'certificate.example.com'], self::$received);
    }

    public function testFailedArgumentStaysFailedAndTheHookIsNotCalled(): void
    {
        $this->assertFalse(host_arguments_valid(false, ['validate_arguments_callback' => 'a10_hook_true'], [], 'default'));
        $this->assertSame(0, self::$calls);
    }

    public function testOnlyOneSampleHostCallbackShips(): void
    {
        $this->assertFalse(function_exists('host_validate_custom_callback'));
        $this->assertTrue(validate_arguments_custom_callback([], [], 'default'));
    }

    public function testDefaultsDeclareTheHookKey(): void
    {
        require __DIR__ . '/../defaults.php';
        $this->assertSame('', $default_host['validate_arguments_callback']);
    }
}

function a10_hook_true(array $url_arguments, array $host_configuration, string $host_name): bool
{
    HostValidationTest::$calls++;
    return true;
}

function a10_hook_false(array $url_arguments, array $host_configuration, string $host_name): bool
{
    HostValidationTest::$calls++;
    return false;
}

function a10_hook_null(array $url_arguments, array $host_configuration, string $host_name): ?bool
{
    return null;
}

function a10_hook_record(array $url_arguments, array $host_configuration, string $host_name): bool
{
    HostValidationTest::$received = [$url_arguments, $host_configuration, $host_name];
    return true;
}
