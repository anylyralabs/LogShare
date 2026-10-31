#!/bin/bash

# 确保在 bash 下运行
ADMIN_TOKEN=$(docker exec logshare-hyperf php -r 'require "core.php"; echo \App\Config::Get("admin.token");')
echo "=== ADMIN TOKEN LENGTH: ${#ADMIN_TOKEN} ==="

test_one() {
    local method="$1"
    local path="$2"
    local ctype="$3"
    local data="$4"
    local desc="$5"

    local header_args=(-H "Host: api.logshare.cn")
    if [ -n "$ADMIN_TOKEN" ]; then
        header_args+=(-H "Authorization: Bearer $ADMIN_TOKEN")
    fi
    if [ -n "$ctype" ]; then
        header_args+=(-H "Content-Type: $ctype")
    fi

    local data_args=()
    if [ -n "$data" ]; then
        data_args+=(-d "$data")
    fi

    local tmp_out="/tmp/curl_resp_$$.txt"
    local status
    status=$(curl -sk -o "$tmp_out" -w "%{http_code}" -X "$method" "${header_args[@]}" "${data_args[@]}" "https://127.0.0.1${path}")
    local body
    body=$(head -c 200 "$tmp_out")
    rm -f "$tmp_out"

    if [ "$status" -ge 200 ] && [ "$status" -lt 300 ]; then
        echo -e "[✓] $method $path -> $status | $desc | $body"
    else
        echo -e "[✗] $method $path -> $status | $desc | $body"
    fi
}

echo "=== 1. 公开端点测试 ==="
test_one "GET" "/v1/limits" "" "" "存储上限"
test_one "GET" "/v1/filters" "" "" "脱敏过滤器列表"
test_one "GET" "/" "" "" "根目录索引"
test_one "GET" "/security/stats" "" "" "WAF 统计"
test_one "GET" "/stats/data" "" "" "访问统计数据"

echo ""
echo "=== 2. Admin 核心大盘与统计 ==="
test_one "GET" "/v1/admin/logs?page=1&limit=5" "" "" "日志检索"
test_one "GET" "/v1/admin/system/stats" "" "" "系统运行指标"
test_one "GET" "/v1/admin/system/storage-health" "" "" "存储健康"
test_one "GET" "/v1/admin/security/overview" "" "" "安全概览"
test_one "GET" "/v1/admin/security/bans" "" "" "IP 封禁列表"
test_one "GET" "/v1/admin/security/content-rules" "" "" "违规内容规则"
test_one "GET" "/v1/admin/analytics/sources" "" "" "来源分析"
test_one "GET" "/v1/admin/analytics/versions" "" "" "版本分析"
test_one "GET" "/v1/admin/analytics/trends?hours=24" "" "" "趋势分析"

echo ""
echo "=== 3. 事件队列与死信 ==="
test_one "GET" "/v1/admin/event-queue/stats" "" "" "事件队列概况"
test_one "GET" "/v1/admin/event-queue/dead?limit=10" "" "" "死信队列列表"

echo ""
echo "=== 4. 审计与系统日志 ==="
test_one "GET" "/v1/admin/audit/logs?limit=10" "" "" "审计日志"
test_one "GET" "/v1/admin/telemetry/stats" "" "" "遥测统计"
test_one "GET" "/v1/admin/system/logs?limit=10" "" "" "系统日志"

echo ""
echo "=== 5. 系统配置 ==="
test_one "GET" "/v1/admin/config" "" "" "配置读取"

echo ""
echo "=== 6. 配置修改实测 ==="
test_one "PUT" "/v1/admin/config" "application/json" '{"storage":{"storageTime":604800}}' "修改配置(带Content-Type)"
test_one "PUT" "/v1/admin/config" "" '{"storage":{"storageTime":604800}}' "修改配置(不带Content-Type)"

echo ""
echo "=== 7. 前端 CORS 预检 ==="
test_one "OPTIONS" "/v1/admin/config" "" "" "OPTIONS 预检配置"
