<?php

declare(strict_types=1);

/**
 * Copies the dynamic-pdf core files from a release tag into an instance, and checks instances for drift.
 *
 * Every instance runs byte-identical core files from one tag; everything else (config.json, config-override.json,
 * custom-callbacks.php, resources/, CLAUDE.md, tests) is instance-owned and never touched. Used by bin/sync-core.
 * Kept PHP 8.2-compatible so --check can run on the server against a deployed public_html.
 */
final class SyncCore
{
    /**
     * Core files, copied byte-for-byte from the tag. An explicit allow-list: upstream also tracks instance-owned
     * files (config.json, custom-callbacks.php, CLAUDE.md, resources/) and upstream-only ones (tests/, phpunit.xml).
     */
    public const CORE_FILES = [
        'index.php',
        'helpers.php',
        'callbacks.php',
        'defaults.php',
        'expire-cache.php',
        'test-links.php',
        'rotate-keys.php',
        '.htaccess',
        'composer.json',
        'composer.lock',
        'readme.md',
        'cache/index.php',
        'config-example.json',
    ];

    /**
     * Files that were core in an earlier release and are deleted from instances (bulk_create.php: D17)
     */
    public const REMOVED_FILES = [
        'bulk_create.php',
    ];

    /**
     * Version marker written into each instance. Ends in .json so the core .htaccess blocks it.
     */
    public const MARKER = '.dynamic-pdf-version.json';

    /**
     * .gitignore lines an instance must not carry: composer.lock is tracked fleet-wide (D16)
     */
    public const FORBIDDEN_GITIGNORE_LINES = [
        'composer.lock',
        '/composer.lock',
    ];

    /**
     * Read the core files (and .gitignore) from a tag in the upstream repository
     *
     * @return array<string, string> core path => contents, in CORE_FILES order, plus '.gitignore' last
     */
    public static function tagFiles(string $upstream, string $tag): array
    {
        if (self::git($upstream, ['rev-parse', '--verify', '--quiet', 'refs/tags/' . $tag . '^{commit}'])[0] !== 0) {
            throw new RuntimeException("Tag {$tag} does not exist in {$upstream}.");
        }
        $files = [];
        foreach (array_merge(self::CORE_FILES, ['.gitignore']) as $path) {
            [$status, $contents] = self::git($upstream, ['show', $tag . ':' . $path]);
            if ($status !== 0) {
                throw new RuntimeException("Tag {$tag} has no {$path}.");
            }
            $files[$path] = $contents;
        }
        self::assertVersionMatches($tag, $files['helpers.php']);
        return $files;
    }

    /**
     * Refuse a tag whose helpers.php declares a different DYNAMIC_PDF_VERSION
     */
    public static function assertVersionMatches(string $tag, string $helpers): void
    {
        if (!preg_match("/const DYNAMIC_PDF_VERSION = '([^']+)';/", $helpers, $match) || $match[1] !== $tag) {
            $found = $match[1] ?? 'none';
            throw new RuntimeException("Tag {$tag} declares DYNAMIC_PDF_VERSION {$found}; refusing to sync a mislabelled release.");
        }
    }

    /**
     * Checksums of a tag's core files, plus the tag's .gitignore, for checks that run without git
     */
    public static function manifest(array $files, string $tag): array
    {
        $hashes = [];
        foreach (self::CORE_FILES as $path) {
            $hashes[$path] = hash('sha256', $files[$path]);
        }
        return ['version' => $tag, 'files' => $hashes, '.gitignore' => $files['.gitignore']];
    }

