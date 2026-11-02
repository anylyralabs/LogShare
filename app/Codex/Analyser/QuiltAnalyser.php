<?php

declare(strict_types=1);

namespace App\Codex\Analyser;

use App\Codex\Analysis\Problem\Fabric\FabricIncompatibleModsProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModDependencyProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModIncompatibleMinecraftVersionProblem;
use Aternos\Codex\Minecraft\Analyser\QuiltAnalyser as BaseQuiltAnalyser;

/**
 * Quilt 复用 Fabric Loader 的 resolution 文案，补上同样的依赖问题。
 */
final class QuiltAnalyser extends BaseQuiltAnalyser
{
    public function __construct()
    {
        parent::__construct();
        $this->addPossibleInsightClass(FabricModDependencyProblem::class);
        $this->addPossibleInsightClass(FabricModIncompatibleMinecraftVersionProblem::class);
        $this->addPossibleInsightClass(FabricIncompatibleModsProblem::class);
    }
}
