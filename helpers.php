<?php

function strip_accents($input)
{
    // Strip accents when the font can't handle it!
    $input = strtr(utf8_decode($input), utf8_decode('àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ'), 'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');

    return preg_replace("/[^\p{L}0-9., '\-()]+/", "", trim($input));
}