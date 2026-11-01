<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\HttpTestCase;

class ControllerTest extends HttpTestCase
{
    public function testLimitsReturnsUploadLimits(): void
    {
        $response = $this->get('/v1/limits');

        $response->assertSuccessful();
        $response->assertJsonFragment(['storageTime' => 604800]);
        $response->assertJsonFragment(['maxFiles' => 200]);
        $response->assertJsonFragment(['maxTotalBytes' => 20971520]);
    }

    public function testLegacyPrefixRoutesToSameController(): void
    {
        $v1 = $this->get('/v1/limits');
        $legacy = $this->get('/1/limits');

        $v1->assertSuccessful();
        $legacy->assertSuccessful();
        $this->assertSame($v1->json('storageTime'), $legacy->json('storageTime'));
    }

    public function testUnknownEndpointReturnsNotFound(): void
    {
        $response = $this->get('/v1/does-not-exist');

        $response->assertStatus(404);
    }
}
