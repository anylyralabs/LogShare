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
    ("GET", "/v1/admin/system/queue", None, None, "Admin AI 队列指标"),
    ("GET", "/v1/admin/ai/metrics", None, None, "Admin AI 性能吞吐与耗时"),
    ("GET", "/v1/admin/ai/queue/inspect", None, None, "Admin AI 队列深度审计"),
    ("GET", "/v1/admin/event-queue/stats", None, None, "Admin 事件队列概况"),
    ("GET", "/v1/admin/event-queue/dead?limit=10", None, None, "Admin 死信队列列表"),
    ("GET", "/v1/admin/audit/logs?limit=10", None, None, "Admin 操作审计日志"),
    ("GET", "/v1/admin/telemetry/stats", None, None, "Admin 遥测统计聚合"),
    ("GET", "/v1/admin/system/logs?limit=10", None, None, "Admin 系统诊断日志"),
    ("GET", "/v1/admin/config", None, None, "Admin 配置读取 (脱敏)"),
    ("GET", "/v1/admin/ai/domain-knowledge", None, None, "Admin 领域知识列表获取"),
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

print("\n=== 深度验证: 领域知识 (Domain Knowledge) API ===")
# 1. POST 带 Content-Type
payload_json = json.dumps({"content": "测试条目：PojavLauncher崩溃切换gl4es渲染器", "enabled": True})
status, res_body = request("POST", "/v1/admin/ai/domain-knowledge", {"Content-Type": "application/json"}, payload_json)
print(f"POST /v1/admin/ai/domain-knowledge (with Content-Type) -> {status} | body: {res_body}")

created_id = None
try:
    j = json.loads(res_body)
    if j.get("success") and "data" in j:
        created_id = j["data"].get("id")
except:
    pass

# 2. POST 不带 Content-Type（前端默认 fetch 行为）
status, res_body = request("POST", "/v1/admin/ai/domain-knowledge", {}, payload_json)
print(f"POST /v1/admin/ai/domain-knowledge (NO Content-Type) -> {status} | body: {res_body}")

# 3. GET 再次获取列表
status, res_body = request("GET", "/v1/admin/ai/domain-knowledge")
print(f"GET /v1/admin/ai/domain-knowledge -> {status} | body: {res_body}")

if created_id:
    # 4. PUT 更新
    put_json = json.dumps({"content": "更新条目：PojavLauncher切换gl4es", "enabled": False})
    status, res_body = request("PUT", f"/v1/admin/ai/domain-knowledge/{created_id}", {"Content-Type": "application/json"}, put_json)
    print(f"PUT /v1/admin/ai/domain-knowledge/{created_id} -> {status} | body: {res_body}")

    # 5. DELETE 删除
    status, res_body = request("DELETE", f"/v1/admin/ai/domain-knowledge/{created_id}")
    print(f"DELETE /v1/admin/ai/domain-knowledge/{created_id} -> {status} | body: {res_body}")

print("\n=== 深度验证: 修改 LogAgent 工具调用轮次配置 ===")
# 读取当前配置
status, res_body = request("GET", "/v1/admin/config")
raw_json = json.loads(res_body)
current_cfg = raw_json.get("data") if isinstance(raw_json.get("data"), dict) else raw_json
current_rounds = current_cfg.get("ai", {}).get("agent", {}).get("maxToolRounds")
print(f"当前配置中的 maxToolRounds: {current_rounds}")

# 更新配置为 25 轮
update_payload = json.dumps({
    "ai": {
        "agent": {
            "enabled": True,
            "maxToolRounds": 25,
            "maxFileLines": 60000,
            "maxFileBytes": 10485760
        }
    }
})
status, res_body = request("PUT", "/v1/admin/config", {"Content-Type": "application/json"}, update_payload)
print(f"PUT /v1/admin/config (with Content-Type) -> {status} | body: {res_body[:150]}")

status_no_ct, res_body_no_ct = request("PUT", "/v1/admin/config", {}, update_payload)
print(f"PUT /v1/admin/config (NO Content-Type) -> {status_no_ct} | body: {res_body_no_ct[:150]}")

# 重新读取验证
status, res_body = request("GET", "/v1/admin/config")
raw_json = json.loads(res_body)
updated_cfg = raw_json.get("data") if isinstance(raw_json.get("data"), dict) else raw_json
updated_rounds = updated_cfg.get("ai", {}).get("agent", {}).get("maxToolRounds")
print(f"修改后配置中的 maxToolRounds: {updated_rounds}")

print("\n=== 深度验证: 前端 CORS OPTIONS 预检 ===")
status, res_body = request("OPTIONS", "/v1/admin/ai/domain-knowledge")
print(f"OPTIONS /v1/admin/ai/domain-knowledge -> {status}")
status, res_body = request("OPTIONS", "/v1/admin/config")
print(f"OPTIONS /v1/admin/config -> {status}")
