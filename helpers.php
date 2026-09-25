<?php

/**
 * Custom UTF-8 decode function to replace deprecated utf8_decode()
 *
 * @param string $input
 * @return string
 */
function custom_utf8_decode(string $input): string
{
    return mb_convert_encoding($input, 'ISO-8859-1', 'UTF-8');
}

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
 * Strip accents from a string when the font can't handle them
 *
 * @param string $input
 * @return string
 */
function strip_accents(string $input): string
{
    // Strip accents when the font can't handle it!
    $input = strtr(custom_utf8_decode($input), custom_utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');

    return preg_replace("/[^\p{L}0-9., '\-()]+/", "", trim($input));
}