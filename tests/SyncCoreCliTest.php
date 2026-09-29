<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SyncCoreCliTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/sync-core';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sync-core-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -r ' . escapeshellarg($this->dir));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function cli(string ...$args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BIN);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $status);
        return [$status, implode("\n", $output)];
    }

    public function testSyncThenCheckIsClean(): void
    {
        [$status, $output] = $this->cli('--tag=1.1.0', $this->dir);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('synced to 1.1.0 (13 core files, .gitignore updated)', $output);

        [$status, $output] = $this->cli('--check', '--tag=1.1.0', $this->dir);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('clean at 1.1.0', $output);
    }

    public function testCheckExitsOneOnDrift(): void
    {
        $this->cli('--tag=1.1.0', $this->dir);
        file_put_contents($this->dir . '/helpers.php', "<?php\n");

        [$status, $output] = $this->cli('--check', '--tag=1.1.0', $this->dir);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('DRIFT (marker 1.1.0; drift: helpers.php)', $output);
    }

    public function testAManifestFileChecksWithoutGit(): void
    {
        $this->cli('--tag=1.1.0', $this->dir);
        // stdout only: a PHP startup warning on stderr (the server's json.so notice) must not end up in the JSON
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BIN) . ' --manifest --tag=1.1.0 2>/dev/null', $lines, $status);
        $json = implode("\n", $lines);
        $this->assertSame(0, $status);
        $manifestFile = $this->dir . '/../' . basename($this->dir) . '.manifest.json';
        file_put_contents($manifestFile, $json);

        try {
            [$status, $output] = $this->cli('--check', '--manifest=' . $manifestFile, $this->dir);
        } finally {
            unlink($manifestFile);
        }
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('clean at 1.1.0', $output);
    }

    public function testRefusalsExitTwo(): void
    {
        file_put_contents($this->dir . '/README.md', 'x');
        [$status, $output] = $this->cli('--tag=1.1.0', $this->dir);
        $this->assertSame(2, $status);
        $this->assertStringContainsString('README.md', $output);

        [$status] = $this->cli('--bogus');
        $this->assertSame(2, $status);
    }
}
