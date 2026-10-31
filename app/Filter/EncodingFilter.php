<?php

namespace App\Filter;

/**
 * Sanitize encoding: replace invalid UTF-8 byte sequences with U+FFFD.
 *
 * 日志常经 form-urlencoded 上传或来自原始文件，可能携带非 UTF-8 字节
 * （GBK 字符、二进制残留）。这些字节会让下游所有 json_encode(payload)
 * 静默返回 false —— 上传响应与结构化分析结果无法编码。
 * 在过滤链最前端清洗一次，保证全链路（存储/缓存/上传解析）编码安全。
 *
 * 合法 UTF-8 内容经此过滤器无损通过。
 */
class EncodingFilter extends Filter
{
    public static function filter(string $data): string
    {
        // UTF-8 → UTF-8 转换会把非法序列替换为 U+FFFD（�），合法内容不变
        return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    }
}
