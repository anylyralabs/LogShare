<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Data\Token;
use App\Storage\MongoStorage;
use Tests\HttpTestCase;

/**
 * 真实 HTTP 集成测试：请求走完整 CORS → RateLimit → 路由 → 控制器链路。
 *
 * 存储链路用例（上传/读取/删除）依赖 MongoDB，不可用时自动跳过
 * （CI 的 test job 会启动 MongoDB 服务全量执行）。
 */
class ApiTest extends HttpTestCase
{
    public function testLimitsReturnsUploadLimits(): void
    {
        $response = $this->get('/v1/limits');

        $response->assertSuccessful();
        $response->assertJsonFragment(['storageTime' => 604800]);
        $response->assertJsonFragment(['maxFiles' => 200]);
        $response->assertJsonFragment(['maxTotalBytes' => 20971520]);
        $response->assertJsonFragment(['maxRequestBodyBytes' => 33554432]);
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

    public function testPostLogWithoutFilesReturns400(): void
    {
        $response = $this->post('/v1/log', []);

        $response->assertStatus(400);
        $response->assertJsonFragment(['success' => false]);
    }

    public function testRemovedAnalyseEndpointReturns404(): void
    {
        $response = $this->post('/v1/analyse', [
            'content' => 'legacy direct analysis',
        ]);

        $response->assertStatus(404);
    }

    public function testDeleteLogWithoutTokenReturns401(): void
    {
        $response = $this->delete('/v1/log/abc123');

        $response->assertStatus(401);
        $response->assertJsonFragment(['success' => false]);
    }

    public function testUploadReadDeleteRoundTrip(): void
    {
        if (!MongoStorage::ping()) {
            $this->markTestSkipped('MongoDB is not available');
        }

        $content = "[12:34:56] [Server thread/INFO]: ApiTest round trip\n";
        $create = $this->post('/v1/log', [
            'files' => [['name' => 'latest.log', 'content' => $content]],
        ]);

        $create->assertSuccessful();
        $id = $create->json('id');
        $token = $create->json('token');
        $this->assertNotEmpty($id);
        $this->assertNotEmpty($token);
        $this->assertSame(1, $create->json('fileCount'));
        $this->assertSame('latest.log', $create->json('files.0.name'));

        // TrimFilter 会去除首尾空白，存储的是 trim 后的内容
        $raw = $this->get("/v1/raw/{$id}");
        $raw->assertSuccessful();
        $this->assertSame(trim($content), $raw->getContent());

        $delete = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $token]);
        $delete->assertSuccessful();
        $this->assertContains($id, $delete->json('deleted'));

