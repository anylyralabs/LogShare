<?php

declare(strict_types=1);

use Aternos\Codex\Minecraft\Translator\Translator;

test('all literal codex minecraft translation keys resolve in simplified chinese', function () {
    new \App\Detective();

    $keys = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CORE_PATH . '/vendor/aternos/codex-minecraft/src')
    );

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $content = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/getTranslation\(\s*["\']([^"\']+)["\']/', $content, $matches) === false) {
            continue;
        }
        foreach ($matches[1] as $key) {
            if ($key === 'type-') {
                continue;
            }
            $keys[$key] = true;
        }
    }

    $translator = Translator::getInstance();
    $translator->setLanguage('zh-CN');

    foreach (array_keys($keys) as $key) {
        expect($translator->getTranslation($key))->toBeString("missing translation: {$key}");
    }
});

test('datapack translation gap is patched by the application overlay', function () {
    new \App\Detective();

    $message = Translator::getInstance()->getTranslation('datapack-parsing-problem', ['datapack' => 'test.zip']);

    expect($message)->toContain('数据包')->toContain('test.zip');
});
