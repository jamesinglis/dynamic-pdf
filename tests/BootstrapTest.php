<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testCoreFilesAreLoaded(): void
    {
        $this->assertTrue(function_exists('load_config'));
        $this->assertTrue(function_exists('sanitize_process_name_filter'));
    }
}
