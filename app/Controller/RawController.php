<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class RawController extends AbstractController
{
    #[GetMapping(path: 'raw/{id}')]
    public function raw(string $id): ResponseInterface
    {
        return $this->serve($id, null);
    }

    #[GetMapping(path: 'raw/{id}/{filename:.+}')]
    public function rawFile(string $id, string $filename): ResponseInterface
    {
        return $this->serve($id, $filename);
    }

    private function serve(string $id, ?string $filename): ResponseInterface
    {
        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        $log->renew();

        if ($filename !== null) {
            // Hyperf 不会解码路由参数；浏览器对非 ASCII 文件名必然发送百分号编码，需手动解码
            // 解码后仅用于 log_files.name 精确匹配（数据库列），无路径遍历风险
            $filename = rawurldecode($filename);
            $content = $log->getFile($filename);
            if ($content === null) {
                throw new ApiError(404, "File not found.");
            }
            return $this->respondText($content, 'text/plain; charset=utf-8');
        }

        $files = $log->getFiles();
        if (count($files) === 1) {
            $content = $log->getFile($files[0]['name']);
            if ($content === null) {
                throw new ApiError(404, "File not found.");
            }
            return $this->respondText($content, 'text/plain; charset=utf-8');
        }

        if ($files === []) {
            throw new ApiError(404, "Log has no files.");
        }

        return $this->respondSuccess(
            $this->fileListPayload($log),
            'Multiple files available; specify a filename.'
        );
    }
}
