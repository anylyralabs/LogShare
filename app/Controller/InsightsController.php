<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class InsightsController extends AbstractController
{
    #[GetMapping(path: 'insights/{id}')]
    public function insights(string $id): ResponseInterface
    {
        $log = $this->loadLog($id);
        $files = $log->getFiles();

        if ($files === []) {
            throw new ApiError(404, "Log has no files.");
        }

        if (count($files) > 1) {
            return $this->respondSuccess(
                $this->fileListPayload($log),
                'Multiple files available; specify a filename.'
            );
        }

        return $this->respondRawJson($log->getFileAnalysisJson($files[0]['name']));
    }

    #[GetMapping(path: 'insights/{id}/{filename:.+}')]
    public function insightsFile(string $id, string $filename): ResponseInterface
    {
        $log = $this->loadLog($id);
        // Hyperf 不会解码路由参数；文件名在数据库中精确匹配，无路径遍历风险
        $filename = rawurldecode($filename);

        return $this->respondRawJson($log->getFileAnalysisJson($filename));
    }

    private function loadLog(string $id): \App\Log
    {
        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        $log->renew();

        return $log;
    }
}
