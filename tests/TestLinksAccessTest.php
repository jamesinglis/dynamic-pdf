<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TestLinksAccessTest extends TestCase
{
    private const ENVIRONMENTS = [
        'dev' => ['url' => 'https://site.ddev.site:8443', 'key' => ''],
        'prod' => ['url' => 'https://certificate.example.com', 'key' => 'prodkey123'],
        'prod_www' => ['url' => 'https://www.example.com/', 'key' => 'wwwkey456'],
    ];

    public function testListedHostWithEmptyKeyIsOpen(): void
    {
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'site.ddev.site:8443', ''));
    }

    public function testListedHostMatchesWithoutItsPort(): void
    {
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'site.ddev.site', ''));
    }

    public function testListedHostWithItsOwnKeyIsAllowed(): void
    {
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'certificate.example.com', 'prodkey123'));
    }

    public function testListedHostMatchesCaseInsensitively(): void
    {
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'Certificate.Example.com', 'prodkey123'));
    }

    public function testListedHostWithWrongKeyIsDenied(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'certificate.example.com', 'nope'));
    }

    public function testListedHostWithNoKeyIsDenied(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'certificate.example.com', ''));
    }

    public function testListedHostDoesNotAcceptAnotherEnvironmentsKey(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'certificate.example.com', 'wwwkey456'));
    }

    public function testUnlistedHostAcceptsAnyConfiguredKey(): void
    {
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'phpstack-1-2.cloudwaysapps.com', 'wwwkey456'));
        $this->assertTrue(test_links_access_allowed(self::ENVIRONMENTS, 'phpstack-1-2.cloudwaysapps.com', 'prodkey123'));
    }

    public function testUnlistedHostWithWrongKeyIsDenied(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'example.com', 'nope'));
    }

    public function testUnlistedHostWithNoKeyIsDenied(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'example.com', ''));
    }

    public function testUnlistedHostIsDeniedWhenNoKeysAreConfigured(): void
    {
        $only_dev = ['dev' => self::ENVIRONMENTS['dev']];

        $this->assertFalse(test_links_access_allowed($only_dev, 'example.com', ''));
        $this->assertFalse(test_links_access_allowed($only_dev, 'example.com', 'anything'));
    }

    public function testEmptyEnvironmentsDenyEverything(): void
    {
        $this->assertFalse(test_links_access_allowed([], 'certificate.example.com', ''));
        $this->assertFalse(test_links_access_allowed([], 'certificate.example.com', 'prodkey123'));
    }

    public function testArrayKeyIsDeniedNotAnError(): void
    {
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'certificate.example.com', ['prodkey123']));
        $this->assertFalse(test_links_access_allowed(self::ENVIRONMENTS, 'example.com', ['prodkey123']));
    }

    public function testMalformedEnvironmentEntriesAreSkippedQuietly(): void
    {
        $environments = [
            'no_url' => ['key' => 'k1'],
            'bad_url' => ['url' => 'http:///nope', 'key' => 'k2'],
            'not_an_array' => 'https://weird.example.com',
            'prod' => self::ENVIRONMENTS['prod'],
        ];

        $this->assertTrue(test_links_access_allowed($environments, 'certificate.example.com', 'prodkey123'));
        $this->assertFalse(test_links_access_allowed($environments, 'weird.example.com', ''));
        $this->assertSame('', environment_for_host($environments, 'weird.example.com'));
    }

    public function testEnvironmentForHost(): void
    {
        $this->assertSame('prod', environment_for_host(self::ENVIRONMENTS, 'certificate.example.com'));
        $this->assertSame('prod_www', environment_for_host(self::ENVIRONMENTS, 'www.example.com'));
        $this->assertSame('dev', environment_for_host(self::ENVIRONMENTS, 'site.ddev.site:8443'));
        $this->assertSame('', environment_for_host(self::ENVIRONMENTS, 'phpstack-1-2.cloudwaysapps.com'));
        $this->assertSame('', environment_for_host(self::ENVIRONMENTS, ''));
    }
}
