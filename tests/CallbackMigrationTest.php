<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../bin/SyncCore.php';

final class CallbackMigrationTest extends TestCase
{
    private string $dir;
    private array $files;
    private const CUSTOM = "<?php\n/** Shared */\nfunction capitalize_input(string \$input, array \$url_argument): string { return mb_strtoupper(\$input, 'UTF-8'); }\nfunction mutate_to_uppercase(\$input, \$url_argument) { return mb_strtoupper(\$input, \"UTF-8\"); }\nfunction validate_float_under_999999_allow_zero(\$input): bool { return floatval(\$input) < 999999 && floatval(\$input) >= 0; }\n/** Instance owned */\nfunction mutate_float_local(\$input) { return \$input; }\n";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/a20-sync-' . bin2hex(random_bytes(5));
        mkdir($this->dir);
        $this->files = SyncCore::tagFiles(__DIR__ . '/..', '1.1.1');
        foreach (['callbacks.php', 'helpers.php'] as $path) { $this->files[$path] = file_get_contents(__DIR__ . '/../' . $path); }
        file_put_contents($this->dir . '/custom-callbacks.php', self::CUSTOM);
    }

    protected function tearDown(): void
    {
        $remove = function ($dir) use (&$remove) {
            foreach (scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $path = $dir . '/' . $entry;
                if (is_dir($path) && !is_link($path)) { $remove($path); } else { unlink($path); }
            }
            rmdir($dir);
        };
        $remove($this->dir);
    }

    public function testMigrationPreservesOwnedBytesAndBootstrapWorksAndIsIdempotent(): void
    {
        SyncCore::sync($this->dir, $this->files, '1.2.0');
        $custom = file_get_contents($this->dir . '/custom-callbacks.php');
        $this->assertStringNotContainsString('function capitalize_input', $custom);
        $this->assertStringContainsString("/** Instance owned */\nfunction mutate_float_local(\$input) { return \$input; }\n", $custom);
        SyncCore::sync($this->dir, $this->files, '1.2.0');
        $this->assertSame($custom, file_get_contents($this->dir . '/custom-callbacks.php'));
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export($this->dir . '/callbacks.php', true) . '; require ' . var_export($this->dir . '/custom-callbacks.php', true) . '; echo capitalize_input("Zoë", []);') . ' 2>' . escapeshellarg($this->dir . '/bootstrap.stderr'), $output, $status);
        $this->assertSame(0, $status, file_get_contents($this->dir . '/bootstrap.stderr') . implode("\n", $output));
        $this->assertSame('ZOË', implode("\n", $output));
        $this->assertTrue(SyncCore::isClean(SyncCore::checkDir($this->dir, SyncCore::manifest($this->files, '1.2.0'))));
    }

    public function testCheckFindsSharedDefinitionsThatWereNotMigrated(): void
    {
        SyncCore::sync($this->dir, $this->files, '1.2.0');
        file_put_contents($this->dir . '/custom-callbacks.php', self::CUSTOM);
        $report = SyncCore::checkDir($this->dir, SyncCore::manifest($this->files, '1.2.0'));
        $this->assertFalse(SyncCore::isClean($report));
        $this->assertNotEmpty($report['callback_conflicts']);
    }

    public function testReferencedDefinitionIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        SyncCore::migrateCallbacks("<?php function &capitalize_input(\$input, \$url_argument) { return mb_strtoupper(\$input, 'UTF-8'); }");
    }

    public function testCustomisedBodyIsRefusedEvenWithForceWithoutPartialWrites(): void
    {
        $custom = str_replace('mb_strtoupper', 'strtoupper', self::CUSTOM);
        file_put_contents($this->dir . '/custom-callbacks.php', $custom);
        try { SyncCore::sync($this->dir, $this->files, '1.2.0', true); $this->fail('Expected refusal'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('customised', $e->getMessage()); }
        $this->assertSame($custom, file_get_contents($this->dir . '/custom-callbacks.php'));
        $this->assertFileDoesNotExist($this->dir . '/helpers.php');
    }

    public function testConditionalDefinitionIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        SyncCore::migrateCallbacks("<?php if (true) { function capitalize_input(\$input, \$url_argument) { return mb_strtoupper(\$input, 'UTF-8'); } }");
    }

    public static function attributedCallbacks(): array
    {
        return [
            'standalone' => ['#[ExampleAttribute] ', ''],
            'with comments' => ['#[ExampleAttribute([1, 2])] /* comment */ ', ''],
            'before owned function' => ['#[ExampleAttribute] ', 'function owned() { return 1; }'],
        ];
    }

    #[DataProvider('attributedCallbacks')]
    public function testAttributedCallbacksAreRefusedBeforeWrites(string $prefix, string $suffix): void
    {
        $source = '<?php ' . $prefix . 'function capitalize_input($input, $url_argument) { return mb_strtoupper($input, "UTF-8"); } ' . $suffix;
        file_put_contents($this->dir . '/custom-callbacks.php', $source);
        try { SyncCore::sync($this->dir, $this->files, '1.2.0', true); $this->fail('Expected refusal'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('attributed', $error->getMessage()); }
        $this->assertSame($source, file_get_contents($this->dir . '/custom-callbacks.php'));
        $this->assertFileDoesNotExist($this->dir . '/helpers.php');
    }

    public static function alternativeScopes(): array
    {
        return [
            'if after statement' => ['if (false): $x = 1;', 'endif;'],
            'switch' => ['switch (1): case 1:', 'endswitch;'],
            'foreach' => ['foreach ([] as $x):', 'endforeach;'],
            'for' => ['for ($i = 0; $i < 0; $i++):', 'endfor;'],
            'while' => ['while (false):', 'endwhile;'],
            'declare' => ['declare(ticks=1):', 'enddeclare;'],
        ];
    }

    #[DataProvider('alternativeScopes')]
    public function testAlternativeScopesAreRefused(string $before, string $after): void
    {
        $this->expectException(RuntimeException::class);
        SyncCore::migrateCallbacks('<?php ' . $before . ' function capitalize_input($input, $url_argument) { return mb_strtoupper($input, "UTF-8"); } ' . $after);
    }

    public function testInterpolationAndCompletedAlternativeScopesPreserveOwnedCode(): void
    {
        $owned = '<?php function owned($input) { return "{$input}"; } if (false): $x = 1; endif; ';
        $source = $owned . 'function capitalize_input($input, $url_argument) { return mb_strtoupper($input, "UTF-8"); }';
        $this->assertSame($owned, SyncCore::migrateCallbacks($source));
        token_get_all(SyncCore::migrateCallbacks($source), TOKEN_PARSE);
    }

    private function git(string ...$args): void
    {
        $command = 'git -C ' . escapeshellarg($this->dir);
        foreach ($args as $arg) { $command .= ' ' . escapeshellarg($arg); }
        exec($command . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    public static function dirtyCallbackStates(): array
    {
        return ['unstaged' => ['unstaged'], 'staged' => ['staged'], 'untracked' => ['untracked'], 'ignored' => ['ignored']];
    }

    #[DataProvider('dirtyCallbackStates')]
    public function testDirtyCustomCallbacksRefuseWithoutChangingAnyFiles(string $state): void
    {
        SyncCore::sync($this->dir, SyncCore::tagFiles(__DIR__ . '/..', '1.1.1'), '1.1.1');
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-qm', 'baseline');
        if (in_array($state, ['untracked', 'ignored'], true)) {
            $this->git('rm', '--cached', 'custom-callbacks.php');
            $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-qm', 'untracked callback fixture');
        }
        if ($state === 'ignored') {
            file_put_contents($this->dir . '/.gitignore', "\ncustom-callbacks.php\n", FILE_APPEND);
            $this->git('add', '.gitignore');
            $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-qm', 'ignored callback fixture');
        }
        $dirty = str_replace('/** Shared */', '/** Uncommitted local work */', self::CUSTOM);
        file_put_contents($this->dir . '/custom-callbacks.php', $dirty);
        if ($state === 'staged') { $this->git('add', 'custom-callbacks.php'); }
        $before = [];
        foreach (array_merge(SyncCore::CORE_FILES, ['custom-callbacks.php', SyncCore::MARKER, '.gitignore']) as $path) {
            $before[$path] = file_get_contents($this->dir . '/' . $path);
        }
        try { SyncCore::sync($this->dir, $this->files, '1.2.0'); $this->fail('Expected dirty-file refusal'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Uncommitted custom-callbacks.php', $error->getMessage()); }
        foreach ($before as $path => $contents) { $this->assertSame($contents, file_get_contents($this->dir . '/' . $path), $path); }
        $this->assertSame([], glob($this->dir . '/.sync-core-*'));
        // --force still explicitly authorises known duplicate migration after review.
        SyncCore::sync($this->dir, $this->files, '1.2.0', true);
        $this->assertStringNotContainsString('function capitalize_input', file_get_contents($this->dir . '/custom-callbacks.php'));
    }

    public static function functionImports(): array
    {
        return [
            'uppercase function' => ['use function Instance\\mb_strtoupper;'],
            'float function alias' => ['use function Instance\\convert as floatval;'],
            'case variant' => ['use function Instance\\custom as MB_STRTOUPPER;'],
            'grouped' => ['use function Instance\\{mb_strtoupper, floatval};'],
            'mixed grouped' => ['use Instance\\{Thing, function mb_strtoupper};'],
        ];
    }

    #[DataProvider('functionImports')]
    public function testImportedFunctionsRefuseMigrationBeforeWritesEvenWithForce(string $import): void
    {
        $custom = str_replace('<?php', '<?php ' . $import, self::CUSTOM);
        file_put_contents($this->dir . '/custom-callbacks.php', $custom);
        try { SyncCore::sync($this->dir, $this->files, '1.2.0', true); $this->fail('Expected import refusal'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('function imports', $error->getMessage()); }
        $this->assertSame($custom, file_get_contents($this->dir . '/custom-callbacks.php'));
        $this->assertFileDoesNotExist($this->dir . '/helpers.php');
    }

    public function testClassImportsAndClosureCapturesDoNotBlockMigration(): void
    {
        $owned = '<?php use Instance\\Thing; $x = "value"; $owned = function () use ($x) { return $x; }; ';
        $helper = 'function capitalize_input($input, $url_argument) { return mb_strtoupper($input, "UTF-8"); }';
        $this->assertSame($owned, SyncCore::migrateCallbacks($owned . $helper));
        $importsOnly = '<?php use function Instance\\custom; function owned($x) { return custom($x); }';
        $this->assertSame($importsOnly, SyncCore::migrateCallbacks($importsOnly));
    }

    public function testBlockedTargetDoesNotRemoveCallbacksOrWriteCore(): void
    {
        mkdir($this->dir . '/defaults.php');
        try { SyncCore::sync($this->dir, $this->files, '1.2.0'); $this->fail('Expected refusal'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('non-regular', $e->getMessage()); }
        $this->assertSame(self::CUSTOM, file_get_contents($this->dir . '/custom-callbacks.php'));
        $this->assertFileDoesNotExist($this->dir . '/helpers.php');
        $this->assertSame([], glob($this->dir . '/.sync-core-*'));
    }

    public function testOldTagLeavesCallbacksAloneAndMigratedDowngradeIsRefused(): void
    {
        $old = SyncCore::tagFiles(__DIR__ . '/..', '1.1.1');
        SyncCore::sync($this->dir, $old, '1.1.1');
        $this->assertSame(self::CUSTOM, file_get_contents($this->dir . '/custom-callbacks.php'));
        SyncCore::sync($this->dir, $this->files, '1.2.0');
        $this->expectException(RuntimeException::class);
        SyncCore::sync($this->dir, $old, '1.1.1', true);
    }
}
