<?php

declare(strict_types=1);

/**
 * Copies the dynamic-pdf core files from a release tag into an instance, and checks instances for drift.
 *
 * Every instance runs byte-identical core files from one tag; everything else (config.json, config-override.json,
 * custom-callbacks.php, resources/, CLAUDE.local.md, tests) is instance-owned. From 1.2.0, sync removes only
 * the three recognised shared callback definitions from custom-callbacks.php in the same transaction. Used by bin/sync-core.
 * Kept PHP 8.2-compatible so --check can run on the server against a deployed public_html.
 */
final class SyncCore
{
    /**
     * Core files, copied byte-for-byte from the tag. An explicit allow-list: upstream also tracks instance-owned
     * files (config.json, custom-callbacks.php, resources/) and upstream-only ones (tests/, phpunit.xml).
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
        'CLAUDE.md',
    ];

    /** The upstream project's own CLAUDE.md is not suitable for instances. */
    public const INSTANCE_CLAUDE_TEMPLATE = 'templates/instance-CLAUDE.md';

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
            // CLAUDE.md first became core in 1.1.1; older releases leave instance notes alone.
            if ($path === 'CLAUDE.md' && version_compare($tag, '1.1.1', '<')) {
                continue;
            }
            $source = $path === 'CLAUDE.md' ? self::INSTANCE_CLAUDE_TEMPLATE : $path;
            [$status, $contents] = self::git($upstream, ['show', $tag . ':' . $source]);
            if ($status !== 0) {
                throw new RuntimeException("Tag {$tag} has no {$source}.");
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
            if (!array_key_exists($path, $files)) {
                continue;
            }
            $hashes[$path] = hash('sha256', $files[$path]);
        }
        return ['version' => $tag, 'files' => $hashes, '.gitignore' => $files['.gitignore'],
            'shared_callbacks' => str_contains($files['callbacks.php'], 'function capitalize_input(')];
    }

    /**
     * Copy a tag's core files into an instance working tree. Never commits.
     *
     * Refuses, before writing anything, the upstream repository itself, a core file whose name collides with an
     * instance file by case only (86k-workplace's README.md vs readme.md, D22), and uncommitted changes to core files.
     *
     * @return array{written: string[], deleted: string[], gitignore_changed: bool, callbacks_changed: bool}
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
        if (isset($files['CLAUDE.md'])) {
            self::assertClaudeNotesPreserved($dir, $files['CLAUDE.md']);
        }
        if (!$force && self::isGitWorktree($dir)) {
            $dirty = self::dirtyCoreFiles($dir, array_keys($files));
            if ($dirty) {
                throw new RuntimeException("{$dir}: uncommitted changes to " . implode(', ', $dirty) . '; commit or discard them first (or pass --force).');
            }
        }

        $customPath = $dir . '/custom-callbacks.php';
        if (is_link($customPath) || (file_exists($customPath) && !is_file($customPath))) {
            throw new RuntimeException('Refusing non-regular custom-callbacks.php.');
        }
        $customBefore = is_file($customPath) ? file_get_contents($customPath) : null;
        $foldsCallbacks = str_contains($files['callbacks.php'], 'function capitalize_input(');
        $oldMarker = is_file($dir . '/' . self::MARKER)
            ? json_decode(file_get_contents($dir . '/' . self::MARKER), true) : null;
        if (!$foldsCallbacks && !empty($oldMarker['shared_callbacks'])) {
            throw new RuntimeException('Cannot downgrade migrated callbacks; restore the complete pre-upgrade working tree, including custom callbacks and the version marker.');
        }
        $customAfter = $foldsCallbacks && $customBefore !== null
            ? self::migrateCallbacks($customBefore) : $customBefore;
        if ($customAfter !== $customBefore && !$force && self::isGitWorktree($dir)
            && self::dirtyFiles($dir, ['custom-callbacks.php'], true)) {
            throw new RuntimeException('Uncommitted custom-callbacks.php changes; commit or discard them first.');
        }

        $changes = [];
        $written = [];
        if ($customAfter !== $customBefore) {
            $changes['custom-callbacks.php'] = $customAfter;
        }
        foreach (self::CORE_FILES as $path) {
            if (array_key_exists($path, $files)) {
                $changes[$path] = $files[$path];
                $written[] = $path;
            }
        }
        $deleted = [];
        foreach (self::REMOVED_FILES as $path) {
            if (file_exists($dir . '/' . $path) || is_link($dir . '/' . $path)) {
                $changes[$path] = null;
                $deleted[] = $path;
            }
        }
        $manifest = self::manifest($files, $tag);
        $marker = ['version' => $tag, 'files' => $manifest['files']];
        if ($foldsCallbacks) {
            $marker['shared_callbacks'] = true;
        }
        $changes[self::MARKER] = json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $before = is_file($dir . '/.gitignore') ? file_get_contents($dir . '/.gitignore') : null;
        $after = self::mergeGitignore($before ?? '', $files['.gitignore']);
        if ($after !== $before) {
            $changes['.gitignore'] = $after;
        }
        self::applyTransaction($dir, $changes);
        return ['written' => $written, 'deleted' => $deleted, 'gitignore_changed' => $after !== $before,
            'callbacks_changed' => $customAfter !== $customBefore];
    }

    /**
     * Remove only known, top-level shared helpers. Unknown bodies/signatures fail before any writes,
     * including with --force. Token offsets preserve every unrelated byte (notably mutate_float_*).
     */
    public static function migrateCallbacks(string $source): string
    {
        $names = ['capitalize_input', 'mutate_to_uppercase', 'validate_float_under_999999_allow_zero'];
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException('Cannot migrate invalid PHP in custom-callbacks.php; fix it before syncing.', 0, $error);
        }
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $token) {
            $offsets[] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        $ranges = [];
        $seen = [];
        $depth = 0;
        $alternativeDepth = 0;
        $namespaced = false;
        $functionImports = false;
        foreach ($tokens as $i => $token) {
            // Interpolated strings have a tokenised opening brace and a literal closing brace.
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            if ($token === '}') { $depth--; }
            if (is_array($token) && in_array($token[0], [T_ENDIF, T_ENDFOR, T_ENDFOREACH, T_ENDWHILE, T_ENDSWITCH, T_ENDDECLARE], true)) { $alternativeDepth--; }
            if (is_array($token) && in_array($token[0], [T_IF, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_DECLARE], true)) {
                $header = $i + 1;
                while (isset($tokens[$header]) && is_array($tokens[$header]) && in_array($tokens[$header][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $header++; }
                if (($tokens[$header] ?? null) === '(') {
                    $parentheses = 0;
                    do {
                        if ($tokens[$header] === '(') { $parentheses++; }
                        if ($tokens[$header] === ')') { $parentheses--; }
                        $header++;
                    } while (isset($tokens[$header]) && $parentheses > 0);
                    while (isset($tokens[$header]) && is_array($tokens[$header]) && in_array($tokens[$header][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $header++; }
                    if (($tokens[$header] ?? null) === ':') { $alternativeDepth++; }
                }
            }
            if (is_array($token) && $token[0] === T_NAMESPACE) { $namespaced = true; }
            if ($depth === 0 && is_array($token) && $token[0] === T_USE) {
                $import = $i + 1;
                while (isset($tokens[$import]) && is_array($tokens[$import]) && in_array($tokens[$import][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $import++; }
                // Closure captures use (...), not an import declaration.
                if (($tokens[$import] ?? null) !== '(') {
                    for (; isset($tokens[$import]) && $tokens[$import] !== ';'; $import++) {
                        if (is_array($tokens[$import]) && $tokens[$import][0] === T_FUNCTION) { $functionImports = true; }
                    }
                }
            }
            if (!is_array($token) || $token[0] !== T_FUNCTION) { continue; }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) { $j++; }
            $name = is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_STRING ? strtolower($tokens[$j][1]) : '';
            if (!in_array($name, $names, true)) { continue; }
            if ($depth !== 0 || $alternativeDepth !== 0 || $namespaced || isset($seen[$name])) {
                throw new RuntimeException("Cannot migrate nested, namespaced or duplicate callback {$name}.");
            }
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $previous--; }
            if (($tokens[$previous] ?? null) === ']') {
                throw new RuntimeException("Cannot migrate attributed callback {$name}; reconcile it before syncing.");
            }
            $seen[$name] = true;
            $normalized = '';
            $bodyDepth = 0;
            $bodyStarted = false;
            for ($end = $i; isset($tokens[$end]); $end++) {
                $part = $tokens[$end];
                if (is_array($part)) {
                    if (in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                    $normalized .= $part[0] === T_CONSTANT_ENCAPSED_STRING && $part[1] === '"UTF-8"'
                        ? "'UTF-8'" : $part[1];
                } else {
                    $normalized .= $part;
                    if ($part === '{') { $bodyDepth++; $bodyStarted = true; }
                    if ($part === '}' && --$bodyDepth === 0 && $bodyStarted) { break; }
                }
            }
            $signature = $name === 'validate_float_under_999999_allow_zero'
                ? 'function'.$name.'\\((?:string)?\\$input\\)(?::bool)?'
                : 'function'.$name.'\\((?:string)?\\$input,(?:array)?\\$url_argument\\)(?::string)?';
            $body = $name === 'validate_float_under_999999_allow_zero'
                ? '{returnfloatval($input)<999999&&floatval($input)>=0;}'
                : "{returnmb_strtoupper(\$input,'UTF-8');}";
            if (!preg_match('~^'.$signature.preg_quote($body, '~').'$~D', $normalized)) {
                throw new RuntimeException("Cannot migrate customised callback {$name}; reconcile it before syncing.");
            }
            $first = $i;
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) { $previous--; }
            if ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOC_COMMENT) {
                $first = $previous;
            }
            $ranges[] = [$offsets[$first], $offsets[$end] + strlen($tokens[$end])];
        }
        if ($ranges && $functionImports) {
            throw new RuntimeException('Cannot migrate shared callbacks in a file with function imports; reconcile name resolution before syncing.');
        }
        foreach (array_reverse($ranges) as [$start, $end]) {
            $source = substr($source, 0, $start) . substr($source, $end);
        }
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException('Migration would produce invalid PHP; refusing to write custom-callbacks.php.', 0, $error);
        }
        return $source;
    }

    /** Stage every replacement before publishing; restore prior contents and modes if publication fails. */
    private static function applyTransaction(string $dir, array $changes): void
    {
        $backups = [];
        $staged = [];
        $applied = [];
        $createdDirs = [];
        $recovery = [];
        try {
            foreach ($changes as $path => $contents) {
                $target = $dir . '/' . $path;
                $parent = dirname($target);
                for ($ancestor = $parent; $ancestor !== dirname($dir); $ancestor = dirname($ancestor)) {
                    if (is_link($ancestor)) { throw new RuntimeException("Refusing symlink directory {$path}."); }
                }
                if (is_link($target) || (file_exists($target) && !is_file($target))) {
                    throw new RuntimeException("Refusing non-regular target {$path}.");
                }
                $before = is_file($target) ? file_get_contents($target) : null;
                if ($before === false) {
                    throw new RuntimeException("Cannot read existing {$path} before syncing.");
                }
                $mode = $before !== null ? fileperms($target) & 0777 : 0644;
                if (!is_dir($parent)) {
                    if (!mkdir($parent, 0775, true)) { throw new RuntimeException("Cannot create directory for {$path}."); }
                    $createdDirs[] = $parent;
                }
                // Restoring by rename also works when a published replacement is read-only.
                $backups[$path] = $before === null ? null : self::stageFile($parent, $before, $mode, '.sync-core-backup-');
                if ($contents !== null) {
                    $staged[$path] = self::stageFile($parent, $contents, $mode, '.sync-core-');
                }
            }
            foreach ($changes as $path => $contents) {
                $target = $dir . '/' . $path;
                $ok = $contents === null ? unlink($target) : rename($staged[$path], $target);
                if (!$ok) { throw new RuntimeException("Cannot publish {$path}."); }
                $applied[] = $path;
            }
        } catch (Throwable $error) {
            $failed = [];
            foreach (array_reverse($applied) as $path) {
                $target = $dir . '/' . $path;
                try {
                    $ok = $backups[$path] === null
                        ? (!file_exists($target) || unlink($target))
                        : rename($backups[$path], $target);
                } catch (Throwable $rollbackError) {
                    $ok = false;
                }
                if (!$ok) {
                    $failed[] = $path;
                    if ($backups[$path] !== null) { $recovery[] = $backups[$path]; }
                }
            }
            if ($failed) {
                throw new RuntimeException('Rollback failed for ' . implode(', ', $failed)
                    . '; original backup files retained: ' . implode(', ', $recovery), 0, $error);
            }
            throw $error;
        } finally {
            foreach (array_merge(array_values($staged), array_values(array_filter($backups))) as $temp) {
                if (is_file($temp) && !in_array($temp, $recovery, true)) { unlink($temp); }
            }
            foreach (array_reverse($createdDirs) as $parent) {
                if (is_dir($parent) && count(scandir($parent)) === 2) { rmdir($parent); }
            }
        }
    }

    /** A same-directory staged file, with no leaked temporary file on staging failure. */
    private static function stageFile(string $parent, string $contents, int $mode, string $prefix): string
    {
        $temp = tempnam($parent, $prefix);
        if ($temp === false) { throw new RuntimeException('Cannot create staged file.'); }
        try {
            if (file_put_contents($temp, $contents) !== strlen($contents) || !chmod($temp, $mode)) {
                throw new RuntimeException('Cannot write staged file.');
            }
        } catch (Throwable $error) {
            unlink($temp);
            throw $error;
        }
        return $temp;
    }

    /** Refuse to erase instance notes, even with --force, before any file is written. */
    private static function assertClaudeNotesPreserved(string $dir, string $template): void
    {
        $path = $dir . '/CLAUDE.md';
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) && !is_link($path)) {
            $contents = file_get_contents($path);
            if ($contents === $template) {
                return; // Re-sync of the current template.
            }
            $markerPath = $dir . '/' . self::MARKER;
            $marker = is_file($markerPath) ? json_decode(file_get_contents($markerPath), true) : null;
            $oldHash = is_array($marker) ? ($marker['files']['CLAUDE.md'] ?? null) : null;
            if (is_string($oldHash) && hash('sha256', $contents) === $oldHash) {
                return; // Unmodified template from a previous sync.
            }
            $local = $dir . '/CLAUDE.local.md';
            if (is_file($local) && !is_link($local) && file_get_contents($local) === $contents) {
                return; // An exact copy of the notes has already been preserved.
            }
        }
        throw new RuntimeException("{$dir}: move CLAUDE.md notes to tracked CLAUDE.local.md before syncing (D44); --force cannot bypass this guard.");
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
            $isRepo ? self::vendorTracked($dir, null) : null,
            is_file($dir . '/custom-callbacks.php') ? file_get_contents($dir . '/custom-callbacks.php') : null
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
            self::vendorTracked($repo, $ref),
            $read('custom-callbacks.php')
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
            && $report['vendor_tracked'] !== true
            && ($report['callback_conflicts'] ?? []) === [];
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
    private static function buildReport(array $manifest, callable $hashOf, array $paths, array $namePaths, ?string $marker, ?string $gitignore, ?bool $vendorTracked, ?string $callbacks = null): array
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

        $callbackConflicts = [];
        if (!empty($manifest['shared_callbacks']) && $callbacks !== null) {
            try {
                if (self::migrateCallbacks($callbacks) !== $callbacks) {
                    $callbackConflicts[] = 'shared definitions remain in custom-callbacks.php';
                }
            } catch (Throwable $error) {
                $callbackConflicts[] = 'unsafe shared callback definitions in custom-callbacks.php';
            }
        }

        return [
            'callback_conflicts' => $callbackConflicts,
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

    private static function dirtyCoreFiles(string $dir, array $paths): array
    {
        $paths = array_values(array_intersect(self::CORE_FILES, $paths));
        // The prescribed git mv stages removal of CLAUDE.md before the replacement is synced.
        if (!file_exists($dir . '/CLAUDE.md') && !is_link($dir . '/CLAUDE.md')) {
            $paths = array_values(array_diff($paths, ['CLAUDE.md']));
        }
        return self::dirtyFiles($dir, array_merge($paths, self::REMOVED_FILES));
    }

    /** Check exactly the requested paths, including instance-owned files subject to a migration. */
    private static function dirtyFiles(string $dir, array $paths, bool $includeUntracked = false): array
    {
        if (!$paths) { return []; }
        $args = ['status', '--porcelain'];
        if ($includeUntracked) { $args[] = '--ignored'; }
        [$status, $output] = self::git($dir, array_merge($args, ['--'], $paths));
        if ($status !== 0) { throw new RuntimeException('Cannot check working-tree changes before syncing.'); }
        $dirty = [];
        foreach (array_filter(explode("\n", $output), 'strlen') as $line) {
            // Untracked core files retain the old overwrite policy; callback migrations also protect untracked work.
            if ($includeUntracked || !str_starts_with($line, '??')) {
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
