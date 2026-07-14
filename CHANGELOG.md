# Changelog

## Unreleased

- Truncatie-signaal nu overal consequent: `profile_diff` en `detect_n_plus_one` melden voortaan `sql_shape_truncated`/`sample_sql_truncated` bij afgekapte SQL; de shape-limiet is geüniformeerd op 400 tekens (was 300 in `profile_diff` en `likely_parent`)
- Eén consistente fallback-melding voor `origin` wanneer backtraces ontbreken (verwijst naar `doctrine.dbal.profiling_collect_backtrace`)
- `profile_diff` meldt bij een onleesbaar profiel nu wélk token het betreft

## 0.1.0 — 2026-07-14

Eerste release.

- Vijf MCP-tools voor AI Mate: `slow_queries`, `detect_n_plus_one`, `request_breakdown`, `profile_diff` en `explain_query`
- OOM-bescherming: te grote profielen worden overgeslagen (`skrepr_mate.max_profile_bytes`), met fail-closed detectie als Symfony's profiler-padschema ooit wijzigt
- In-process cache van gedistilleerde profielen (de MCP-server is langlevend)
- `explain_query` weigert multi-statements en draait `EXPLAIN ANALYZE` alleen voor `SELECT`/`WITH`
- Tool-output volgt de Mate-designprincipes: genormaliseerde query-shapes, harde limieten met truncatie-signaal
- Configuratie via `skrepr_mate.profiler_dir`, `skrepr_mate.database_url` en `skrepr_mate.max_profile_bytes`
