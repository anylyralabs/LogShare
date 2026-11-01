<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class FiltersController extends AbstractController
{
    /**
     * 进程级缓存：过滤器清单由配置推导且配置加载后不变，
     * 与全局配置语义一致（变更需重启生效）。
     */
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $cachedFilters = null;

    /**
     * Report the active filter chain derived from the `filter.pre` config,
     * so the endpoint can never drift from the actually applied filters.
     */
    #[GetMapping(path: 'filters')]
    public function filters(): ResponseInterface
    {
        if (self::$cachedFilters === null) {
            self::$cachedFilters = self::buildFilterList();
        }

        return $this->respondJson([
            'success' => true,
            'filters' => self::$cachedFilters,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function buildFilterList(): array
    {
        $preFilters = \App\Config::Get('filter')['pre'] ?? [];

        $filters = [];
        $redactionPatterns = [];

        foreach ($preFilters as $filterClass) {
            if (!is_string($filterClass) || !class_exists($filterClass)) {
                continue;
            }

            $shortName = substr($filterClass, (int) strrpos($filterClass, '\\') + 1);
            $name = str_ends_with($shortName, 'Filter') ? substr($shortName, 0, -6) : $shortName;

            switch ($shortName) {
                case 'TrimFilter':
                    $filters[] = ['type' => 'trim', 'data' => null];
                    break;

                case 'LimitBytesFilter':
                    $filters[] = [
                        'type' => 'limit-bytes',
                        'data' => ['limit' => (int) (\App\Config::Get('storage')['maxLength'] ?? (10 * 1024 * 1024))],
                    ];
                    break;

                case 'LimitLinesFilter':
                    $filters[] = [
                        'type' => 'limit-lines',
                        'data' => ['limit' => (int) (\App\Config::Get('storage')['maxLines'] ?? 50_000)],
                    ];
                    break;

                default:
                    // Redaction filter: expose its first replacement per pattern name
                    $replacement = self::firstReplacement($filterClass);
                    if ($replacement !== null) {
                        $redactionPatterns[] = ['pattern' => $name, 'replacement' => $replacement];
                    }
            }
        }

        if (!empty($redactionPatterns)) {
            $filters[] = ['type' => 'regex', 'data' => ['patterns' => $redactionPatterns]];
        }

        return $filters;
    }

    /**
     * Read the first replacement of a PatternWithReplacement-based filter via
     * reflection (getPatterns() is protected static by design; since PHP 8.1
     * reflection can invoke it without setAccessible).
     */
    private static function firstReplacement(string $filterClass): ?string
    {
        try {
            $method = new \ReflectionMethod($filterClass, 'getPatterns');
            $patterns = $method->invoke(null);
        } catch (\ReflectionException) {
            return null;
        }

        foreach ($patterns as $pattern) {
            if ($pattern instanceof \App\Filter\Pattern\PatternWithReplacement) {
                return $pattern->getReplacement();
            }
        }
        return null;
    }
}
