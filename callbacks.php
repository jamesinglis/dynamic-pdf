<?php

/*
 * Sample callbacks
 */

/**
 * Custom callback to get default value for a URL argument
 *
 * @param array $url_argument
 * @return mixed
 */
function default_custom_callback($url_argument)
{
    return $url_argument["default"];
}

/**
 * Custom sanitization callback for a URL argument
 *
 * @param $input
 * @return mixed
 */
function sanitize_custom_callback($input)
{
    return $input;
}

/**
 * Custom validation callback
 *
 * @param string $input
 * @param array $url_argument
 * @return bool
 */
function validate_custom_callback($input, $url_argument)
{
    return !empty($input);
}

/**
 * Custom mutation callback for a URL argument
 *
 * @param $input
 * @param array $url_argument
 * @return mixed
 */
function mutate_custom_callback($input, $url_argument)
{
    return $input;
}

/**
 * Custom callback to get relative path to PDF template
 *
 * @param array $host_configuration_array
 * @param array $url_arguments_array
 * @return string Relative path to PDF template
 */
function pdf_template_custom_callback($host_configuration_array, $url_arguments_array)
{
    return $host_configuration_array["pdf_template"];
}

/*
 * Commonly used callbacks
 */

function sanitize_process_name_filter($input)
{
    return trim(strip_accents($input));
}

function validate_not_empty($input)
{
    return !empty($input);
}

function validate_int_under_999999($input)
{
    return intval($input) < 999999 && intval($input) > 0;
}

function validate_float_under_999999($input)
{
    return floatval($input) < 999999 && floatval($input) > 0;
}