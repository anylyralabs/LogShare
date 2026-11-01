# Hyperf 常驻进程镜像：Swoole 6.2 + SpinYarn 扩展 + pdo_mysql/redis
# 阶段 1：编译 SpinYarn C ABI 库
# rust ≥1.88：SpinYarn 依赖链（url→idna→icu 2.x）的 MSRV 要求
# 源码走 CNB 主仓（MornZe-Dev/SpinYarn），Redis JSON 共享缓存 + 按需回退 +
# PHP 侧 5 参反射适配，建不起缓存回退按需模式
FROM rust:1.90.0 AS spinyarn-capi
# SpinYarn 源码 pin 到 commit（2026-10-12 的 main，经 CNB 流水线 CI），防分支漂移
ARG SPINYARN_REF=87e56039a9961b7b06b4d21f98325d70afa45d7e
RUN git clone https://cnb.cool/MornZe-Dev/SpinYarn /spinyarn \
    && cd /spinyarn \
    && git fetch --depth 1 origin main \
    && git checkout ${SPINYARN_REF}
WORKDIR /spinyarn
# 仅编译 C ABI 库（PHP 扩展的链接对象），不编 Web binary（Axum 服务本镜像用不到）
RUN cargo build --release -p spinyarn-capi

# 阶段 2：最终镜像（php:8.5-cli，Hyperf 常驻进程）
FROM php:8.5.0-cli-bookworm

# Composer（安装项目依赖用）
COPY --from=composer:2.8.9 /usr/bin/composer /usr/bin/composer

# 扩展安装器：pdo_mysql / redis / zip（zip 供 composer 解压 dist 包）
# pcntl / posix / sockets 为 Hyperf 注解扫描与 Swoole 所需
# 直接拉取官方 release 脚本——镜像 tag 内部路径曾变动导致 COPY not found，此方式不受上游打包影响。
# 供应链注意：ADD 已固定 2.7.0 版本 tag，但未 pin 内容校验和；升级版本号时建议
# 先下载脚本核对其 sha256 后改用 `ADD --checksum=sha256:...`（需 BuildKit）。
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.0/install-php-extensions /usr/local/bin/install-php-extensions
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql redis zip pcntl posix sockets mbstring brotli

# 编译 Swoole（固定版本，保证构建可复现且满足 Hyperf 3.2 + PHP 8.5 所需的 6.2+）
# libjemalloc2：替换 glibc malloc 作为分配器。glibc 的 free 只在堆顶可连续收缩
# 时才 munmap 归还（brk 语义），常驻进程（尤其事件消费者长连接大分配）
# 峰值后 RSS 永不回落；jemalloc 按 dirty/muzzy decay 主动 purge 归还页面。
ARG SWOOLE_VERSION=6.2.0
RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential libcurl4-openssl-dev libssl-dev zlib1g-dev libc-ares-dev libbrotli-dev libjemalloc2 \
    && pecl install swoole-${SWOOLE_VERSION} \
    && docker-php-ext-enable swoole \
    && ln -sf /usr/lib/*-linux-gnu/libjemalloc.so.2 /usr/local/lib/libjemalloc.so.2 \
    && rm -rf /var/lib/apt/lists/*

# jemalloc 生效：全容器进程（php/hyperf/composer）统一走 jemalloc；
# decay 1s 加速空闲页归还（默认 10s，对 3.6G 小机器再激进一点，收益是 RSS 紧跟真实用量）
ENV LD_PRELOAD=/usr/local/lib/libjemalloc.so.2 \
    MALLOC_CONF=dirty_decay_ms:1000,muzzy_decay_ms:1000,background_thread:true

# 编译 SpinYarn PHP 扩展（C ABI 来自阶段 1），编译完成后删除源码与构建产物，
# 仅保留 /usr/local/lib 下的共享库，减小镜像体积与攻击面。
# 只取构建所需的子路径（扩展源码 + C 头文件 + 已编译的 C ABI 库），不整目录 COPY，
# 避免把 Rust target 缓存与 Web binary 产物带入本阶段
COPY --from=spinyarn-capi /spinyarn/crates/php /spinyarn/crates/php
COPY --from=spinyarn-capi /spinyarn/crates/capi/include /spinyarn/crates/capi/include
COPY --from=spinyarn-capi /spinyarn/target/release/libspinyarn_capi.so /spinyarn/target/release/libspinyarn_capi.so
RUN cd /spinyarn/crates/php \
    && phpize \
    && ./configure --enable-spinyarn --with-spinyarn-libdir=/spinyarn/target/release \
    && make \
    && make install \
    && docker-php-ext-enable spinyarn \
    && cp /spinyarn/target/release/libspinyarn_capi.so /usr/local/lib/libspinyarn_capi.so \
    && rm -rf /spinyarn \
    && apt-get purge -y autoconf build-essential libcurl4-openssl-dev libssl-dev zlib1g-dev libc-ares-dev libbrotli-dev \
    && rm -rf /var/lib/apt/lists/*
# 说明：扩展装完后 purge 编译期依赖以收敛体积与攻击面；libjemalloc2 为运行时保留；
# 不用 --auto-remove，避免连带删掉 Swoole 运行时仍需的共享库（libcurl/libssl 等）；
# 不用 BuildKit cache mount，保证普通 docker build 兼容性。

# C ABI 共享库 + 动态链接器路径
RUN echo '/usr/local/lib' > /etc/ld.so.conf.d/spinyarn.conf && ldconfig

# 项目依赖 + 代码（代码打进镜像，避免依赖运行时挂载 vendor）
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction
COPY . .

# 映射目录：/app/mappings（compose 以 bind mount 挂载宿主机 ./mappings，映射表由
# 下载脚本 scripts/download_mappings.sh + download_vanilla_mappings.py 预先生成）

# 启动：先清理挂载的陈旧注解容器缓存，再启动服务。
# `exec` 让 Hyperf 成为 PID 1，保证信号能正确传递。
CMD ["sh", "-c", "rm -rf /app/runtime/container; exec php bin/hyperf.php start"]

# 版本断言：spinyarn 扩展版本须与阶段 1 的 SPINYARN_REF 对应（`php --ri spinyarn` 核验由 CI 保障，本任务不改 CI）。
