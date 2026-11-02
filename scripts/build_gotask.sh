#!/usr/bin/env bash
# 本地构建 GoTask MongoDB 边车。生产镜像由 docker/hyperf.Dockerfile 的多阶段
# 构建完成，不需要在宿主机安装 Go。
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}/gotask"

go mod download
go vet ./...
CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${ROOT_DIR}/bin/logshare-gotask" ./cmd/logshare-gotask
