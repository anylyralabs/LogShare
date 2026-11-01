<?php

declare(strict_types=1);

/**
 * 将 PHPUnit/Pest 生成的 Clover XML 覆盖率报告转换为 LCOV info 格式。
 *
 * ── 为什么需要这个脚本 ──────────────────────────────────────────────────────
 * CNB 的 `testing:coverage` 内置任务只解析这几种报告：coverage.json（JS 或
 * json-summary）、lcov.info / *.lcov、jacoco*.xml、golang。而 Pest 能产出的
 * 覆盖率格式是 clover / cobertura / crap / html / php / text / xml，**没有 lcov**，
 * 也没有 CNB 认的 JSON 结构。Clover XML 已包含逐行命中次数，转成 LCOV 是无损的，
 * 因此加这一层薄转换，而不是把覆盖率上报整个砍掉。
 *
 * ── 用法 ────────────────────────────────────────────────────────────────────
 *   php scripts/clover_to_lcov.php coverage/clover.xml coverage/lcov.info
 *
 * 输入缺失或解析失败时以非零码退出，由调用方决定是否失败（CI 中该步骤前置了
 * test -s 断言，因此这里退不出就说明确实是测试本身没跑起来）。
 */

$argv = $_SERVER['argv'] ?? [];
$input = $argv[1] ?? 'coverage/clover.xml';
$output = $argv[2] ?? 'coverage/lcov.info';

if (!is_file($input)) {
    fwrite(STDERR, "Clover 报告不存在: {$input}\n");
    exit(1);
}

$xml = @simplexml_load_file($input);
if ($xml === false) {
    fwrite(STDERR, "Clover 报告解析失败: {$input}\n");
    exit(1);
}

$lines = [];
$foundLines = 0;

// Clover 结构：coverage/project/file[@name]/line[@num,@count]
// 目录嵌套（package）在 PHPUnit 的输出里不出现，直接遍历所有 <file>。
foreach ($xml->xpath('//file') ?: [] as $file) {
    $path = (string) ($file['name'] ?? '');
    if ($path === '') {
        continue;
    }

    // LCOV 约定使用仓库相对路径；Clover 里可能是绝对路径，剥掉工作区前缀
    $cwd = getcwd();
    if ($cwd !== false && str_starts_with($path, $cwd . '/')) {
        $path = substr($path, strlen($cwd) + 1);
    }

    $hit = 0;
    $total = 0;
    $lineData = [];

    foreach ($file->line as $line) {
        $num = (int) ($line['num'] ?? 0);
        $count = (int) ($line['count'] ?? 0);
        if ($num <= 0) {
            continue;
        }
        // LCOV 的记录顺序必须是行号升序，否则部分解析器会报错
        $lineData[$num] = $count;
        $total++;
        if ($count > 0) {
            $hit++;
        }
    }

    if ($lineData === []) {
        continue;
    }

    ksort($lineData);

    $lines[] = 'TN:';
    $lines[] = 'SF:' . $path;
    foreach ($lineData as $num => $count) {
        $lines[] = 'DA:' . $num . ',' . $count;
    }
    // LF = 可执行行总数，LH = 命中的行数
    $lines[] = 'LF:' . $total;
    $lines[] = 'LH:' . $hit;
    $lines[] = 'end_of_record';

    $foundLines += $total;
}

if ($lines === []) {
    fwrite(STDERR, "Clover 报告中没有任何可转换的行覆盖数据\n");
    exit(1);
}

$dir = dirname($output);
if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
    fwrite(STDERR, "无法创建输出目录: {$dir}\n");
    exit(1);
}

if (file_put_contents($output, implode("\n", $lines) . "\n") === false) {
    fwrite(STDERR, "写入 LCOV 失败: {$output}\n");
    exit(1);
}

$fileCount = count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'SF:')));
printf("已转换 %d 个文件 / %d 行覆盖数据 -> %s\n", $fileCount, $foundLines, $output);
