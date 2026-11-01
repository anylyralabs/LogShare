<?php

namespace App;

use Aternos\Codex\Minecraft\Log\Minecraft\MinecraftLog;
use Aternos\Codex\Minecraft\Translator\Translator;

class Detective extends \Aternos\Codex\Detective\Detective
{
    protected string $defaultLogClass = MinecraftLog::class;

    public function __construct()
    {
        $this->configureTranslator();
        $this->addDetective(new \Aternos\Codex\Minecraft\Detective\Detective())
            ->addDetective(new \Aternos\Codex\Hytale\Detective\Detective());
    }

    /**
     * 设置 codex-minecraft 的翻译语言。
     *
     * Translator 是进程级单例，必须在首次 detect/parse/analyse 之前设置；
     * 否则问题、解决方案与日志类型会使用默认英文。
     */
    private function configureTranslator(): void
    {
        $language = (string) (\App\Config::GetPath('codex.language', 'zh-CN') ?? 'zh-CN');
        if ($language === '') {
            $language = 'zh-CN';
        }

        try {
            Translator::getInstance()->setLanguage($language);
        } catch (\Throwable $e) {
            // 配置写错语言代码时退回英文，不能让分析接口直接 500。
            \App\Syslog::warning('Codex', '语言包加载失败，回退英文: ' . $e->getMessage());
            Translator::getInstance()->setLanguage('en');
        }
    }
}