<?php

$config = false;
if (file_exists(__DIR__ . '/config.json')) {
    $config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
}

if (!$config) {
    die("Could not load configuration file.");
}

if ($config["global"]["debug_mode"]) {
    error_reporting(-1);
    ini_set('display_errors', 'on');
    ini_set('error_reporting', E_ALL);
}

define('FPDF_FONTPATH', __DIR__ . '/resources/fonts');

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/defaults.php';
require __DIR__ . '/callbacks.php';
require __DIR__ . '/custom-callbacks.php';

use Symfony\Component\HttpFoundation\Request;
use setasign\Fpdi\Fpdi;

foreach ($config["hosts"] as &$host) {
    $host = array_merge($default_host, $host);
}
unset($host);

// If the domain matches one of the hosts in the config, use that configuration; otherwise use "default"
$hosts = array_keys($config["hosts"]);

if (in_array($_SERVER['HTTP_HOST'], $hosts)) {
    $host = $_SERVER['HTTP_HOST'];
} else {
    $host = "default";
}

$request = Request::createFromGlobals();

$url_arguments = array();
$valid_arguments = true;
$cache_filename = array($config["hosts"][$host]["slug"]);

foreach ($config["url_arguments"] as $url_argument) {
    $url_argument = array_merge($default_url_argument, $url_argument);
    $argument_name = $url_arguments[$url_argument["argument"]]["name"] = $url_argument["argument"];
    switch ($url_argument['type']) {
        case "integer":
            $filter_type = FILTER_SANITIZE_NUMBER_INT;
            break;
        case "float":
            $filter_type = FILTER_SANITIZE_NUMBER_FLOAT;
            break;
        case "custom":
            $filter_type = FILTER_CALLBACK;
            break;
        default:
            $filter_type = FILTER_SANITIZE_STRING;
    }

    $default_value = $url_argument['default'];
    if (!empty($url_argument['default_callback']) && is_callable($url_argument['default_callback'])) {
        $default_value = call_user_func($url_argument['default_callback'], $url_argument);
    }

    if ($url_argument['type'] === "custom") {
        $url_arguments[$argument_name]["original"] = $request->query->filter($argument_name, $default_value, false, $filter_type, array('options' => $url_argument["sanitize_callback"]));
    } else {
        $url_arguments[$argument_name]["original"] = $request->query->filter($argument_name, $default_value, false, $filter_type);
    }

    $url_arguments[$argument_name]["active"] = $url_arguments[$argument_name]["original"];

    // If there's a validation callback, run it and flag if it returns false
    if (!empty($url_argument['validate_callback']) && is_callable($url_argument['validate_callback']) && call_user_func($url_argument['validate_callback'], $url_arguments[$argument_name]["active"], $url_argument) === false) {
        $valid_arguments = false;
    }

    // If there's a mutation callback, run it and return the value
    if (!empty($url_argument['mutate_callback']) && is_callable($url_argument['mutate_callback'])) {
        $url_arguments[$argument_name]["active"] = call_user_func($url_argument['mutate_callback'], $url_arguments[$argument_name]["active"], $url_argument);
    }

    $cache_filename[] = $url_arguments[$argument_name]["active"];
}

// If we're validating the arguments and any of the above have failed their validation callback, now is the time to bail out to the redirect location
if ($config["global"]["validate_arguments"] && $valid_arguments === false) {
    header("Location: " . $config["hosts"][$host]["redirect_location"]);
    exit();
}

$filename = preg_replace("/[^A-Za-z0-9-.]+/", "_", implode("-", $cache_filename)) . ".pdf";
$file_path = __DIR__ . '/cache/' . $filename;

// If a cached file already exists, just output that to the browser
if ($config["global"]["cache_dynamic_files"] && file_exists($file_path)) {
    // We send to a browser
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    echo file_get_contents($file_path);
    exit();
}

// initiate FPDI
$pdf = new Fpdi();
$pdf_template_file = $config["hosts"][$host]["pdf_template"];

// If there's a PDF template callback, run it and return the value
if (!empty($config["hosts"][$host]['pdf_template_callback']) && is_callable($config["hosts"][$host]['pdf_template_callback'])) {
    $pdf_template_file = call_user_func($config["hosts"][$host]['pdf_template_callback'], $config["hosts"][$host], $url_arguments);
}

$pdf_stream = new \setasign\Fpdi\PdfParser\StreamReader(fopen(__DIR__ . '/' . $pdf_template_file, 'r'));

$pdf->setSourceFile($pdf_stream);

// Use page 1 of the PDF template file as the base for the dynamic PDF
$tplIdx = $pdf->importPage(1);
$size = $pdf->getTemplateSize($tplIdx);
$pdf->AddPage($config["hosts"][$host]["pdf_orientation"], array($size['width'], $size['height']));
$pdf->useTemplate($tplIdx, 0, 0, $size['width'], $size['height']);

// We need to register the fonts from our resources directory - make sure they're registered in config.json!
foreach ($config["fonts"] as $font) {
    $pdf->AddFont($font["name"], $font["style"], $font["file"]);
}

foreach ($config["text_blocks"] as $text_block) {
    $text_block = array_merge($default_text_block, $text_block);
    $text_template = $config["text_templates"][$text_block["text_template"]];
    $pdf->SetFont($text_template["font"], $text_template["style"]);
    $pdf->SetTextColor($text_template["r"], $text_template["g"], $text_template["b"]);
    $font_size = $text_template["size"];
    $pdf->SetFontSize($text_template["size"]);

    $pdf->SetXY($text_block["position"]["x"], $text_block["position"]["y"]);

    $text = $text_block["text"];

    // Loop through the URL arguments and replace placeholder values with the processed URL argument
    foreach ($url_arguments as $url_argument) {
        $placeholder = "%%" . strtoupper($url_argument["name"]) . "%%";
        $text = str_replace($placeholder, $url_argument["active"], $text);
    }

    // If there's a text callback, run it and return the value
    if (!empty($url_argument['text_callback']) && is_callable($url_argument['text_callback'])) {
        $text = call_user_func($url_argument['text_callback'], $text, $url_arguments);
    }

    if ($text_block["fit_line"]) {
        while ($pdf->GetStringWidth($text) > $text_block["position"]["width"] - 2) {
            $font_size--;
            $pdf->SetFontSize($font_size);
        }
    }

    $pdf->MultiCell($text_block["position"]["width"], $text_block["position"]["height"], $text, $config["global"]["show_borders"], $text_block["position"]["align"], false);
}

if ($config["global"]["cache_dynamic_files"]) {
    $pdf->Output($file_path, "F");
}
$pdf->Output($filename, "I");