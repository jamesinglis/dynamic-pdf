<?php

require __DIR__ . '/vendor/autoload.php';

use Guzzle\Http\Client;

$client = new Client();

$config = false;
if (file_exists(__DIR__ . '/config.json')) {
    $config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
}

if (!$config) {
    die("Could not load configuration file.");
}

$file_path = isset($config['bulk_create']['file_path']) ? __DIR__ . $config['bulk_create']['file_path'] : __DIR__ . '/resources/';

$filename = isset($config['bulk_create']['file_name']) ? $config['bulk_create']['file_name'] : 'recipients.csv';
$filename_output = str_replace('.csv', ' (output).csv', $filename);

$header = isset($config['bulk_create']['has_header']) ? $config['bulk_create']['has_header'] : false;

if (file_exists($file_path . $filename)) {
    $count = 0;
    $count2 = 0;
    $fh = fopen($file_path . $filename, "r");
    $fho = fopen($file_path . $filename_output, "w+");
    while (($data = fgetcsv($fh, 2048, ",")) !== false) {
        $count++;
        $output_data = $data;
        if ($header && $count === 1) {
            array_push($output_data, 'url');
        } else {
            $count2++;
            $url = sprintf("http://blw2018certificate.com.au/?name=%s&amount=%s", rawurlencode($data[0] . " " . $data[1]), $data[4]);
            array_push($output_data, $url);

            echo $count2 . sprintf(" Generating certificate for %s...", $data[0] . " " . $data[1]) . PHP_EOL;
//        echo $url . PHP_EOL;
            $client->get($url)->send();
            fputcsv($fho, $output_data, ",", '"');
        }
    }
    fclose($fh);
    fclose($fho);
}