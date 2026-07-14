# symfony-runtime-mcp

MCP-server die runtime-data uit de Symfony profiler beschikbaar maakt voor AI-agents (Claude Code). Waar code-intelligence-MCP's kijken naar wat er op disk staat, kijkt deze naar wat er *daadwerkelijk gebeurt* op je dev-omgeving: request-timing, Doctrine-queries, N+1-patronen en logs.

## Hoe het werkt

De TypeScript-server draait op je host en praat via stdio met Claude Code. Voor het uitlezen van profielen kopieert hij een klein PHP-script naar `var/runtime-mcp/` in je project (bind-mounted, dus zichtbaar in de container) en roept dat aan via `docker compose exec`. Zo doet Symfony's eigen `FileProfilerStorage` het parsen — versiebestendig en zonder het serialisatieformaat te hoeven nabouwen.

```
Claude Code ──stdio──> symfony-runtime-mcp (Node, host)
                           │  docker compose exec -T php ...
                           ▼
                       extract.php (container, gebruikt Symfony-klassen)
                           │  leest var/cache/dev/profiler
                           ▼
                       JSON terug naar de server
```

## Vereisten

- Node.js 20+ op de host
- Symfony 7.x-project in docker compose, dev-modus met profiler (standaard aan bij `symfony/profiler-pack`)
- Projectmap bind-mounted in de container

## Installatie

```bash
git clone <deze-repo> && cd symfony-runtime-mcp
npm install
npm run build
```

Registreer in Claude Code (vanuit je Symfony-projectmap):

```bash
claude mcp add symfony-runtime -- node /pad/naar/symfony-runtime-mcp/dist/index.js "$PWD"
```

Wijkt je setup af van de defaults, geef dat mee via env:

```bash
claude mcp add symfony-runtime \
  -e SYMFONY_MCP_SERVICE=app \
  -e SYMFONY_MCP_CONTAINER_DIR=/var/www/html \
  -- node /pad/naar/symfony-runtime-mcp/dist/index.js "$PWD"
```

| Variabele | Default | Betekenis |
|---|---|---|
| `SYMFONY_MCP_SERVICE` | `php` | compose-service waarin PHP draait |
| `SYMFONY_MCP_CONTAINER_DIR` | `/app` | pad van de app ín de container |

## Backtraces aanzetten (sterk aangeraden)

Zonder backtraces weet de server *dat* een query traag is, maar niet *waar* hij vandaan komt. Zet in `config/packages/dev/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        profiling_collect_backtrace: true
```

Daarmee wijzen `slow_queries` en `detect_n_plus_one` naar het exacte bestand en regelnummer in je projectcode.

## Tools

| Tool | Doet |
|---|---|
| `list_requests` | Recente requests met duur, status, query-count, geheugen |
| `get_request_profile` | Volledig profiel van één request incl. alle queries |
| `slow_queries` | Traagste query-shapes over meerdere requests, met herkomst |
| `detect_n_plus_one` | Herhaalde identieke queries vanuit één regel, met de vermoedelijke parent-query (1+N) |
| `request_breakdown` | Wall-clock van één request opgesplitst per categorie (DB / externe HTTP / rendering / …) |
| `profile_diff` | Voor/na-vergelijking van hetzelfde endpoint |
| `explain_query` | Draait `EXPLAIN` op een query en zegt *waarom* die traag is (full scan, ongebruikte index, filesort) |
| `search_logs` | Doorzoekt `var/log/dev.log` op substring en level |

`slow_queries` en `detect_n_plus_one` geven per bevinding de veroorzakende regel projectcode terug (`origin`), de call-keten naar `flush()` bij writes (`origin_chain`), én de broncode rond die regel (`origin_context`) — zodat de agent de fix ziet zonder een extra bestand te openen. `explain_query` maakt verbinding via `DATABASE_URL`; `analyze=true` draait `EXPLAIN ANALYZE` maar alleen voor `SELECT`/`WITH` (writes worden geweigerd, die zou ANALYZE echt uitvoeren).

## Voorbeeldworkflow

> **Jij:** "De checkout voelt traag, zoek uit waarom en fix het."
>
> Claude roept `list_requests` aan met filter `/checkout` → ziet 2,3s en 147 queries → `detect_n_plus_one` → vindt 89× dezelfde productquery vanuit `CartItemRepository::findProduct()` regel 42, mét de broncode erbij → `explain_query` op die query bevestigt een full table scan op `product` → past een fetch-join toe → vraagt jou de pagina te herladen (of doet zelf een `curl`) → `profile_diff` bevestigt: 147 → 12 queries, 2,3s → 180ms.

## Beveiliging & privacy

- Request-parameters en headers met sleutels als `password`, `token`, `secret`, `authorization`, `cookie` worden geredact vóór ze de container verlaten.
- Query-parameters worden getruncate; lange payloads komen niet integraal in agent-context.
- De server is uitsluitend bedoeld voor **lokale dev-omgevingen**. Richt hem nooit op productie.

## Bekende beperkingen (PoC)

- Geen persistente index: profielen worden per sessie in-memory gecachet. Een SQLite-index (zoals codebase-memory die heeft) is de logische volgende stap.
- `search_logs` leest maximaal de laatste 4 MB van `dev.log`.
- Alleen `FileProfilerStorage` (de default); geen custom profiler-storage.
- Fase 2 (Excimer-flamegraphs) en fase 3 (interactieve Xdebug/DBGp-brug) zijn nog niet gebouwd.
