<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

use Mcp\Capability\Attribute\McpTool;

final class NPlusOneTool
{
    use ResolvesProfile;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $token     Profiler-token; leeg = recentste (evt. via urlFilter)
     * @param string|null $urlFilter Substring-filter op de URL om het recentste passende request te pakken
     * @param int         $threshold Minimaal aantal herhalingen om te rapporteren
     */
    #[McpTool(
        name: 'detect_n_plus_one',
        title: 'Detect N+1',
        description: 'Detecteert N+1-patronen binnen één request: identieke query-shapes die herhaald worden uitgevoerd, met de veroorzakende regel code en de vermoedelijke parent-query (1+N). Geef een token, of een urlFilter om het recentste passende request te pakken. Let op: sample_sql bevat de werkelijke parameterwaarden uit de request (handig als input voor explain_query, maar potentieel gevoelig).',
    )]
    public function detectNPlusOne(?string $token = null, ?string $urlFilter = null, int $threshold = 5): string
    {
        $threshold = max(2, $threshold);
        $profile = $this->resolveProfile($token, $urlFilter);
        if (\is_string($profile)) {
            return $profile;
        }
        $queries = $profile['queries'];

        $groups = QueryShapes::groupByShapeAndOrigin($queries);

        $out = [];
        foreach (QueryShapes::nPlusOneSuspects($groups, $queries, $threshold) as $s) {
            $out[] = [
                'executions' => $s['executions'],
                'total_ms' => $s['total_ms'],
                'sample_sql' => $s['sample_sql'],
                ...(($s['sample_sql_truncated'] ?? false) ? ['sample_sql_truncated' => true] : []),
                ...Sql::originFields($s['originFrame'], $s['chain']),
                'likely_parent' => $s['likely_parent'],
                'hint' => 'Overweeg een JOIN/fetch-join, batch-loading of fetch: EAGER voor deze relatie.',
            ];
        }

        return $this->json(['request' => ProfileReader::summarize($profile), 'n_plus_one_suspects' => $out]);
    }
}
