<?php

$default_host = array(
    "slug" => "",
    "pdf_template" => "",
    "pdf_template_callback" => "",
    "pdf_orientation" => "P",
    "redirect_location" => "http://www.google.com",
);

$default_url_argument = array(
    "argument" => "",
    "type" => "string",
    "default" => "",
    "default_callback" => "",
    "sanitize_callback" => "",
    "validate_callback" => "",
    "mutate_callback" => "trim",
);

$default_text_block = array(
    "text" => "",
    "text_template" => "standard",
    "position" => array(
        "x" => 10,
        "y" => 10,
        "width" => 190,
        "height" => 20,
        "align" => "C"
    ),
    "fit_line" => false
);