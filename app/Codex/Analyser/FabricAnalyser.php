<?php

declare(strict_types=1);

namespace App\Codex\Analyser;

use App\Codex\Analysis\Problem\Fabric\FabricIncompatibleModsProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModDependencyProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModIncompatibleMinecraftVersionProblem;
use Aternos\Codex\Minecraft\Analyser\FabricAnalyser as BaseFabricAnalyser;
use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricIncompatibleModsProblem as BaseFabricIncompatibleModsProblem;
use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricModDependencyProblem as BaseFabricModDependencyProblem;
use Aternos\Codex\Minecraft\Analysis\Problem\Fabric\FabricModIncompatibleMinecraftVersionProblem as BaseFabricModIncompatibleMinecraftVersionProblem;

/**
 * Fabric 日志分析器：用中文输入适配版本替换三个依赖相关 insight。
 */
final class FabricAnalyser extends BaseFabricAnalyser
{
    public function __construct()
    {
        parent::__construct();
        $this->overridePossibleInsightClass(BaseFabricModDependencyProblem::class, FabricModDependencyProblem::class);
        $this->overridePossibleInsightClass(BaseFabricModIncompatibleMinecraftVersionProblem::class, FabricModIncompatibleMinecraftVersionProblem::class);
        $this->overridePossibleInsightClass(BaseFabricIncompatibleModsProblem::class, FabricIncompatibleModsProblem::class);
    }
}
