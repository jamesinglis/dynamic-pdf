<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../helpers.php';
require __DIR__ . '/../callbacks.php';

// Production configs set a UTF-8 locale before rendering; iconv's //TRANSLIT depends on it (Ł -> L under en_AU.UTF-8, ? under C)
if (setlocale(LC_ALL, 'en_AU.UTF-8', 'en_AU.utf8') === false) {
    fwrite(STDERR, "Warning: en_AU.UTF-8 locale unavailable; pdf_text() transliteration results will differ.\n");
}
