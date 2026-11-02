<?php

declare(strict_types=1);

namespace App\Codex\Analyser;

use App\Codex\Analysis\Problem\Fabric\FabricIncompatibleModsProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModDependencyProblem;
use App\Codex\Analysis\Problem\Fabric\FabricModIncompatibleMinecraftVersionProblem;
use Aternos\Codex\Minecraft\Analyser\Report\CrashReport\QuiltCrashReportAnalyser as BaseQuiltCrashReportAnalyser;

/**
 * Quilt 崩溃报告分析器：补上 resolution 相关的中文/英文问题。
 */
final class QuiltCrashReportAnalyser extends BaseQuiltCrashReportAnalyser
{
    public function __construct()
    {
        parent::__construct();
        $this->addPossibleInsightClass(FabricModDependencyProblem::class);
        $this->addPossibleInsightClass(FabricModIncompatibleMinecraftVersionProblem::class);
        $this->addPossibleInsightClass(FabricIncompatibleModsProblem::class);
    }
}
