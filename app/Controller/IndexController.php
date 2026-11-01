<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller]
class IndexController extends AbstractController
{
    #[GetMapping(path: '/')]
    public function index(): ResponseInterface
    {
        // 端点清单为快照，仅作发现用途；新增端点时以 openapi.yaml 为唯一权威并同步此处。
        $endpoints = [
            'POST /1/log',
            'DELETE /1/log/{id}',
            'GET /1/limits',
            'GET /1/raw/{id}',
            'GET /1/raw/{id}/{filename:.+}',
            'GET /1/log/{id}',
            'GET /1/insights/{id}',
            'GET /1/insights/{id}/{filename:.+}',
            'POST /v1/log',
            'DELETE /v1/log/{id}',
            'GET /v1/limits',
            'GET /v1/raw/{id}',
            'GET /v1/raw/{id}/{filename:.+}',
            'GET /v1/log/{id}',
            'GET /v1/insights/{id}',
            'GET /v1/insights/{id}/{filename:.+}',
        ];

        return \App\ApiResponse::success(['endpoints' => $endpoints], 'LogShare API');
    }
}
