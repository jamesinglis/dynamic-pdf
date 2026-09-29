<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextPipelineTest extends TestCase
{
    /** @return array<string, array{string, string}> input (UTF-8) => expected cp1252 bytes */
    public static function pdfTextCases(): array
    {
        return [
            'e acute' => ['José', "Jos\xE9"],
            'o slash' => ['Søren', "S\xF8ren"],
            'e diaeresis' => ['Zoë', "Zo\xEB"],
            'ae ligature and o slash' => ['Ærø', "\xC6r\xF8"],
            'n tilde and u acute' => ['José Núñez', "Jos\xE9 N\xFA\xF1ez"],
            'L stroke transliterates' => ['Łukasz', 'Lukasz'],
            'straight apostrophe' => ["O'Brien", "O'Brien"],
            'curly apostrophe' => ["O\u{2019}Brien", "O\x92Brien"],
            'em dash' => ["a\u{2014}b", "a\x97b"],
            'euro sign' => ["\u{20AC}5", "\x805"],
            'ampersand' => ['Smith & Jones', 'Smith & Jones'],
            'ascii untouched' => ['Plain ASCII 123 .,-()', 'Plain ASCII 123 .,-()'],
            'literal question mark kept' => ['Why?', 'Why?'],
            'no cp1252 form is dropped, not printed as ?' => ["Wei \u{4E2D}", 'Wei '],
            'emoji dropped' => ["Go \u{1F600}!", 'Go !'],
            'invalid utf8 byte dropped' => ["Jos\xC3 e", 'Jos e'],
            'decomposed accent is composed' => ["Jose\u{0301}", "Jos\xE9"],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('pdfTextCases')]
    public function testPdfTextConvertsUtf8ToCp1252(string $input, string $expected): void
    {
        $this->assertSame(bin2hex($expected), bin2hex(pdf_text($input)));
    }

    /** @return array<string, array{string, string}> */
    public static function nameFilterCases(): array
    {
        return [
            'accents kept' => ['José Núñez', 'José Núñez'],
            'ampersand kept' => ['Smith & Jones', 'Smith & Jones'],
            'curly apostrophe kept as typed' => ["O\u{2019}Brien", "O\u{2019}Brien"],
            'allowed punctuation and digits kept' => ["O'Brien-Smith (Jr.), 2", "O'Brien-Smith (Jr.), 2"],
            'markup characters removed' => ['<b>Bob</b>;"', 'bBobb'],
            'surrounding spaces trimmed' => ['  Zoë  ', 'Zoë'],
            'non-breaking spaces removed' => ["\u{00A0}José\u{00A0}", 'José'],
            'emoji-only name becomes empty' => ["\u{1F600}\u{1F600}", ''],
            'invalid utf8 removed' => ["Jos\xC3 e", 'Jos e'],
            'letters outside the Latin script removed' => ["Wei \u{4E2D}", 'Wei'],
            'greek-only name becomes empty' => ["\u{039D}\u{03AF}\u{03BA}\u{03BF}\u{03C2}", ''],
            'cyrillic-only name becomes empty' => ["\u{0414}\u{043C}\u{0438}\u{0442}\u{0440}\u{0438}\u{0439}", ''],
            'latin letters outside cp1252 kept for pdf_text to transliterate' => ['Łukasz', 'Łukasz'],
            'combining mark kept' => ["Jose\u{0301}", "Jose\u{0301}"],
        ];
    }

    #[DataProvider('nameFilterCases')]
    public function testFilterNameCharacters(string $input, string $expected): void
    {
        $this->assertSame($expected, filter_name_characters($input));
    }

    public function testNameFilterOutputIsValidUtf8(): void
    {
        $this->assertTrue(mb_check_encoding(sanitize_process_name_filter("Jos\xC3 \xFF é"), 'UTF-8'));
    }

    public function testSanitizeProcessNameFilterUsesTheNameFilter(): void
    {
        $this->assertSame('Smith & Jones', sanitize_process_name_filter(' Smith & Jones<> '));
    }

    public function testStripAccentsIsADeprecatedAliasOfTheNameFilter(): void
    {
        $this->assertSame(filter_name_characters(' José & Zoë! '), strip_accents(' José & Zoë! '));
        $this->assertSame('José', strip_accents('José'));
    }

    public function testCustomUtf8DecodeIsGone(): void
    {
        $this->assertFalse(function_exists('custom_utf8_decode'));
    }

    public function testCacheFilenamesDifferForNamesThatDifferOnlyInAccentedLetters(): void
    {
        $config = ['global' => ['locale' => 'en_AU.UTF-8']];

        $this->assertNotSame(cache_filename(['PDF', 'Søren', '100'], $config), cache_filename(['PDF', 'Sören', '100'], $config));
        $this->assertNotSame(cache_filename(['PDF', 'José'], $config), cache_filename(['PDF', 'Josè'], $config));
    }

    public function testCacheFilenameIsSafeReadableAndStable(): void
    {
        $config = ['global' => ['locale' => 'en_AU.UTF-8']];
        $filename = cache_filename(['PDF', 'Smith & Jones', '100', true], $config);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.pdf$/', $filename);
        $this->assertStringStartsWith('PDF-Smith_Jones-100-1-', $filename);
        $this->assertStringEndsWith('-' . cache_config_hash($config) . '.pdf', $filename);
        $this->assertSame($filename, cache_filename(['PDF', 'Smith & Jones', '100', true], $config));
    }

    public function testCacheHashIncludesTheCoreVersion(): void
    {
        $config = ['global' => ['locale' => 'en_AU.UTF-8']];

        $this->assertSame('1.1.1', DYNAMIC_PDF_VERSION);
        $this->assertSame(substr(md5('1.1.1' . json_encode($config)), 0, 6), cache_config_hash($config));
        $this->assertNotSame(substr(md5(json_encode($config)), 0, 6), cache_config_hash($config));
    }
}
