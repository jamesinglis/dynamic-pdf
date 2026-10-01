<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseHardeningTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/a20-fixture-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
        foreach (['helpers.php', 'test-links.php', 'index.php', 'defaults.php', 'callbacks.php'] as $file) {
            copy(__DIR__ . '/../' . $file, $this->dir . '/' . $file);
        }
        symlink(realpath(__DIR__ . '/../vendor'), $this->dir . '/vendor');
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) as $file) {
            if ($file !== '.' && $file !== '..') { unlink($this->dir . '/' . $file); }
        }
        rmdir($this->dir);
    }

    private function request(array $config, string $file, string $host, array $query = []): string
    {
        file_put_contents($this->dir . '/config.json', json_encode($config));
        $script = '<?php ob_start(); $_SERVER["HTTP_HOST"] = ' . var_export($host, true)
            . '; $_GET = ' . var_export($query, true) . '; require ' . var_export($this->dir . '/' . $file, true) . ';';
        file_put_contents($this->dir . '/request.php', $script);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->dir . '/request.php') . ' 2>' . escapeshellarg($this->dir . '/request.stderr'), $output, $status);
        $this->assertSame(0, $status, file_get_contents($this->dir . '/request.stderr') . implode("\n", $output));
        return implode("\n", $output);
    }

    public function testTestLinksNeverPublishesConfiguredSecretsOnOpenOrAuthenticatedHosts(): void
    {
        $config = [
            'global' => ['expose_test_links' => true, 'allow_toggle_self_link' => true, 'expire_cache_key' => 'CACHE_SECRET_SENTINEL'],
            'environments' => [
                'dev' => ['url' => 'http://dev.example', 'key' => ''],
                'prod' => ['url' => 'https://prod.example', 'key' => 'PROD_SECRET_SENTINEL'],
                'hidden' => ['url' => 'https://hidden.example', 'key' => 'HIDDEN_SECRET_SENTINEL', 'expose' => false],
            ],
            'hosts' => ['default' => ['private_metadata' => 'HOST_SECRET_SENTINEL']],
            'test_versions' => ['demo' => ['name' => "O'Brien & Zoë", 'amount' => 0, 'label' => 'Example']],
        ];
        foreach ([['dev.example:8443', []], ['prod.example', ['key' => 'PROD_SECRET_SENTINEL']]] as [$host, $query]) {
            $html = $this->request($config, 'test-links.php', $host, $query);
            foreach (['CACHE_SECRET_SENTINEL', 'PROD_SECRET_SENTINEL', 'HIDDEN_SECRET_SENTINEL', 'HOST_SECRET_SENTINEL', 'hidden.example'] as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
            $this->assertStringContainsString('requires_key', $html);
            $this->assertStringContainsString('prod.example', $html);
            $this->assertStringNotContainsString('?key=', $html);
            $this->assertStringContainsString('O%27Brien+%26+Zo%C3%AB', $html);
        }
        $this->assertStringContainsString('Not Found', $this->request($config, 'test-links.php', 'prod.example'));
    }

    public function testIndexDefaultArgumentStripsControlsAndKeepsPunctuationAndAccents(): void
    {
        file_put_contents($this->dir . '/custom-callbacks.php', '<?php function capture_arguments($args, $host, $name) { echo json_encode($args["rsdate"]["active"]); return false; }');
        $config = [
            'global' => ['debug_mode' => false, 'locale' => 'en_AU.UTF-8', 'validate_arguments' => true],
            'hosts' => ['default' => ['active' => true, 'validate_arguments_callback' => 'capture_arguments']],
            'url_arguments' => [['argument' => 'rsdate', 'type' => 'string', 'default' => '', 'mutate_callback' => '']],
        ];
        $actual = $this->request($config, 'index.php', 'dev.example', ['rsdate' => "O'Brien\x00\x01\n\t & Zoë"]);
        $this->assertSame("O'Brien & Zoë", json_decode($actual, true));
    }

    public function testPortlessEnvironmentMatchesRequestWithPort(): void
    {
        $environments = ['prod' => ['url' => 'https://prod.example', 'key' => 12345]];
        $this->assertSame('prod', environment_for_host($environments, 'PROD.EXAMPLE:8443'));
        $this->assertFalse(test_links_access_allowed($environments, 'prod.example:8443', ''));
        $this->assertTrue(test_links_access_allowed($environments, 'prod.example:8443', '12345'));
        $this->assertTrue(test_links_access_allowed($environments, 'unlisted.example', '12345'));
        foreach ([[0, '0'], [true, '1'], [1.25, '1.25']] as [$key, $provided]) {
            $scalar = ['prod' => ['url' => 'https://prod.example', 'key' => $key]];
            $this->assertFalse(test_links_access_allowed($scalar, 'prod.example', ''));
            $this->assertTrue(test_links_access_allowed($scalar, 'prod.example', $provided));
        }
        foreach ([null, [], new stdClass()] as $invalid) {
            $this->assertFalse(test_links_access_allowed(['prod' => ['url' => 'https://prod.example', 'key' => $invalid]], 'prod.example', ''));
        }
        foreach (['user@prod.example', 'prod.example/path', 'prod.example?x=1'] as $invalid) {
            $this->assertSame('', environment_for_host($environments, $invalid));
        }
    }

    public function testSharedCallbacksKeepUtf8AndZeroBoundary(): void
    {
        $this->assertSame('ZOË & JOSÉ', capitalize_input('Zoë & José', []));
        $this->assertSame('SØREN', mutate_to_uppercase('Søren', []));
        foreach (['0', '0.5', '999998.99'] as $valid) { $this->assertTrue(validate_float_under_999999_allow_zero($valid)); }
        foreach (['-0.01', '999999', '1000000'] as $invalid) { $this->assertFalse(validate_float_under_999999_allow_zero($invalid)); }
    }
}
