<?php

/**
 * The dynamic-pdf core version. Part of the cache key, so each release stops serving PDFs cached by the previous one.
 */
const DYNAMIC_PDF_VERSION = '1.1.0';

/**
 * Load configuration from config.json with optional config-override.json merge
 *
 * @return array
 */
function load_config(): array
{
    if (file_exists(__DIR__ . '/config.json')) {
        $config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
    } else {
        die("Could not load configuration file.");
    }

    $config_override = array();
    if (file_exists(__DIR__ . '/config-override.json')) {
        $config_override = json_decode(file_get_contents(__DIR__ . '/config-override.json'), true);
    }

    return array_replace_recursive($config, $config_override);
}

/**
 * Remove invalid UTF-8 byte sequences from a string
 *
 * @param string $input
 * @return string
 */
function drop_invalid_utf8(string $input): string
{
    $previous = mb_substitute_character();
    mb_substitute_character('none');
    $clean = mb_convert_encoding($input, 'UTF-8', 'UTF-8');
    mb_substitute_character($previous);

    return $clean;
}

/**
 * Keep only the characters a name may contain: letters with their accents, digits, spaces and . , ' ’ - ( ) &
 *
 * Filters characters only. Text stays UTF-8 until pdf_text() converts it at the render point.
 *
 * @param string $input
 * @return string
 */
function filter_name_characters(string $input): string
{
    $input = trim(drop_invalid_utf8($input));

    return trim(preg_replace("/[^\p{L}\p{M}0-9., '\x{2019}\-()&]+/u", '', $input) ?? '');
}

/**
 * Deprecated alias of filter_name_characters(), kept for instance callbacks that still call it
 *
 * Since 1.1.0 it no longer strips accents: names keep them and pdf_text() converts them for the font.
 *
 * @deprecated 1.1.0 Use filter_name_characters()
 * @param string $input
 * @return string
 */
function strip_accents(string $input): string
{
    return filter_name_characters($input);
}

/**
 * Convert UTF-8 text to the cp1252 bytes FPDF's fonts expect
 *
 * The only encoding conversion in core: call it once, at the render point. Characters cp1252 can't hold are
 * transliterated where the locale allows (Ł becomes L) and dropped otherwise, so a stray "?" is never printed.
 *
 * @param string $text UTF-8 text
 * @return string cp1252 text
 */
function pdf_text(string $text): string
{
    $text = drop_invalid_utf8($text);

    if (class_exists('Normalizer')) {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
    }

    $output = '';
    foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $character);

        if ($converted === false || $converted === '') {
            continue;
        }

        // glibc's //TRANSLIT writes "?" for a character it can't transliterate
        if ($character !== '?' && str_contains($converted, '?')) {
            continue;
        }

        $output .= $converted;
    }

    return $output;
}

/**
 * Replace characters the current font has no glyph for, so they never print as blanks
 *
 * Some fonts are subsets without accented letters. A non-ASCII cp1252 character whose glyph width is zero is
 * replaced by its ASCII transliteration (é becomes e, Æ becomes AE, ’ becomes ') or dropped when it has none.
 *
 * @param string $cp1252_text text already converted by pdf_text()
 * @param callable $glyph_width returns the current font's width for one cp1252 character (e.g. $pdf->GetStringWidth(...))
 * @return string
 */
function fallback_missing_glyphs(string $cp1252_text, callable $glyph_width): string
{
    $output = '';
    foreach (str_split($cp1252_text) as $character) {
        if (ord($character) < 0x80 || $glyph_width($character) > 0) {
            $output .= $character;
            continue;
        }

        $utf8 = @iconv('CP1252', 'UTF-8', $character);
        $ascii = $utf8 === false ? false : @iconv('UTF-8', 'ASCII//TRANSLIT', $utf8);

        // glibc's //TRANSLIT writes "?" for a character it can't transliterate
        if ($ascii !== false && $ascii !== '' && !str_contains($ascii, '?')) {
            $output .= $ascii;
        }
    }

    return $output;
}

/**
 * Short hash of the core version and the configuration, used in cache filenames
 *
 * @param array $config
 * @return string
 */
function cache_config_hash(array $config): string
{
    return substr(md5(DYNAMIC_PDF_VERSION . json_encode($config)), 0, 6);
}

/**
 * Run the host-level validation hook, if the host has one
 *
 * The hook is ANDed with the per-argument result: it can fail a request, never rescue one. As with per-argument
 * validation, only a false return fails.
 *
 * @param bool $arguments_valid result of the per-argument validation
 * @param array $host_configuration this host's configuration (may carry "validate_arguments_callback")
 * @param array $url_arguments processed URL arguments, keyed by name
 * @param string $host_name the matched host key
 * @return bool
 */
function host_arguments_valid(bool $arguments_valid, array $host_configuration, array $url_arguments, string $host_name): bool
{
    if ($arguments_valid === false) {
        return false;
    }

    $callback = $host_configuration['validate_arguments_callback'] ?? '';
    if (empty($callback) || !is_callable($callback)) {
        return true;
    }

    return call_user_func($callback, $url_arguments, $host_configuration, $host_name) !== false;
}

/**
 * Find the environment whose URL host matches the request host
 *
 * Case-insensitive; the request host may carry the URL's port (ddev's ":8443"). Entries without a usable URL are skipped.
 *
 * @param array $environments the config's "environments"
 * @param string $host the request host ($_SERVER['HTTP_HOST'])
 * @return string the environment name, or '' when the host is not listed
 */
function environment_for_host(array $environments, string $host): string
{
    $host = strtolower($host);
    if ($host === '') {
        return '';
    }

    foreach ($environments as $name => $environment) {
        if (!is_array($environment) || !is_string($environment['url'] ?? null)) {
            continue;
        }

        $parsed = parse_url($environment['url']);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            continue;
        }

        $environment_host = strtolower($parsed['host']);
        $candidates = [$environment_host];
        if (isset($parsed['port'])) {
            $candidates[] = $environment_host . ':' . $parsed['port'];
        }

        if (in_array($host, $candidates, true)) {
            return (string) $name;
        }
    }

    return '';
}

/**
 * Whether test-links.php may be shown for this request
 *
 * A listed host takes its own environment's key, or is open when that key is empty (ddev). Any other host needs
 * one of the configured keys, and is closed when no environment has a key.
 *
 * @param array $environments the config's "environments"
 * @param string $host the request host ($_SERVER['HTTP_HOST'])
 * @param mixed $provided_key $_GET['key']; anything but a string counts as no key
 * @return bool
 */
function test_links_access_allowed(array $environments, string $host, mixed $provided_key): bool
{
    $provided_key = is_string($provided_key) ? $provided_key : '';

    $listed = environment_for_host($environments, $host);
    if ($listed !== '') {
        $key = is_string($environments[$listed]['key'] ?? null) ? $environments[$listed]['key'] : '';

        return $key === '' || ($provided_key !== '' && hash_equals($key, $provided_key));
    }

    if ($provided_key === '') {
        return false;
    }

    foreach ($environments as $environment) {
        $key = is_array($environment) && is_string($environment['key'] ?? null) ? $environment['key'] : '';
        if ($key !== '' && hash_equals($key, $provided_key)) {
            return true;
        }
    }

    return false;
}