    /**
     * Copy a tag's core files into an instance working tree. Never commits.
     *
     * Refuses, before writing anything, the upstream repository itself, a core file whose name collides with an
     * instance file by case only (86k-workplace's README.md vs readme.md, D22), and uncommitted changes to core files.
     *
     * @return array{written: string[], deleted: string[], gitignore_changed: bool}
     */
    public static function sync(string $dir, array $files, string $tag, bool $force = false): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new RuntimeException("{$dir} is not a directory.");
        }
        if (is_file($dir . '/bin/SyncCore.php')) {
            throw new RuntimeException("{$dir} is the upstream repository; sync into instances only.");
        }
        $collisions = self::caseCollisions(self::instancePaths($dir));
        if ($collisions) {
            throw new RuntimeException("{$dir}: " . implode(', ', $collisions) . ' collides by case with a core file; resolve it first (D22).');
        }
        if (!$force && self::isGitWorktree($dir)) {
            $dirty = self::dirtyCoreFiles($dir);
            if ($dirty) {
                throw new RuntimeException("{$dir}: uncommitted changes to " . implode(', ', $dirty) . '; commit or discard them first (or pass --force).');
            }
        }

        $written = [];
        foreach (self::CORE_FILES as $path) {
            $target = $dir . '/' . $path;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $files[$path]);
            $written[] = $path;
        }

        $deleted = [];
        foreach (self::REMOVED_FILES as $path) {
            if (is_file($dir . '/' . $path)) {
                unlink($dir . '/' . $path);
                $deleted[] = $path;
            }
        }

        $manifest = self::manifest($files, $tag);
        file_put_contents(
            $dir . '/' . self::MARKER,
            json_encode(['version' => $tag, 'files' => $manifest['files']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $gitignorePath = $dir . '/.gitignore';
        $before = is_file($gitignorePath) ? file_get_contents($gitignorePath) : null;
        $after = self::mergeGitignore($before ?? '', $files['.gitignore']);
        if ($after !== $before) {
            file_put_contents($gitignorePath, $after);
        }

        return ['written' => $written, 'deleted' => $deleted, 'gitignore_changed' => $after !== $before];
    }

    /**
     * Check an instance directory (a working tree, or a deployed public_html) against a manifest
     */
    public static function checkDir(string $dir, array $manifest): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new RuntimeException("{$dir} is not a directory.");
        }
        $isRepo = self::isGitWorktree($dir);
        $gitignore = is_file($dir . '/.gitignore') ? file_get_contents($dir . '/.gitignore') : null;
        $marker = is_file($dir . '/' . self::MARKER) ? file_get_contents($dir . '/' . self::MARKER) : null;

        return self::buildReport(
            $manifest,
            fn (string $path) => is_file($dir . '/' . $path) ? hash_file('sha256', $dir . '/' . $path) : null,
            self::listDir($dir),
            self::instancePaths($dir),
            $marker,
            // A deployed copy with no .gitignore has nothing to check; a repo without one is missing every line
            $gitignore ?? ($isRepo ? '' : null),
            $isRepo ? self::vendorTracked($dir, null) : null
        );
    }

    /**
     * Check a committed tree (a branch, tag or commit) without checking it out: e.g. tdr's 2023 and 2026 deploy branches
     */
    public static function checkRef(string $repo, string $ref, array $manifest): array
    {
        [$status, $tree] = self::git($repo, ['ls-tree', '-r', '--name-only', $ref]);
        if ($status !== 0) {
            throw new RuntimeException("{$repo}: cannot read ref {$ref}.");
        }
        $paths = array_values(array_filter(explode("\n", $tree), 'strlen'));
        $read = function (string $path) use ($repo, $ref, $paths): ?string {
            if (!in_array($path, $paths, true)) {
                return null;
            }
            return self::git($repo, ['show', $ref . ':' . $path])[1];
        };
        $contents = $read('.gitignore');
        $marker = $read(self::MARKER);

        return self::buildReport(
            $manifest,
            fn (string $path) => ($blob = $read($path)) === null ? null : hash('sha256', $blob),
            $paths,
            $paths,
            $marker,
            $contents ?? '',
            self::vendorTracked($repo, $ref)
        );
    }

    /**
     * Whether a report shows an instance exactly at its manifest's release
     */
    public static function isClean(array $report): bool
    {
        return $report['missing'] === []
            && $report['drift'] === []
            && $report['removed_present'] === []
            && $report['case_collisions'] === []
            && $report['marker'] === $report['version']
            && ($report['gitignore_missing'] ?? []) === []
            && ($report['gitignore_forbidden'] ?? []) === []
            && $report['vendor_tracked'] !== true;
    }

    /**
     * Upstream's .gitignore lines added to an instance's, minus the forbidden ones; instance lines and order are kept
     */
    public static function mergeGitignore(string $instance, string $upstream): string
    {
        $lines = $instance === '' ? [] : explode("\n", rtrim($instance, "\n"));
        $lines = array_values(array_filter($lines, fn ($line) => !in_array(trim($line), self::FORBIDDEN_GITIGNORE_LINES, true)));
        $present = array_map('trim', $lines);
        foreach (self::gitignoreLines($upstream) as $line) {
            if (!in_array($line, $present, true)) {
                $lines[] = $line;
                $present[] = $line;
            }
        }
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param string[] $paths files present, for the removed-file check
     * @param string[] $namePaths every name the instance uses, for the case-collision check
     */
    private static function buildReport(array $manifest, callable $hashOf, array $paths, array $namePaths, ?string $marker, ?string $gitignore, ?bool $vendorTracked): array
    {
        $missing = [];
        $drift = [];
        foreach ($manifest['files'] as $path => $expected) {
            $actual = $hashOf($path);
            if ($actual === null) {
                $missing[] = $path;
            } elseif ($actual !== $expected) {
                $drift[] = $path;
            }
        }
        $removed = array_values(array_intersect(self::REMOVED_FILES, $paths));

        $markerVersion = null;
        if ($marker !== null) {
            $decoded = json_decode($marker, true);
            $markerVersion = is_array($decoded) && is_string($decoded['version'] ?? null) ? $decoded['version'] : 'unreadable';
        }

        $gitignoreMissing = null;
        $gitignoreForbidden = null;
        if ($gitignore !== null) {
            $have = self::gitignoreLines($gitignore);
            $gitignoreMissing = array_values(array_diff(self::gitignoreLines($manifest['.gitignore']), $have));
            $gitignoreForbidden = array_values(array_intersect($have, self::FORBIDDEN_GITIGNORE_LINES));
        }

        return [
            'version' => $manifest['version'],
            'marker' => $markerVersion,
            'missing' => $missing,
            'drift' => $drift,
            'removed_present' => $removed,
            'case_collisions' => self::caseCollisions($namePaths),
            'gitignore_missing' => $gitignoreMissing,
            'gitignore_forbidden' => $gitignoreForbidden,
            'vendor_tracked' => $vendorTracked,
        ];
    }

    /**
     * Paths that differ from a core file only by case (README.md vs readme.md): one file on APFS, two in git
     */
    private static function caseCollisions(array $paths): array
    {
        $collisions = [];
        foreach ($paths as $path) {
            foreach (self::CORE_FILES as $core) {
                if ($path !== $core && strtolower($path) === strtolower($core)) {
                    $collisions[] = $path;
                }
            }
        }
        return $collisions;
    }

    /**
     * Paths on disk plus, in a git worktree, the tracked ones: on APFS git can track README.md while the disk
     * lists the same file as readme.md, and only the index shows the name the server will get
     */
    private static function instancePaths(string $dir): array
    {
        $paths = self::listDir($dir);
        if (self::isGitWorktree($dir)) {
            $tracked = array_filter(explode("\n", self::git($dir, ['ls-files'])[1]), 'strlen');
            $paths = array_values(array_unique(array_merge($paths, $tracked)));
        }
        return $paths;
    }

    /**
     * Relative paths of the files an instance holds at the core files' locations (top level and cache/)
     */
    private static function listDir(string $dir): array
    {
        $paths = [];
        foreach (['', 'cache/'] as $sub) {
            if (!is_dir($dir . '/' . $sub)) {
                continue;
            }
            foreach (scandir($dir . '/' . $sub) as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $sub . $entry)) {
                    $paths[] = $sub . $entry;
                }
            }
        }
        return $paths;
    }

    private static function gitignoreLines(string $contents): array
    {
        $lines = array_map('trim', explode("\n", $contents));
        return array_values(array_unique(array_filter($lines, fn ($line) => $line !== '' && $line[0] !== '#')));
    }

    private static function isGitWorktree(string $dir): bool
    {
        [$status, $output] = self::git($dir, ['rev-parse', '--show-toplevel']);
        return $status === 0 && realpath(trim($output)) === realpath($dir);
    }

    private static function dirtyCoreFiles(string $dir): array
    {
        [, $output] = self::git($dir, array_merge(['status', '--porcelain', '--'], self::CORE_FILES, self::REMOVED_FILES));
        $dirty = [];
        foreach (array_filter(explode("\n", $output), 'strlen') as $line) {
            // Untracked core files are fine to overwrite: there is nothing committed to lose
            if (!str_starts_with($line, '??')) {
                $dirty[] = substr($line, 3);
            }
        }
        return $dirty;
    }

    private static function vendorTracked(string $repo, ?string $ref): bool
    {
        $args = $ref === null ? ['ls-files', '--', 'vendor'] : ['ls-tree', '--name-only', $ref, '--', 'vendor'];
        return trim(self::git($repo, $args)[1]) !== '';
    }

    /**
     * @return array{0: int, 1: string} exit status and stdout
     */
    private static function git(string $dir, array $args): array
    {
        $command = 'git -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout];
    }
}
