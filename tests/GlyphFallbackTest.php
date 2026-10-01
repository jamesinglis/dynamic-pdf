<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class GlyphFallbackTest extends TestCase
{
    /** A font with every glyph */
    private static function fullFont(): callable
    {
        return fn(string $character): float => 5.0;
    }

    /** A subset font like wfw2026's SFL-ExtraBold: ASCII only, nothing from 0x80 up */
    private static function asciiOnlyFont(): callable
    {
        return fn(string $character): float => ord($character) < 0x80 ? 5.0 : 0.0;
    }

    public function testTextIsUnchangedWhenTheFontHasEveryGlyph(): void
    {
        $text = pdf_text('José Núñez Søren Ærø O’Brien');

        $this->assertSame(bin2hex($text), bin2hex(fallback_missing_glyphs($text, self::fullFont())));
    }

    public function testMissingAccentedGlyphsFallBackToAscii(): void
    {
        $this->assertSame('Jose Nunez', fallback_missing_glyphs(pdf_text('José Núñez'), self::asciiOnlyFont()));
    }

    public function testMissingLigatureFallsBackToItsLetters(): void
    {
        $this->assertSame('AErenskiold', fallback_missing_glyphs(pdf_text('Ærenskiöld'), self::asciiOnlyFont()));
    }

    public static function latinNameCases(): array
    {
        return [
            'mixed accents' => ['José Núñez', 'Jose Nunez'],
            'uppercase accents' => ['JOSÉ NÚÑEZ', 'JOSE NUNEZ'],
            'diaeresis' => ['Zoë Müller', 'Zoe Muller'],
            'decomposed accents' => ["Jose\u{0301} Mu\u{0308}ller", 'Jose Muller'],
            'ligatures and slash' => ['Ærø Œuvres', 'AEro OEuvres'],
            'lowercase ligatures' => ['æ œ Groß', 'ae oe Gross'],
            'Nordic letters' => ['Šíma Žan Þór Ðaði', 'Sima Zan THor Dadi'],
            'punctuation preserved' => ["Élodie O’Brien & René-Søren", "Elodie O'Brien & Rene-Soren"],
        ];
    }

    #[DataProvider('latinNameCases')]
    public function testMissingLatinGlyphsHaveTheSameFallbackInDifferentLocales(string $input, string $expected): void
    {
        $original = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C', 'en_AU.UTF-8', 'en_US.UTF-8'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                $encoded = pdf_text($input);
                $this->assertSame($encoded, fallback_missing_glyphs($encoded, self::fullFont()), $locale);
                $this->assertSame($expected, fallback_missing_glyphs($encoded, self::asciiOnlyFont()), $locale);
            }
        } finally {
            setlocale(LC_CTYPE, $original);
        }
    }

    public function testMissingCurlyApostropheFallsBackToStraight(): void
    {
        $this->assertSame("O'Brien", fallback_missing_glyphs(pdf_text('O’Brien'), self::asciiOnlyFont()));
    }

    public function testAsciiIsNeverTouched(): void
    {
        $this->assertSame('Smith & Jones 123', fallback_missing_glyphs('Smith & Jones 123', fn(string $character): float => 0.0));
    }

    public function testCharacterWithNoAsciiFormIsDroppedRatherThanPrintedBlank(): void
    {
        // Every cp1252 byte from 0x80 up either transliterates or disappears; none is left for the font to draw blank
        $all_high = '';
        for ($byte = 0x80; $byte <= 0xFF; $byte++) {
            $all_high .= chr($byte);
        }

        $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', fallback_missing_glyphs($all_high, self::asciiOnlyFont()));
    }

    public function testOnlyTheMissingGlyphsAreReplaced(): void
    {
        $font_without_o_slash = fn(string $character): float => $character === "\xF8" ? 0.0 : 5.0;

        $this->assertSame(bin2hex("S\x6Fren Jos\xE9"), bin2hex(fallback_missing_glyphs(pdf_text('Søren José'), $font_without_o_slash)));
    }
}
