<?php

namespace Tests\Unit;

use App\Support\SafeRemoteUrl;
use Tests\TestCase;

class SafeRemoteUrlTest extends TestCase
{
    public function test_blocks_localhost_and_metadata_ips(): void
    {
        $this->assertFalse(SafeRemoteUrl::isAllowedHttpUrl('http://127.0.0.1/file.pdf'));
        $this->assertFalse(SafeRemoteUrl::isAllowedHttpUrl('http://169.254.169.254/latest/meta-data'));
    }

    public function test_allows_r2_getfy_host(): void
    {
        $this->assertTrue(SafeRemoteUrl::isAllowedHttpUrl('https://r2.getfy.cloud/bucket/file.pdf'));
    }
}
