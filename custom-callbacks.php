<?php

/*
 * Add any custom callbacks to this file
 */

/**
 * Custom callback to determine whether or not a text block should show
 *
 * @param array $text_block
 * @param array $url_arguments
 * @return bool
 */
function text_block_amount_greater_than_zero_callback($text_block, $url_arguments)
{
    if (intval($url_arguments['amount']['original']) > 0) {
        return true;
    }
    return false;
}