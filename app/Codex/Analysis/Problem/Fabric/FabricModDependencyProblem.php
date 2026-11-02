<?php

declare(strict_types=1);

namespace App\Codex\Analysis\Problem\Fabric;

use Aternos\Codex\Analysis\InsightInterface;
use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricModDependencyProblem as BaseFabricModDependencyProblem;
use Aternos\Codex\Minecraft\Analysis\Solution\Forge\ModInstallSolution;
use Aternos\Codex\Minecraft\Translator\Translator;

/**
 * Fabric / Quilt Loader 依赖问题（中文输入适配）。
 */
class FabricModDependencyProblem extends BaseFabricModDependencyProblem
{
    use ParsesFabricMessages;

    private const MOD_TOKEN = "(?:'[^']+' \\([^)]+\\)(?: [^ ]+)?|[\\w.-]+)";

    private const DEP_TOKEN = "(?!(?:'Minecraft' \\(minecraft\\)|minecraft))(?:'[^']+' \\([^)]+\\)|[\\w.-]+)";

    private string $reason = 'missing';

    private ?string $versionRange = null;

    private ?string $installedVersion = null;

    public static function getPatterns(): array
    {
        return array_merge(parent::getPatterns(), [
            'zh-missing' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ') 需要 (' . self::DEP_TOKEN . ') 的 ([^\n]+?)，但没有安装它！/u',
            'zh-mismatch' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ') 需要 (' . self::DEP_TOKEN . ') 的 ([^\n]+?)，但已经安装了的版本 ([^\n]+?) 不对！/u',
            'zh-env-disabled' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ') 需要 (' . self::DEP_TOKEN . ') 的 ([^\n]+?)，但它在此环境中被停用（仅适用于用户端／服务器）！/u',
            'zh-invalid' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ') 需要 (' . self::DEP_TOKEN . ') 的 ([^\n]+?)，但由于其他限制条件无法载入！/u',
            'en-env-disabled' => '/\s*-\s*Mod (' . self::MOD_TOKEN . ')(?: [^ ]+)? requires ([^\n]+?) of (?:mod )?(' . self::DEP_TOKEN . '), which is disabled for this environment \(client\/server only\)!/',
            'en-invalid' => '/\s*-\s*Mod (' . self::MOD_TOKEN . ')(?: [^ ]+)? requires ([^\n]+?) of (?:mod )?(' . self::DEP_TOKEN . "), which can't be loaded due to other constraints!/",
            'en-mismatch' => '/\s*-\s*Mod (' . self::MOD_TOKEN . ')(?: [^ ]+)? requires ([^\n]+?) of (?:mod )?(' . self::DEP_TOKEN . '), but only the wrong version[^:]+: ([^\n]+?)!/',
        ]);
    }

    public function getMessage(): string
    {
        if ($this->reason === 'missing') {
            return parent::getMessage();
        }

        return Translator::getInstance()->getTranslation(
            'fabric-mod-dependency-' . $this->reason . '-problem',
            [
                'mod-name' => $this->getModName(),
                'dependency-mod-name' => "'" . $this->getDependency() . "'",
                'version-range' => $this->displayRange(),
                'installed-version' => $this->installedVersion ?? '',
            ]
        );
    }

    /**
     * @param array<int, string> $matches
     */
    public function setMatches(array $matches, mixed $patternKey): void
    {
        if ($patternKey === 'between') {
            $this->setEnglishBetween($matches);
            return;
        }

        if (in_array($patternKey, ['zh-missing', 'zh-mismatch', 'zh-env-disabled', 'zh-invalid'], true)) {
            $mod = self::parseModToken($matches[1]);
            $dependency = self::parseModName($matches[2]);
            $this->setModName($mod['name']);
            $this->setDependency($dependency);
            $this->versionRange = self::parseChineseRange($matches[3]);
            $this->reason = match ($patternKey) {
                'zh-mismatch' => 'mismatch',
                'zh-env-disabled' => 'env-disabled',
                'zh-invalid' => 'invalid',
                default => 'missing',
            };
            if ($patternKey === 'zh-mismatch') {
                $this->installedVersion = trim($matches[4]);
            }

            $this->addSolution(new ModInstallSolution($dependency, $this->versionRange));
            return;
        }

        if (in_array($patternKey, ['en-env-disabled', 'en-invalid', 'en-mismatch'], true)) {
            $mod = self::parseModToken($matches[1]);
            $dependency = self::parseModName($matches[3]);
            $this->setModName($mod['name']);
            $this->setDependency($dependency);
            $this->versionRange = trim($matches[2]);
            $this->reason = match ($patternKey) {
                'en-mismatch' => 'mismatch',
                'en-env-disabled' => 'env-disabled',
                default => 'invalid',
            };
            if ($patternKey === 'en-mismatch') {
                $this->installedVersion = trim($matches[4]);
            }

            $this->addSolution(new ModInstallSolution($dependency, $this->versionRange));
            return;
        }

        parent::setMatches($matches, $patternKey);
    }

    private function displayRange(): string
    {
        if ($this->versionRange !== null) {
            return $this->versionRange;
        }

        return str_starts_with(Translator::getInstance()->getLanguage(), 'zh')
            ? '任意版本'
            : 'any version';
    }

    public function isEqual(InsightInterface $insight): bool
    {
        return parent::isEqual($insight)
            && $insight instanceof static
            && $this->reason === $insight->reason
            && $this->versionRange === $insight->versionRange;
    }

    /**
     * 上游 between 分支把依赖名固定从 group 7 取，未加引号的依赖会丢失；
     * 这里用 group 7/8 兜底，并同时兼容未加引号的父模组。
     *
     * @param array<int, string> $matches
     */
    private function setEnglishBetween(array $matches): void
    {
        $modName = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');
        $dependency = ($matches[7] ?? '') !== '' ? $matches[7] : ($matches[8] ?? '');

        $this->setModName($modName);
        $this->setDependency($dependency);

        $firstSymbol = $matches[4] === 'exclusive' ? '>' : '>=';
        $secondSymbol = $matches[6] === 'exclusive' ? '<' : '<=';
        $this->versionRange = $firstSymbol . $matches[3] . ', ' . $secondSymbol . $matches[5];
        $this->addSolution(new ModInstallSolution($dependency, $this->versionRange));
    }
}