        $gone = $this->get("/v1/raw/{$id}");
        $gone->assertStatus(404);
    }

    public function testLegacyContentUploadRemainsCompatible(): void
    {
        if (!MongoStorage::ping()) {
            $this->markTestSkipped('MongoDB is not available');
        }

        $content = "[12:34:56] [Server thread/INFO]: Legacy content upload " . uniqid('', true) . "\n";
        $create = $this->post('/v1/log', [
            'content' => $content,
            'source' => 'legacy-client/1.0',
        ]);

        $create->assertSuccessful();
        $id = (string) $create->json('id');
        $token = (string) $create->json('token');
        $this->assertSame(1, $create->json('fileCount'));
        $this->assertSame('latest.log', $create->json('files.0.name'));
        $rawPath = (string) parse_url((string) $create->json('raw'), PHP_URL_PATH);
        $this->assertSame(trim($content), $this->get($rawPath)->getContent());

        $delete = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $token]);
        $delete->assertSuccessful();
    }

    public function testLegacyContentWithFilesKeepsMainRawLink(): void
    {
        if (!MongoStorage::ping()) {
            $this->markTestSkipped('MongoDB is not available');
        }

        $main = "[12:34:56] [Server thread/INFO]: Legacy main " . uniqid('', true) . "\n";
        $crash = "Legacy crash " . uniqid('', true) . "\n";
        $create = $this->post('/v1/log', [
            'content' => $main,
            'files' => [['name' => 'crash.txt', 'content' => $crash]],
        ]);

        $create->assertSuccessful();
        $id = (string) $create->json('id');
        $token = (string) $create->json('token');
        $this->assertSame(2, $create->json('fileCount'));
        $this->assertStringContainsString('/latest.log', (string) $create->json('raw'));

        $rawPath = (string) parse_url((string) $create->json('raw'), PHP_URL_PATH);
        $this->assertSame(trim($main), $this->get($rawPath)->getContent());

        $delete = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $token]);
        $delete->assertSuccessful();
    }

    public function testMultiFileListAndPerFileAccess(): void
    {
        if (!MongoStorage::ping()) {
            $this->markTestSkipped('MongoDB is not available');
        }

        $marker = uniqid('multi-', true);
        $create = $this->post('/v1/log', [
            'files' => [
                ['name' => 'latest.log', 'content' => "[12:00:00] [Server thread/INFO]: {$marker}\n"],
                ['name' => 'crash.txt', 'content' => "Crash {$marker}\n"],
            ],
        ]);

        $create->assertSuccessful();
        $id = (string) $create->json('id');
        $token = (string) $create->json('token');
        $this->assertSame(2, $create->json('fileCount'));
        $this->assertTrue((bool) $create->json('requiresFileSelection'));

        $meta = $this->get("/v1/log/{$id}");
        $meta->assertSuccessful();
        $this->assertSame(2, $meta->json('fileCount'));
        $this->assertTrue((bool) $meta->json('requiresFileSelection'));
        $this->assertContains('crash.txt', array_column($meta->json('files'), 'name'));

        // 多文件时直接访问 raw/{id} 返回 200 + 文件列表
        $multiRaw = $this->get("/v1/raw/{$id}");
        $multiRaw->assertSuccessful();
        $this->assertSame(2, $multiRaw->json('fileCount'));

        // 指定文件名才返回内容
        $raw = $this->get("/v1/raw/{$id}/latest.log");
        $raw->assertSuccessful();
        $this->assertStringContainsString($marker, $raw->getContent());

        // 多文件分析同样先返回文件列表
        $multiInsights = $this->get("/v1/insights/{$id}");
        $multiInsights->assertSuccessful();
        $this->assertSame(2, $multiInsights->json('fileCount'));

        // 指定文件名后执行真正的 Codex 分析
        $insights = $this->get("/v1/insights/{$id}/latest.log");
        $insights->assertSuccessful();
        $this->assertIsArray($insights->json());

        $delete = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $token]);
        $delete->assertSuccessful();
    }

    public function testUploadResponseTokenCannotBeLeakedFromStorage(): void
    {
        if (!MongoStorage::ping()) {
            $this->markTestSkipped('MongoDB is not available');
        }

        // 内容必须唯一：否则会命中去重指纹、复用上一轮遗留的 ID 与旧 token
        $content = "[00:00:01] [Server thread/INFO]: token storage check " . uniqid('', true) . "\n";
        $create = $this->post('/v1/log', [
            'files' => [['name' => 'latest.log', 'content' => $content]],
        ]);

        $id = (string) $create->json('id');
        $token = (string) $create->json('token');

        // 存储中落库的是带前缀的 SHA-256，而非原文 token
        $stored = MongoStorage::Get(new \App\Id($id));
        $this->assertNotNull($stored);
        $this->assertNotSame($token, $stored['token']);
        $this->assertSame(Token::hashValue($token), $stored['token']);
        $this->assertStringStartsWith(Token::HASH_PREFIX, (string) $stored['token']);

        // 库里的值本身不能作为删除凭证
        $leakAttempt = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $stored['token']]);
        $this->assertSame(400, $leakAttempt->getStatusCode());

        // 原文 token 仍可正常通过删除鉴权（哈希比对路径）
        $delete = $this->delete("/v1/log/{$id}", [], ['Authorization' => 'Bearer ' . $token]);
        $delete->assertSuccessful();
        $this->assertNull(MongoStorage::Get(new \App\Id($id)));
    }

}
