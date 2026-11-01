#!/usr/bin/env bash
set -euo pipefail

cd /home/YanSui/LogShare

echo "=== 1. 检查远端 git 状态 ==="
git status -s

echo "=== 2. 拉取最新代码与子模块 ==="
# 备份生产特定配置文件
cp docker/nginx/default.conf /tmp/prod_nginx_default.conf || true

git pull --recurse-submodules

# 确保生产环境专属配置不被变动
if [ -f /tmp/prod_nginx_default.conf ]; then
    cp /tmp/prod_nginx_default.conf docker/nginx/default.conf
fi

echo "=== 3. 清理宿主机陈旧注解缓存 ==="
docker run --rm -v /home/YanSui/LogShare/runtime:/runtime alpine rm -rf /runtime/container || true

echo "=== 4. 构建并重启 Hyperf 容器 ==="
docker compose --env-file .env -f docker/compose.yaml build --no-cache hyperf
docker compose --env-file .env -f docker/compose.yaml up -d --remove-orphans --force-recreate hyperf

echo "=== 5. 等待 Hyperf 就绪并检查运行状态 ==="
sleep 8
docker compose --env-file .env -f docker/compose.yaml ps
docker logs --tail 25 logshare-hyperf

echo "=== 部署完成 ==="
