<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class RequestBreakdownTool
{
    use ResolvesProfile;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $token     Profiler-token; leeg = recentste (evt. via urlFilter)
     * @param string|null $urlFilter Substring-filter op de URL om het recentste passende request te pakken
     */
    #[McpTool(
        name: 'request_breakdown',
        title: 'Request Breakdown',
        description: 'Splitst de wall-clock van één request op per categorie (Doctrine/DB, externe http_client-calls, template-rendering, event listeners, …) — zodat je ziet waar de tijd heen ging, niet alleen in de DB. Let op: categorieën kunnen nesten (bv. controller omvat doctrine/template), dus ze tellen niet per se op tot 100%.',
    )]
    public function requestBreakdown(?string $token = null, ?string $urlFilter = null): string
    {
        $p = $this->resolveProfile($token, $urlFilter);
        if (\is_string($p)) {
            return $p;
        }

        $total = $p['duration_ms'] ?? 0.0;
        $pct = static fn (float $ms): ?float => $total > 0 ? round(($ms / $total) * 100, 1) : null;

        $categories = [];
        foreach ($p['timeline_ms'] as $category => $ms) {
            $categories[] = ['category' => $category, 'ms' => $ms, 'pct_of_total' => $pct($ms)];
        }

        return $this->json([
            'request' => ProfileReader::summarize($p),
            'total_ms' => $total,
            'database' => [
                'query_count' => $p['query_count'],
                'query_time_ms' => $p['query_time_ms'],
                'pct_of_total' => $pct($p['query_time_ms']),
            ],
            'categories' => $categories,
            'note' => [] !== $categories
                ? "Categorieën kunnen nesten (bv. 'controller' omvat 'doctrine'/'template'); ze tellen niet per se op tot total_ms."
                : "Geen timeline beschikbaar — draait de 'time'-collector (standaard aan in dev met de profiler)?",
        ]);
    }
}
