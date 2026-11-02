<?php

declare(strict_types=1);

namespace App\Codex\Analysis\Problem\Fabric;

use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricModIncompatibleMinecraftVersionProblem as BaseFabricModIncompatibleMinecraftVersionProblem;
use Aternos\Codex\Minecraft\Analysis\Solution\Bukkit\ServerInstallDifferentVersionSolution;
use Aternos\Codex\Minecraft\Analysis\Solution\Forge\ModInstallDifferentVersionSolution;

/**
 * Fabric / Quilt Loader：Minecraft 版本不满足依赖。
 */
class FabricModIncompatibleMinecraftVersionProblem extends BaseFabricModIncompatibleMinecraftVersionProblem
{
    use ParsesFabricMessages;

    private const MOD_TOKEN = "(?:'[^']+' \\([^)]+\\)(?: [^ ]+)?|[\\w.-]+)";

    public static function getPatterns(): array
    {
        return array_merge(parent::getPatterns(), [
            'zh-minecraft-mismatch' => '/\s*-\s*模组 (' . self::MOD_TOKEN . ") 需要 'Minecraft' \\(minecraft\\) 的 ([^\\n]+?)，但已经安装了的版本 ([^\\n]+?) 不对！/u",
        ]);
    }

    /**
     * @param array<int, string> $matches
     */
    public function setMatches(array $matches, mixed $patternKey): void
    {
        if ($patternKey === 'zh-minecraft-mismatch') {
            $mod = self::parseModToken($matches[1]);
            $this->setModName($mod['name']);
            $this->setMinecraftVersion(trim($matches[3]));

            $range = self::parseChineseRange($matches[2]);
            if ($range !== null) {
                $this->addSolution(new ServerInstallDifferentVersionSolution($range));
            }
            $this->addSolution(new ModInstallDifferentVersionSolution($this->getModName()));
            return;
        }

        parent::setMatches($matches, $patternKey);
    }
}
