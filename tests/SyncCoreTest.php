<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bin/SyncCore.php';

final class SyncCoreTest extends TestCase
{
    private const UPSTREAM = __DIR__ . '/..';
    private const TAG = '1.1.0';

    private static ?array $files = null;
    private string $dir;

    protected function setUp(): void
    {
        self::$files ??= SyncCore::tagFiles(self::UPSTREAM, self::TAG);
        $this->dir = sys_get_temp_dir() . '/sync-core-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function git(string ...$args): string
    {
        $command = 'git -C ' . escapeshellarg($this->dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        return implode("\n", $output);
    }

    private function initRepo(): void
    {
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'test@example.com');
        $this->git('config', 'user.name', 'Test');
    }

    private function commitAll(string $message = 'commit'): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    private function writeInstanceFiles(): void
    {
        file_put_contents($this->dir . '/config.json', '{"instance": true}');
        file_put_contents($this->dir . '/custom-callbacks.php', "<?php\n// instance\n");
        mkdir($this->dir . '/resources');
        file_put_contents($this->dir . '/resources/template.pdf', 'PDF');
    }

    public function testTagFilesHoldExactlyTheCoreList(): void
    {
        // .gitignore comes along for the superset merge, but is never copied over
        $this->assertSame(array_merge(SyncCore::CORE_FILES, ['.gitignore']), array_keys(self::$files));
    }

    public function testTagFilesMatchGitShowByteForByte(): void
    {
        foreach (['index.php', 'composer.lock', '.htaccess', 'cache/index.php'] as $path) {
            $expected = shell_exec('git -C ' . escapeshellarg(self::UPSTREAM) . ' show ' . escapeshellarg(self::TAG . ':' . $path));
            $this->assertSame($expected, self::$files[$path], $path);
        }
    }

    public function testCoreListLeavesInstanceOwnedAndUpstreamOnlyFilesOut(): void
    {
        foreach (['config.json', 'custom-callbacks.php', 'CLAUDE.md', 'phpunit.xml', '.gitignore', 'bulk_create.php', 'config-override.json'] as $path) {
            $this->assertNotContains($path, SyncCore::CORE_FILES, $path);
        }
        foreach (SyncCore::CORE_FILES as $path) {
            $this->assertStringStartsNotWith('tests/', $path);
            $this->assertStringStartsNotWith('resources/', $path);
        }
    }

    public function testAMissingTagIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('9.9.9');
        SyncCore::tagFiles(self::UPSTREAM, '9.9.9');
    }

    public function testATagWhoseVersionConstantDisagreesIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DYNAMIC_PDF_VERSION');
        SyncCore::assertVersionMatches('1.1.1', self::$files['helpers.php']);
    }

    public function testManifestHashesEveryCoreFile(): void
    {
        $manifest = SyncCore::manifest(self::$files, self::TAG);
        $this->assertSame(self::TAG, $manifest['version']);
        $this->assertSame(SyncCore::CORE_FILES, array_keys($manifest['files']));
        $this->assertSame(hash('sha256', self::$files['index.php']), $manifest['files']['index.php']);
        $this->assertArrayHasKey('.gitignore', $manifest);
    }

    public function testSyncIntoAnEmptyInstanceMakesItClean(): void
    {
        $this->writeInstanceFiles();
        $result = SyncCore::sync($this->dir, self::$files, self::TAG);

        $this->assertSame(SyncCore::CORE_FILES, $result['written']);
        foreach (SyncCore::CORE_FILES as $path) {
            $this->assertSame(self::$files[$path], file_get_contents($this->dir . '/' . $path), $path);
        }
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));
        $this->assertTrue(SyncCore::isClean($report), json_encode($report));
    }

    public function testSyncLeavesInstanceOwnedFilesAlone(): void
    {
        $this->writeInstanceFiles();
        SyncCore::sync($this->dir, self::$files, self::TAG);

        $this->assertSame('{"instance": true}', file_get_contents($this->dir . '/config.json'));
        $this->assertSame("<?php\n// instance\n", file_get_contents($this->dir . '/custom-callbacks.php'));
        $this->assertSame('PDF', file_get_contents($this->dir . '/resources/template.pdf'));
    }

    public function testSyncWritesTheVersionMarker(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        $marker = json_decode(file_get_contents($this->dir . '/' . SyncCore::MARKER), true);

        $this->assertSame(self::TAG, $marker['version']);
        $this->assertSame(SyncCore::manifest(self::$files, self::TAG)['files'], $marker['files']);
    }

    public function testTheMarkerIsBlockedByTheShippedHtaccess(): void
    {
        // .htaccess blocks \.(json|lock|...)$; nginx hands .json to Apache on Cloudways (A12)
        $this->assertStringEndsWith('.json', SyncCore::MARKER);
        $this->assertStringContainsString('RewriteRule \.(json|lock|yaml|yml|log)$ - [F,L]', self::$files['.htaccess']);
    }

    public function testSyncDeletesRemovedCoreFiles(): void
    {
        file_put_contents($this->dir . '/bulk_create.php', "<?php\n");
        $result = SyncCore::sync($this->dir, self::$files, self::TAG);

        $this->assertFileDoesNotExist($this->dir . '/bulk_create.php');
        $this->assertSame(['bulk_create.php'], $result['deleted']);
    }

    public function testARemovedFileDeletedButNotYetCommittedIsNotReportedPresent(): void
    {
        $this->initRepo();
        file_put_contents($this->dir . '/bulk_create.php', "<?php\n");
        $this->commitAll();
        SyncCore::sync($this->dir, self::$files, self::TAG);

        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));
        $this->assertSame([], $report['removed_present']);
    }

    public function testCheckFlagsDriftMissingAndRemovedFiles(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        file_put_contents($this->dir . '/index.php', "<?php\n// drifted\n");
        unlink($this->dir . '/rotate-keys.php');
        file_put_contents($this->dir . '/bulk_create.php', "<?php\n");

        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));

        $this->assertSame(['index.php'], $report['drift']);
        $this->assertSame(['rotate-keys.php'], $report['missing']);
        $this->assertSame(['bulk_create.php'], $report['removed_present']);
        $this->assertFalse(SyncCore::isClean($report));
    }

    public function testCheckReportsAMissingOrStaleMarker(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        unlink($this->dir . '/' . SyncCore::MARKER);
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));
        $this->assertNull($report['marker']);
        $this->assertFalse(SyncCore::isClean($report));

        file_put_contents($this->dir . '/' . SyncCore::MARKER, json_encode(['version' => '1.0.1']));
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));
        $this->assertSame('1.0.1', $report['marker']);
        $this->assertFalse(SyncCore::isClean($report));
    }

    public function testGitignoreGainsMissingUpstreamLinesAndLosesTheComposerLockLine(): void
    {
        file_put_contents($this->dir . '/.gitignore', "vendor\ncomposer.lock\n/composer.lock\nmy-instance-thing\n");
        $result = SyncCore::sync($this->dir, self::$files, self::TAG);
        $gitignore = file_get_contents($this->dir . '/.gitignore');
        $lines = explode("\n", trim($gitignore));

        $this->assertContains('my-instance-thing', $lines);
        $this->assertContains('.DS_Store', $lines);
        $this->assertContains('.claude/', $lines);
        $this->assertContains('resources/recipients.csv', $lines);
        $this->assertNotContains('composer.lock', $lines);
        $this->assertNotContains('/composer.lock', $lines);
        $this->assertSame(1, count(array_keys($lines, 'vendor')));
        $this->assertTrue($result['gitignore_changed']);
    }

    public function testCheckFlagsGitignoreGaps(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        file_put_contents($this->dir . '/.gitignore', "vendor\ncomposer.lock\n");
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));

        $this->assertContains('.DS_Store', $report['gitignore_missing']);
        $this->assertSame(['composer.lock'], $report['gitignore_forbidden']);
        $this->assertFalse(SyncCore::isClean($report));
    }

    public function testCheckOnADeployedCopyWithoutGitignoreSkipsTheGitignoreCheck(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        unlink($this->dir . '/.gitignore');
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));

        $this->assertNull($report['gitignore_missing']);
        $this->assertTrue(SyncCore::isClean($report), json_encode($report));
    }

    public function testACaseCollidingReadmeIsRefusedAndLeftUntouched(): void
    {
        file_put_contents($this->dir . '/README.md', "instance readme\n");

        try {
            SyncCore::sync($this->dir, self::$files, self::TAG);
            $this->fail('Expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('README.md', $e->getMessage());
        }
        $this->assertSame(['README.md'], array_values(array_filter(scandir($this->dir), fn ($f) => $f[0] !== '.')));
        $this->assertSame("instance readme\n", file_get_contents($this->dir . '/README.md'));
    }

    public function testACaseCollisionOnlyGitCanSeeIsRefused(): void
    {
        // 86k-workplace: git tracks README.md while APFS lists the same file as readme.md
        $this->initRepo();
        file_put_contents($this->dir . '/README.md', "instance readme\n");
        $this->commitAll();
        rename($this->dir . '/README.md', $this->dir . '/tmp-readme');
        rename($this->dir . '/tmp-readme', $this->dir . '/readme.md');

        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));
        $this->assertSame(['README.md'], $report['case_collisions']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('README.md');
        SyncCore::sync($this->dir, self::$files, self::TAG);
    }

    public function testCheckReportsCaseCollisions(): void
    {
        SyncCore::sync($this->dir, self::$files, self::TAG);
        unlink($this->dir . '/readme.md');
        file_put_contents($this->dir . '/README.md', "instance readme\n");
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest(self::$files, self::TAG));

        $this->assertSame(['README.md'], $report['case_collisions']);
        $this->assertFalse(SyncCore::isClean($report));
    }

    public function testSyncRefusesUncommittedChangesToCoreFiles(): void
    {
        $this->initRepo();
        file_put_contents($this->dir . '/index.php', "<?php\n// committed\n");
        $this->commitAll();
        file_put_contents($this->dir . '/index.php', "<?php\n// uncommitted edit\n");

        try {
            SyncCore::sync($this->dir, self::$files, self::TAG);
            $this->fail('Expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('index.php', $e->getMessage());
        }
        $this->assertSame("<?php\n// uncommitted edit\n", file_get_contents($this->dir . '/index.php'));
    }

    public function testSyncIgnoresUncommittedInstanceFiles(): void
    {
        $this->initRepo();
        $this->writeInstanceFiles();
        $this->commitAll();
        file_put_contents($this->dir . '/config.json', '{"edited": true}');

        $result = SyncCore::sync($this->dir, self::$files, self::TAG);
        $this->assertSame(SyncCore::CORE_FILES, $result['written']);
    }

    public function testSyncNeverCommits(): void
    {
        $this->initRepo();
        $this->writeInstanceFiles();
        $this->commitAll('base');
        SyncCore::sync($this->dir, self::$files, self::TAG);

        $this->assertSame('1', trim($this->git('rev-list', '--count', 'HEAD')));
        $this->assertNotSame('', $this->git('status', '--porcelain'));
    }

    public function testSyncRefusesTheUpstreamRepository(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('upstream');
        SyncCore::sync(self::UPSTREAM, self::$files, self::TAG);
    }

    public function testCheckRefReadsACommittedTreeWithoutCheckingItOut(): void
    {
        $this->initRepo();
        $this->writeInstanceFiles();
        SyncCore::sync($this->dir, self::$files, self::TAG);
        $this->commitAll('synced');
        $this->git('branch', 'deploy');
        file_put_contents($this->dir . '/index.php', "<?php\n// later drift\n");
        file_put_contents($this->dir . '/bulk_create.php', "<?php\n");
        $this->commitAll('drift');

        $manifest = SyncCore::manifest(self::$files, self::TAG);
        $clean = SyncCore::checkRef($this->dir, 'deploy', $manifest);
        $drifted = SyncCore::checkRef($this->dir, 'HEAD', $manifest);

        $this->assertTrue(SyncCore::isClean($clean), json_encode($clean));
        $this->assertSame(self::TAG, $clean['marker']);
        $this->assertSame(['index.php'], $drifted['drift']);
        $this->assertSame(['bulk_create.php'], $drifted['removed_present']);
    }

    public function testCheckRefFlagsTrackedVendor(): void
    {
        $this->initRepo();
        SyncCore::sync($this->dir, self::$files, self::TAG);
        mkdir($this->dir . '/vendor');
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\n");
        $this->git('add', '-A');
        $this->git('add', '-f', 'vendor/autoload.php');
        $this->git('commit', '-q', '-m', 'with vendor');

        $report = SyncCore::checkRef($this->dir, 'HEAD', SyncCore::manifest(self::$files, self::TAG));
        $this->assertTrue($report['vendor_tracked']);
        $this->assertFalse(SyncCore::isClean($report));
    }

    public function testCheckRefOnAnUnknownRefIsAnError(): void
    {
        $this->initRepo();
        file_put_contents($this->dir . '/x', 'x');
        $this->commitAll();
        $this->expectException(RuntimeException::class);
        SyncCore::checkRef($this->dir, 'no-such-branch', SyncCore::manifest(self::$files, self::TAG));
    }
}
