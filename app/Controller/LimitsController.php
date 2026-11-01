<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LimitsController extends AbstractController
{
    #[GetMapping(path: 'limits')]
    public function limits(): ResponseInterface
    {
        $config = \App\Config::Get('storage');
        $upload = $config['uploadFiles'] ?? [];

        return $this->respondJson([
            'storageTime' => (int) ($config['storageTime'] ?? (7 * 24 * 60 * 60)),
            'maxFiles' => (int) ($upload['maxFiles'] ?? 200),
            'maxTotalBytes' => (int) ($upload['maxTotalBytes'] ?? (20 * 1024 * 1024)),
            'maxRequestBodyBytes' => (int) ($upload['maxRequestBodyBytes'] ?? (32 * 1024 * 1024)),
        ]);
    }
}
