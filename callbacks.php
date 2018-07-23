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
 * @param string $input
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
 * @param string $input
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

/**
 * Custom callback to determine whether or not a text block should show
 *
 * @param array $text_block
 * @param array $url_arguments
 * @return bool
 */
function text_block_toggle_custom_callback($text_block, $url_arguments)
{
    return true;
}

/**
 * Custom callback to determine text content of a text block
 *
 * @param array $text
 * @param array $url_arguments
 * @return bool
 */
function text_block_text_custom_callback($text, $url_arguments)
{
    return true;
}


/*
 * Commonly used callbacks
 */

/**
 * Standard function for sanitizing a name
 *
 * @param $input
 * @return string
 */
function sanitize_process_name_filter($input)
{
    return trim(strip_accents($input));
}

/**
 * Ensure that the input is not empty
 *
 * @param string $input
 * @return bool
 */
function validate_not_empty($input)
{
    return !empty($input);
}

/**
 * Ensure that the integer is between 0 and 999999
 *
 * Note: 0 values return false
 *
 * @param string $input
 * @return bool
 */
function validate_int_under_999999($input)
{
    return intval($input) < 999999 && intval($input) > 0;
}

/**
 * Ensure that the float is between 0 and 999999
 *
 * Note: 0 values return false
 *
 * @param string $input
 * @return bool
 */
function validate_float_under_999999($input)
{
    return floatval($input) < 999999 && floatval($input) > 0;
}


/**
 * Formats a number as a currency amount (according to locale)
 *
 * @param $input
 * @param array $url_argument
 * @return mixed
 */
function mutate_dollar_amount($input, $url_argument)
{
    // If the amount is .00, omit it
    if (intval($input) == floatval($input)) {
        return money_format('%.0n', $input);
    }

    return money_format('%.2n', $input);
}