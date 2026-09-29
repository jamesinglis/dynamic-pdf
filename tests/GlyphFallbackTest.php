<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
