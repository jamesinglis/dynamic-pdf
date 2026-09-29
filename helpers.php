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
 * Keep only the characters a name may contain: letters with their accents, digits, spaces and . , ' - ( ) &
 *
 * Filters characters only. Text stays UTF-8 until pdf_text() converts it at the render point.
 *
 * @param string $input
 * @return string
 */
function filter_name_characters(string $input): string
{
    $input = trim(drop_invalid_utf8($input));

    return trim(preg_replace("/[^\p{L}\p{M}0-9., '\-()&]+/u", '', $input) ?? '');
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
 * Short hash of the core version and the configuration, used in cache filenames
 *
 * @param array $config
 * @return string
 */
function cache_config_hash(array $config): string
{
    return substr(md5(DYNAMIC_PDF_VERSION . json_encode($config)), 0, 6);
}
