<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Run the actual class in an isolated namespace so deterministic I/O faults need no production test hooks.
$syncSource = file_get_contents(__DIR__ . '/../bin/SyncCore.php');
$syncSource = str_replace('declare(strict_types=1);', <<<'SOURCE'
declare(strict_types=1);
namespace SyncCoreFaultFixture;
use \RuntimeException;
use \Throwable;
use \ParseError;
final class Faults {
    public static int $renames = 0;
    public static array $fail = [];
}
function rename($source, $target): bool {
    return in_array(++Faults::$renames, Faults::$fail, true) ? false : \rename($source, $target);
}
SOURCE, $syncSource);
eval(substr($syncSource, 5));

final class SyncCoreRollbackTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sync-rollback-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
        \SyncCoreFaultFixture\Faults::$renames = 0;
        \SyncCoreFaultFixture\Faults::$fail = [];
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) as $file) {
            if ($file !== '.' && $file !== '..') { unlink($this->dir . '/' . $file); }
        }
        rmdir($this->dir);
    }

    private function apply(array $changes): void
    {
        $method = new ReflectionMethod(\SyncCoreFaultFixture\SyncCore::class, 'applyTransaction');
        $method->invoke(null, $this->dir, $changes);
    }

    public function testPublicationFailureRestoresReadOnlyFilesModesDeletedFilesAndNewFiles(): void
    {
        file_put_contents($this->dir . '/readonly', 'original');
        chmod($this->dir . '/readonly', 0444);
        file_put_contents($this->dir . '/removed', 'removed-original');
        file_put_contents($this->dir . '/last', 'last-original');
        // readonly, new-file and last are renames; deletion is an unlink. Fail the last publication.
        \SyncCoreFaultFixture\Faults::$fail = [3];
        try {
            $this->apply(['readonly' => 'replacement', 'new-file' => 'created', 'removed' => null, 'last' => 'last-replacement']);
            $this->fail('Expected publication failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Cannot publish last.', $error->getMessage());
        }
        clearstatcache();
        $this->assertSame('original', file_get_contents($this->dir . '/readonly'));
        $this->assertSame(0444, fileperms($this->dir . '/readonly') & 0777);
        $this->assertSame('removed-original', file_get_contents($this->dir . '/removed'));
        $this->assertSame('last-original', file_get_contents($this->dir . '/last'));
        $this->assertFileDoesNotExist($this->dir . '/new-file');
        $this->assertSame([], glob($this->dir . '/.sync-core-*'));
    }

    public function testRollbackContinuesAfterOneRestoreFailsAndRetainsItsBackup(): void
    {
        foreach (['first', 'second', 'third'] as $file) { file_put_contents($this->dir . '/' . $file, 'old-' . $file); }
        // Third publication fails, then restoring second fails. Restoring first must still run.
        \SyncCoreFaultFixture\Faults::$fail = [3, 4];
        try {
            $this->apply(['first' => 'new-first', 'second' => 'new-second', 'third' => 'new-third']);
            $this->fail('Expected publication and rollback failure');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Rollback failed for second', $error->getMessage());
            $this->assertStringContainsString('original backup files retained:', $error->getMessage());
        }
        $this->assertSame('old-first', file_get_contents($this->dir . '/first'));
        $this->assertSame('new-second', file_get_contents($this->dir . '/second'));
        $this->assertSame('old-third', file_get_contents($this->dir . '/third'));
        $backups = glob($this->dir . '/.sync-core-backup-*');
        $this->assertCount(1, $backups);
        $this->assertSame('old-second', file_get_contents($backups[0]));
    }
}
