<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LogMetaController extends AbstractController
{
    #[GetMapping(path: 'log/{id}')]
    public function meta(string $id): ResponseInterface
    {
        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        return $this->respondSuccess([
            'id' => $logId->get(),
            'size' => $log->getSize(),
            'lines' => $log->getLineNumbers(),
            'created' => $log->getCreated(),
            'expires' => $log->getExpires(),
            // visible=false 是客户端显式声明的「不要公开」，公开元信息接口必须尊重它
            'metadata' => \App\Data\MetadataEntry::serializeVisible($log->getMetadata()),
            'source' => $log->getSource(),
            'files' => $log->getFiles(),
            'raw' => $this->rawUrl($logId),
        ], 'Log metadata retrieved successfully');
    }

    private function rawUrl(\App\Id $id): string
    {
        $urls = \App\Config::Get('urls');
        $apiPrefix = $this->apiPrefix();
        return $urls['apiBaseUrl'] . "/{$apiPrefix}/raw/" . $id->get();
    }
}
