import os
import ssl
import json
import urllib.request
import urllib.error

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

BASE_URL = "https://127.0.0.1"
HOST = os.environ.get("API_HOST", "api.logshare.cn")

def request(method, path, headers=None, body=None):
    if headers is None:
        headers = {}
    headers["Host"] = HOST
    
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
    ("GET", "/", None, None, "API 根路径索引"),
    ("GET", "/security/stats", None, None, "OpenLiteWaf 统计概览"),
    ("GET", "/stats/data", None, None, "OpenLiteStats 访问分析数据"),
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
