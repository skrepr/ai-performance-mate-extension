# Changelog

## 0.1.0 — 2026-07-14

Eerste release.

- Vijf MCP-tools voor AI Mate: `slow_queries`, `detect_n_plus_one`, `request_breakdown`, `profile_diff` en `explain_query`
- OOM-bescherming: te grote profielen worden overgeslagen (`skrepr_mate.max_profile_bytes`), met fail-closed detectie als Symfony's profiler-padschema ooit wijzigt
- In-process cache van gedistilleerde profielen (de MCP-server is langlevend)
- `explain_query` weigert multi-statements en draait `EXPLAIN ANALYZE` alleen voor `SELECT`/`WITH`
- Tool-output volgt de Mate-designprincipes: genormaliseerde query-shapes, harde limieten met truncatie-signaal
- Configuratie via `skrepr_mate.profiler_dir`, `skrepr_mate.database_url` en `skrepr_mate.max_profile_bytes`
