<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Mcp\Capability\Attribute\McpTool;

final class NPlusOneTool
{
    use JsonResponse;

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
        if (null === $token || '' === $token) {
            $token = $this->reader->latestToken($urlFilter ?? '');
        }
        if (null === $token) {
            return $this->json(['error' => 'Geen requests gevonden — doe eerst een request naar de app.']);
        }
        try {
            $profile = $this->reader->read($token);
        } catch (ProfileTooLargeException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }
        if (null === $profile) {
            return $this->json(['error' => "Geen profiel gevonden voor token {$token}."]);
        }
        $queries = $profile['queries'];

        $groups = QueryShapes::groupByShapeAndOrigin($queries);

        $out = [];
        foreach (QueryShapes::nPlusOneSuspects($groups, $queries, $threshold) as $s) {
            $out[] = [
                'executions' => $s['executions'],
                'total_ms' => $s['total_ms'],
                'sample_sql' => $s['sample_sql'],
                'origin' => null !== $s['originFrame']
                    ? Sql::formatFrame($s['originFrame'])
                    : 'onbekend — zet profiling_collect_backtrace aan voor bestand:regel',
                'origin_chain' => \count($s['chain']) > 1 ? $s['chain'] : null,
                'origin_context' => Sql::sourceContext($s['originFrame']),
                'likely_parent' => $s['likely_parent'],
                'hint' => 'Overweeg een JOIN/fetch-join, batch-loading of fetch: EAGER voor deze relatie.',
            ];
        }

        return $this->json(['request' => ProfileReader::summarize($profile), 'n_plus_one_suspects' => $out]);
    }
}
