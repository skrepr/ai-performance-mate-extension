# AGENTS.md

AI Mate-extensie (Composer-package) die Symfony-profiler-runtimedata als MCP-tools ontsluit voor AI-agents. Dev-only; PHP ≥ 8.2, Symfony 6.4/7.x/8.x, DBAL 3.6+/4.x.

## Commando's

```bash
composer install
composer test        # PHPUnit
composer phpstan     # PHPStan level 8 + strict rules — findings zijn blocking
```

End-to-end rooktest (dit repo is zelf ook een Mate-rootproject):

```bash
vendor/bin/mate mcp:tools:list                     # alle 5 tools zichtbaar?
vendor/bin/mate mcp:tools:call slow_queries '{}'   # hele DI-keten
```

## Architectuur

- `src/*Tool.php` — de 5 MCP-tools (entry-laag): dunne mappers van kern-output naar JSON
- `src/ProfileReader.php` — leest profielen via `FileProfilerStorage`; OOM-guards + in-process cache (kern)
- `src/QueryShapes.php` — pure aggregatie: shape-groepering, N+1-heuristiek, voor/na-diff (kern)
- `src/Sql.php` — pure SQL-/backtrace-helpers (kern)
- `config/services.php` — DI-config, geladen door Mate; tool-services MOETEN `public` zijn (de MCP-SDK resolvet via `$container->has(FQCN)`)
- Registratie bij consumers via `extra.ai-mate` in `composer.json`; zie `vendor/bin/mate discover`

## Conventies

- Commentaar, tool-descriptions en foutmeldingen in het Nederlands; `INSTRUCTIONS.md` (agent-facing) in het Engels
- Strikte types; geen `empty()`; expliciete checks (`null ===`, `'' ===`)
- Pure logica hoort in statische helper-classes (`Sql`, `QueryShapes`) — testbaar zonder IO; tools blijven dun
- Tool-output volgt de Mate-designprincipes: distilleren i.p.v. dumpen, harde limieten mét truncatie-signaal (`sql_shape_truncated`), gevoelige data eruit
- `ProfileReader::profileFilePath()` spiegelt Symfony's padschema; de schema-pin-test in `ProfileReaderTest` faalt als Symfony dat wijzigt
