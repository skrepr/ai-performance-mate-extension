<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class SlowQueriesTool
{
    use JsonResponse;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param int         $requests  Aantal recente requests om te analyseren (1-50)
     * @param int         $top       Aantal traagste query-shapes om terug te geven
     * @param string|null $urlFilter Substring-filter op de URL, bv. '/checkout'
     */
    #[McpTool(
        name: 'slow_queries',
        title: 'Slow Queries',
        description: 'Traagste query-shapes over de laatste N requests, gegroepeerd op genormaliseerde SQL, met totale tijd, aantal uitvoeringen en de veroorzakende regel projectcode. Let op de aard van origin: bij SELECTs wijst die naar de echte trigger (actionable); bij INSERT/UPDATE/COMMIT wijst origin meestal naar de flush()-regel — kijk dan naar de persist-/business-logica, niet naar die regel zelf.',
    )]
    public function slowQueries(int $requests = 15, int $top = 10, ?string $urlFilter = null): string
    {
        $requests = max(1, min(50, $requests));
        $metas = $this->reader->findRecent($requests, $urlFilter ?? '');

        $groups = [];
        $analyzed = 0;
        $skipped = [];
        foreach ($metas as $meta) {
            try {
                $profile = $this->reader->read($meta['token']);
            } catch (ProfileTooLargeException $e) {
                $skipped[] = ['token' => $e->token, 'bytes' => $e->bytes];
                continue;
            }
            if (null === $profile) {
                continue;
            }
            ++$analyzed;
            $groups = QueryShapes::accumulate($groups, $profile['queries'], "{$profile['method']} {$profile['url']}");
        }

        $ranked = [];
        foreach (QueryShapes::rank($groups, $top) as $g) {
            $ranked[] = [
                ...QueryShapes::truncateShape($g['sql_shape']),
                'total_ms' => $g['total_ms'],
                'executions' => $g['executions'],
                'avg_ms' => $g['avg_ms'],
                'max_ms' => $g['max_ms'],
                ...Sql::originFields($g['originFrame'], $g['chain']),
                'seen_on' => $g['seen_on'],
            ];
        }

        return $this->json([
            'analyzed_requests' => $analyzed,
            'skipped_too_large' => $skipped,
            'slow_queries' => $ranked,
        ]);
    }
}
