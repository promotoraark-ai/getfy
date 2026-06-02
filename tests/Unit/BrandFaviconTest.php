<?php

namespace Tests\Unit;

use App\Support\BrandFavicon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BrandFaviconTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('getfy.favicon_url', '/brand/favicon.png');
        parent::tearDown();
    }

    public function test_public_url_uses_route_for_local_public_file(): void
    {
        if (! is_file(public_path('brand/favicon.png'))) {
            $this->markTestSkipped('Default favicon file not present.');
        }

        Config::set('getfy.favicon_url', '/brand/favicon.png');

        $url = BrandFavicon::publicUrl();

        $this->assertStringContainsString('/brand/favicon.png', $url);
        $this->assertStringContainsString('v=', $url);
    }

    public function test_public_url_returns_external_url_as_is(): void
    {
        Config::set('getfy.favicon_url', 'https://cdn.example.com/icon.png');

        $this->assertSame('https://cdn.example.com/icon.png', BrandFavicon::publicUrl());
    }

    public function test_source_path_resolves_storage_url(): void
    {
        $dir = storage_path('app/public/white-label-test');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir.'/favicon.png';
        if (! is_file($file)) {
            copy(public_path('brand/favicon.png'), $file);
        }

        Config::set('getfy.favicon_url', '/storage/white-label-test/favicon.png');

        $this->assertSame($file, BrandFavicon::sourcePath());

        @unlink($file);
        @rmdir($dir);
    }
}
