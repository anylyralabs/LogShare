<?php

declare(strict_types=1);

namespace App\Codex\Analysis\Problem\Fabric;

use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricIncompatibleModsProblem as BaseFabricIncompatibleModsProblem;
use Aternos\Codex\Minecraft\Analysis\Solution\Forge\ModRemoveSolution;

/**
 * Fabric / Quilt Loader：两个模组之间的 breaks / conflicts。
 */
class FabricIncompatibleModsProblem extends BaseFabricIncompatibleModsProblem
{
    use ParsesFabricMessages;

    private const MOD_TOKEN = "(?:'[^']+' \\([^)]+\\)(?: [^ ]+)?|[\\w.-]+)";

    private const DEP_TOKEN = "(?:'[^']+' \\([^)]+\\)|[\\w.-]+)";

    public static function getPatterns(): array
    {
        return array_merge(parent::getPatterns(), [
            'zh-incompatible' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ') 与 (' . self::DEP_TOKEN . ') 的 ([^\n]+?)，但已经安装了的版本是 ([^\n]+?)！/u',
        ]);
    }

    /**
     * @param array<int, string> $matches
     */
    public function setMatches(array $matches, mixed $patternKey): void
    {
        if ($patternKey === 'zh-incompatible') {
            $this->setModName(self::parseModName($matches[1]));
            $this->setSecondModName(self::parseModName($matches[2]));
            $this->addSolution(new ModRemoveSolution($this->getModName()));
            $this->addSolution(new ModRemoveSolution($this->getSecondModName()));
            return;
        }

        // 上游英文 pattern 对未加引号的第二个 mod 也会取到空 group；
        // 这里按 1/2、3/4 兜底。
        if ($patternKey === 0) {
            $first = ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? '');
            $second = ($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? '');
            $this->setModName($first);
            $this->setSecondModName($second);
            $this->addSolution(new ModRemoveSolution($this->getModName()));
            $this->addSolution(new ModRemoveSolution($this->getSecondModName()));
            return;
        }

        parent::setMatches($matches, $patternKey);
    }
}
