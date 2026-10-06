<?php

declare(strict_types=1);

namespace GMA\Tests\Unit\Admin;

use GMA\Admin\SettingsSanitizer;
use PHPUnit\Framework\TestCase;

class SettingsSanitizerTest extends TestCase
{
    public function test_unknown_geocoder_falls_back_to_mock(): void
    {
        $this->assertSame('mock', SettingsSanitizer::geocoder('google'));
        $this->assertSame('mock', SettingsSanitizer::geocoder(null));
        $this->assertSame('nominatim', SettingsSanitizer::geocoder('nominatim'));
    }

    public function test_tile_url_must_be_https_with_all_placeholders(): void
    {
        $this->assertSame(
            'https://tiles.example.com/{z}/{x}/{y}.png',
            SettingsSanitizer::tileUrl('https://tiles.example.com/{z}/{x}/{y}.png')
        );

        $this->assertSame(SettingsSanitizer::DEFAULT_TILE_URL, SettingsSanitizer::tileUrl('http://tiles.example.com/{z}/{x}/{y}.png'));
        $this->assertSame(SettingsSanitizer::DEFAULT_TILE_URL, SettingsSanitizer::tileUrl('https://tiles.example.com/{z}/{x}.png'));
        $this->assertSame(SettingsSanitizer::DEFAULT_TILE_URL, SettingsSanitizer::tileUrl('https://x.com/{z}/{x}/{y}.png evil'));
        $this->assertSame(SettingsSanitizer::DEFAULT_TILE_URL, SettingsSanitizer::tileUrl(['array']));
    }

    public function test_latitude_and_longitude_are_bounded(): void
    {
        $this->assertSame(50.45, SettingsSanitizer::latitude('50.45'));
        $this->assertSame(SettingsSanitizer::DEFAULT_LAT, SettingsSanitizer::latitude('91'));
        $this->assertSame(SettingsSanitizer::DEFAULT_LNG, SettingsSanitizer::longitude('-181'));
        $this->assertSame(SettingsSanitizer::DEFAULT_LAT, SettingsSanitizer::latitude('not a number'));
    }

    public function test_zoom_is_an_integer_between_one_and_eighteen(): void
    {
        $this->assertSame(7, SettingsSanitizer::zoom('7'));
        $this->assertSame(SettingsSanitizer::DEFAULT_ZOOM, SettingsSanitizer::zoom('0'));
        $this->assertSame(SettingsSanitizer::DEFAULT_ZOOM, SettingsSanitizer::zoom('19'));
        $this->assertSame(SettingsSanitizer::DEFAULT_ZOOM, SettingsSanitizer::zoom('5.5'));
    }

    public function test_user_agent_strips_control_characters_and_never_empties(): void
    {
        $this->assertSame('bot (me@example.com)', SettingsSanitizer::userAgent("bot\x00 (me@example.com)\n"));
        $this->assertSame(SettingsSanitizer::DEFAULT_USER_AGENT, SettingsSanitizer::userAgent('   '));
    }
}
