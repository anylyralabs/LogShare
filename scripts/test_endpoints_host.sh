#!/bin/bash

HOST="${HOST:-api.logshare.cn}"

test_one() {
    local method="$1"
    local path="$2"
    local ctype="$3"
    local data="$4"
    local desc="$5"

    local header_args=(-H "Host: ${HOST}")
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
