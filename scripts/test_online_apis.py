import ssl
import json
import urllib.request
import urllib.error
import subprocess

# 获取 Admin Token
cmd = ["docker", "exec", "logshare-hyperf", "php", "-r", 'require "core.php"; echo \\App\\Config::Get("admin")["token"] ?? "";']
token = subprocess.check_output(cmd, text=True).strip()
print(f"=== 获取到 Admin Token，长度: {len(token)} ===")

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

BASE_URL = "https://127.0.0.1"
HOST = "api.logshare.cn"

def request(method, path, headers=None, body=None):
    if headers is None:
        headers = {}
    headers["Host"] = HOST
    if token and "/admin/" in path and "Authorization" not in headers:
        headers["Authorization"] = f"Bearer {token}"
    
    data = body.encode("utf-8") if isinstance(body, str) else body
    req = urllib.request.Request(f"{BASE_URL}{path}", data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=10) as resp:
            status = resp.status
            content = resp.read().decode("utf-8", errors="replace")
            return status, content
    except urllib.error.HTTPError as e:
        status = e.code
        content = e.read().decode("utf-8", errors="replace")
        return status, content
    except Exception as e:
        return 0, str(e)

endpoints = [
    ("GET", "/v1/limits", None, None, "公开存储限制与规格"),
    ("GET", "/v1/filters", None, None, "公开过滤器列表"),
    ("GET", "/", None, None, "API 根路径索引"),
    ("GET", "/security/stats", None, None, "OpenLiteWaf 统计概览"),
    ("GET", "/stats/data", None, None, "OpenLiteStats 访问分析数据"),
    ("GET", "/v1/admin/logs?page=1&limit=5", None, None, "Admin 日志列表检索"),
    ("GET", "/v1/admin/system/stats", None, None, "Admin 系统统计"),
    ("GET", "/v1/admin/system/storage-health", None, None, "Admin 存储健康诊断"),
    ("GET", "/v1/admin/security/overview", None, None, "Admin 安全态势感知概览"),
    ("GET", "/v1/admin/security/bans", None, None, "Admin 封禁 IP 列表"),
    ("GET", "/v1/admin/security/content-rules", None, None, "Admin 违规内容规则"),
    ("GET", "/v1/admin/analytics/sources", None, None, "Admin 客户端来源分析"),
    ("GET", "/v1/admin/analytics/versions", None, None, "Admin 游戏版本分析"),
    ("GET", "/v1/admin/analytics/trends?hours=24", None, None, "Admin 趋势分析"),
    ("GET", "/v1/admin/event-queue/stats", None, None, "Admin 事件队列概况"),
    ("GET", "/v1/admin/event-queue/dead?limit=10", None, None, "Admin 死信队列列表"),
    ("GET", "/v1/admin/audit/logs?limit=10", None, None, "Admin 操作审计日志"),
    ("GET", "/v1/admin/telemetry/stats", None, None, "Admin 遥测统计聚合"),
    ("GET", "/v1/admin/system/logs?limit=10", None, None, "Admin 系统诊断日志"),
    ("GET", "/v1/admin/config", None, None, "Admin 配置读取 (脱敏)"),
]

print("\n=== 开始全面测试线上 API ===")
passed = 0
failed = 0

for method, path, headers, body, desc in endpoints:
    status, res_body = request(method, path, headers, body)
    is_ok = 200 <= status < 300
    icon = "✓" if is_ok else "✗"
    
    # 格式化预览
    preview = res_body[:100].replace("\n", " ")
    try:
        j = json.loads(res_body)
        if isinstance(j, dict):
            if j.get("success") is False:
                is_ok = False
                icon = "✗"
            preview = f"keys: {list(j.keys())[:5]}"
            if "data" in j and isinstance(j["data"], dict):
                preview += f" -> data: {list(j['data'].keys())[:5]}"
    except:
        pass
        
    print(f"[{icon}] {method:4s} {path:36s} -> {status:3d} | {desc} | {preview}")
    if is_ok:
        passed += 1
    else:
        failed += 1

print(f"\n基础只读端点: 通过 {passed} 项, 失败 {failed} 项")

print("\n=== 深度验证: 配置修改 ===")
status, res_body = request("GET", "/v1/admin/config")
raw_json = json.loads(res_body)
current_cfg = raw_json.get("data") if isinstance(raw_json.get("data"), dict) else raw_json
current_storage_time = current_cfg.get("storage", {}).get("storageTime")
print(f"当前配置中的 storageTime: {current_storage_time}")

update_payload = json.dumps({"storage": {"storageTime": 604800}})
status, res_body = request("PUT", "/v1/admin/config", {"Content-Type": "application/json"}, update_payload)
print(f"PUT /v1/admin/config (with Content-Type) -> {status} | body: {res_body[:150]}")

status_no_ct, res_body_no_ct = request("PUT", "/v1/admin/config", {}, update_payload)
print(f"PUT /v1/admin/config (NO Content-Type) -> {status_no_ct} | body: {res_body_no_ct[:150]}")

status, res_body = request("GET", "/v1/admin/config")
raw_json = json.loads(res_body)
updated_cfg = raw_json.get("data") if isinstance(raw_json.get("data"), dict) else raw_json
print(f"修改后配置中的 storageTime: {updated_cfg.get('storage', {}).get('storageTime')}")

print("\n=== 深度验证: 前端 CORS OPTIONS 预检 ===")
status, res_body = request("OPTIONS", "/v1/admin/config")
print(f"OPTIONS /v1/admin/config -> {status}")
