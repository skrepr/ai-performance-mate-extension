#!/usr/bin/env node
/**
 * Symfony Runtime MCP — geeft AI-agents toegang tot runtime-data
 * (Symfony profiler, Doctrine-queries, logs) van een lokaal dev-project
 * dat in docker compose draait.
 *
 * Gebruik: symfony-runtime-mcp /pad/naar/symfony-project
 * Env:
 *   SYMFONY_MCP_SERVICE        compose-servicenaam met PHP (default: "php")
 *   SYMFONY_MCP_CONTAINER_DIR  app-pad in de container (default: "/app")
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const execFileAsync = promisify(execFile);
const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ---------- Configuratie ----------

const projectDir = process.argv[2];
if (!projectDir || !fs.existsSync(projectDir)) {
  console.error("Gebruik: symfony-runtime-mcp /pad/naar/symfony-project");
  process.exit(1);
}
const service = process.env.SYMFONY_MCP_SERVICE ?? "php";
const containerDir = process.env.SYMFONY_MCP_CONTAINER_DIR ?? "/app";

// ---------- Extractor-bridge (docker compose exec) ----------

/**
 * Kopieert het PHP-extractorscript naar var/runtime-mcp/ in het project.
 * Omdat het project bind-mounted is, is het daarmee ook in de container
 * beschikbaar — waar vendor/autoload.php en de profiler-klassen leven.
 */
function ensureExtractor(): void {
  const targetDir = path.join(projectDir, "var", "runtime-mcp");
  fs.mkdirSync(targetDir, { recursive: true });
  const source = path.join(__dirname, "..", "php", "extract.php");
  fs.copyFileSync(source, path.join(targetDir, "extract.php"));
}

async function runExtractor(args: string[]): Promise<any> {
  const { stdout } = await execFileAsync(
    "docker",
    [
      "compose", "exec", "-T", service, "php",
      `${containerDir}/var/runtime-mcp/extract.php`,
      containerDir,
      ...args,
    ],
    { cwd: projectDir, maxBuffer: 64 * 1024 * 1024 },
  );
  const parsed = JSON.parse(stdout);
  if (parsed.error) throw new Error(parsed.error);
  return parsed;
}

// ---------- Profiel-cache & helpers ----------

type Query = {
  connection: string;
  sql: string;
  params: unknown;
  ms: number;
  backtrace?: { file: string | null; line: number | null; call: string }[];
};

type Profile = {
  token: string;
  url: string;
  method: string;
  status_code: number;
  route?: string;
  duration_ms?: number;
  memory_mb?: number;
  query_count?: number;
  query_time_ms?: number;
  queries?: Query[];
  timeline_ms?: Record<string, number>;
  [key: string]: unknown;
};

const profileCache = new Map<string, Profile>();

async function getProfile(token: string): Promise<Profile> {
  const cached = profileCache.get(token);
  if (cached) return cached;
  const profile = (await runExtractor(["show", token])) as Profile;
  profileCache.set(token, profile);
  return profile;
}

async function listRequests(limit: number, urlFilter = ""): Promise<any[]> {
  const result = await runExtractor(["list", String(limit), urlFilter]);
  return result.requests ?? [];
}

/** SQL normaliseren zodat identieke query-shapes samenvallen. */
function normalizeSql(sql: string): string {
  return sql
    .replace(/\s+/g, " ")
    .replace(/'(?:[^'\\]|\\.)*'/g, "?")
    .replace(/\b\d+(\.\d+)?\b/g, "?")
    .replace(/IN\s*\((\s*\?\s*,?\s*)+\)/gi, "IN (?)")
    .trim();
}

type Frame = NonNullable<Query["backtrace"]>[number];

/** Bovenste project-frame (innermost) — waar de query vandaan komt. */
function topFrame(q: Query): Frame | null {
  return q.backtrace?.find((f) => f.file) ?? null;
}

function formatFrame(f: Frame): string {
  return `${f.file}:${f.line}${f.call ? ` (${f.call})` : ""}`;
}

/** Volledige project-frame-keten (innermost eerst). Bij een write toont dit
 *  de weg náár flush(), zodat de agent de persist-locatie ziet i.p.v. alleen
 *  de flush()-regel. */
function frameChain(q: Query): string[] {
  return (q.backtrace ?? []).filter((f) => f.file).map(formatFrame);
}

/** Containerpad (bv. /app/src/Foo.php) -> hostpad, zodat we de bron lokaal
 *  kunnen inlezen. Valt terug op het pad zelf als dat al op de host bestaat. */
function resolveHostPath(p: string): string | null {
  if (p.startsWith(containerDir + "/")) {
    const mapped = path.join(projectDir, p.slice(containerDir.length + 1));
    if (fs.existsSync(mapped)) return mapped;
  }
  return fs.existsSync(p) ? p : null;
}

type SourceContext = {
  file: string;
  line: number;
  snippet: { line: number; code: string; origin?: true }[];
};

/** Leest de veroorzakende regel + omliggende context uit de projectbron, zodat
 *  de agent de fix ziet zonder een extra bestand te hoeven openen. */
function sourceContext(frame: Frame | null, radius = 3): SourceContext | undefined {
  if (!frame?.file || !frame.line) return undefined;
  const host = resolveHostPath(frame.file);
  if (!host) return undefined;
  let lines: string[];
  try {
    lines = fs.readFileSync(host, "utf-8").split("\n");
  } catch {
    return undefined;
  }
  const idx = frame.line - 1;
  if (idx < 0 || idx >= lines.length) return undefined;
  const start = Math.max(0, idx - radius);
  const end = Math.min(lines.length - 1, idx + radius);
  const snippet: SourceContext["snippet"] = [];
  for (let i = start; i <= end; i++) {
    snippet.push(i === idx ? { line: i + 1, code: lines[i], origin: true } : { line: i + 1, code: lines[i] });
  }
  return { file: host, line: frame.line, snippet };
}

function summarize(p: Profile) {
  return {
    token: p.token,
    method: p.method,
    url: p.url,
    route: p.route,
    status_code: p.status_code,
    duration_ms: p.duration_ms,
    memory_mb: p.memory_mb,
    query_count: p.query_count,
    query_time_ms: p.query_time_ms,
  };
}

function json(data: unknown) {
  return { content: [{ type: "text" as const, text: JSON.stringify(data, null, 2) }] };
}

/** Platform-bewuste heuristiek op EXPLAIN-output: markeert de klassieke
 *  langzaam-signalen zodat de agent niet zelf plan-jargon hoeft te ontleden. */
function explainWarnings(platform: string, rows: unknown): string[] {
  if (!Array.isArray(rows)) return [];
  const w: string[] = [];
  if (platform === "mysql") {
    for (const r of rows as Record<string, unknown>[]) {
      const table = String(r.table ?? "?");
      if (String(r.type ?? "").toUpperCase() === "ALL") {
        w.push(`Full table scan op '${table}' (type=ALL) — vaak een ontbrekende index.`);
      }
      if ((r.key === null || r.key === undefined || r.key === "") && r.possible_keys) {
        w.push(`Index beschikbaar maar niet gebruikt op '${table}' (possible_keys=${String(r.possible_keys)}, key=NULL).`);
      }
      const extra = String(r.Extra ?? r.extra ?? "");
      if (/using filesort/i.test(extra)) w.push(`Filesort op '${table}' — overweeg een index die de ORDER BY dekt.`);
      if (/using temporary/i.test(extra)) w.push(`Tijdelijke tabel op '${table}' — vaak door GROUP BY/DISTINCT zonder dekkende index.`);
    }
  } else if (platform === "postgresql") {
    for (const r of rows as Record<string, unknown>[]) {
      const line = String(r["QUERY PLAN"] ?? Object.values(r)[0] ?? "");
      const m = line.match(/Seq Scan on (\S+)/i);
      if (m) w.push(`Sequential scan op '${m[1]}' — mogelijk ontbrekende index.`);
    }
  } else if (platform === "sqlite") {
    for (const r of rows as Record<string, unknown>[]) {
      const detail = String(r.detail ?? Object.values(r).join(" ")).trim();
      if (/^SCAN\b/i.test(detail)) w.push(`Full scan: ${detail}`);
    }
  }
  return w;
}

// ---------- MCP-server & tools ----------

const server = new McpServer({ name: "symfony-runtime-mcp", version: "0.1.0" });

server.registerTool(
  "list_requests",
  {
    description:
      "Lijst recente HTTP-requests uit de Symfony profiler, met duur, status, query-count en geheugen. Startpunt voor elke performance-analyse.",
    inputSchema: {
      limit: z.number().int().min(1).max(100).default(20),
      url_filter: z.string().optional().describe("Substring-filter op de URL, bv. '/checkout'"),
      enrich: z.boolean().default(true).describe("Profielen ophalen voor duur/queries (iets trager)"),
    },
  },
  async ({ limit, url_filter, enrich }) => {
    const requests = await listRequests(limit, url_filter ?? "");
    if (!enrich) return json({ requests });
    const enriched = await Promise.all(
      requests.map(async (r) => {
        try {
          return summarize(await getProfile(r.token));
        } catch {
          return r;
        }
      }),
    );
    return json({ requests: enriched });
  },
);

server.registerTool(
  "get_request_profile",
  {
    description:
      "Volledig profiel van één request: timing, geheugen, controller, alle Doctrine-queries met backtraces (indien profiling_collect_backtrace aanstaat).",
    inputSchema: { token: z.string().describe("Profiler-token, bv. 'a1b2c3'") },
  },
  async ({ token }) => json(await getProfile(token)),
);

server.registerTool(
  "slow_queries",
  {
    description:
      "Traagste query-shapes over de laatste N requests, gegroepeerd op genormaliseerde SQL. Toont totale tijd, aantal uitvoeringen en de veroorzakende regel projectcode. " +
      "Let op de aard van 'origin': bij SELECT's wijst die naar de echte trigger (bv. een lazy-load in een lus) — actionable. Bij INSERT/UPDATE/COMMIT wijst 'origin' meestal naar de flush()-regel; dat is waar Doctrine de queue wegschrijft, niet de oorzaak. Een '(flush)'-origin betekent dus: kijk naar de persist-/business-logica, niet naar die regel zelf.",
    inputSchema: {
      requests: z.number().int().min(1).max(50).default(15).describe("Aantal recente requests om te analyseren"),
      top: z.number().int().min(1).max(50).default(10),
      url_filter: z.string().optional(),
    },
  },
  async ({ requests, top, url_filter }) => {
    const metas = await listRequests(requests, url_filter ?? "");
    const groups = new Map<string, { total_ms: number; count: number; max_ms: number; sample_sql: string; originFrame: Frame | null; chain: string[]; seen_on: Set<string> }>();

    for (const meta of metas) {
      let profile: Profile;
      try { profile = await getProfile(meta.token); } catch { continue; }
      for (const q of profile.queries ?? []) {
        const key = normalizeSql(q.sql);
        const g = groups.get(key) ?? { total_ms: 0, count: 0, max_ms: 0, sample_sql: q.sql, originFrame: null, chain: [], seen_on: new Set<string>() };
        g.total_ms += q.ms;
        g.count += 1;
        g.max_ms = Math.max(g.max_ms, q.ms);
        if (!g.originFrame) {
          const tf = topFrame(q);
          if (tf) { g.originFrame = tf; g.chain = frameChain(q); }
        }
        g.seen_on.add(`${profile.method} ${profile.url}`);
        groups.set(key, g);
      }
    }

    const ranked = [...groups.entries()]
      .sort((a, b) => b[1].total_ms - a[1].total_ms)
      .slice(0, top)
      .map(([shape, g]) => ({
        sql_shape: shape,
        total_ms: Math.round(g.total_ms * 10) / 10,
        executions: g.count,
        avg_ms: Math.round((g.total_ms / g.count) * 100) / 100,
        max_ms: g.max_ms,
        origin: g.originFrame
          ? formatFrame(g.originFrame)
          : "onbekend — zet doctrine.dbal.profiling_collect_backtrace: true in config/packages/dev/doctrine.yaml",
        origin_chain: g.chain.length > 1 ? g.chain : undefined,
        origin_context: sourceContext(g.originFrame),
        seen_on: [...g.seen_on].slice(0, 5),
      }));

    return json({ analyzed_requests: metas.length, slow_queries: ranked });
  },
);

server.registerTool(
  "detect_n_plus_one",
  {
    description:
      "Detecteert N+1-patronen binnen één request: identieke query-shapes die herhaald worden uitgevoerd, met de veroorzakende regel code. Geef een token, of een url_filter om het recentste passende request te pakken.",
    inputSchema: {
      token: z.string().optional(),
      url_filter: z.string().optional(),
      threshold: z.number().int().min(2).default(5).describe("Minimaal aantal herhalingen om te rapporteren"),
    },
  },
  async ({ token, url_filter, threshold }) => {
    let resolvedToken = token;
    if (!resolvedToken) {
      const metas = await listRequests(1, url_filter ?? "");
      if (!metas.length) throw new Error("Geen requests gevonden — doe eerst een request naar de app.");
      resolvedToken = metas[0].token;
    }
    const profile = await getProfile(resolvedToken!);
    const queries = profile.queries ?? [];

    // Groeperen op (shape + origin), niet alleen op shape: een echte N+1 is
    // dezelfde query die vanuit één regel in een lus herhaald wordt. Zo vallen
    // twee toevallig identieke shapes vanaf verschillende plekken niet samen.
    // (Zonder backtrace is origin leeg en valt dit terug op groeperen op shape.)
    type Group = { shape: string; count: number; total_ms: number; sample_sql: string; originFrame: Frame | null; chain: string[]; firstIndex: number };
    const groups = new Map<string, Group>();
    queries.forEach((q, i) => {
      const shape = normalizeSql(q.sql);
      const tf = topFrame(q);
      const key = `${shape} ${tf ? formatFrame(tf) : ""}`;
      let g = groups.get(key);
      if (!g) {
        g = { shape, count: 0, total_ms: 0, sample_sql: q.sql, originFrame: tf, chain: tf ? frameChain(q) : [], firstIndex: i };
        groups.set(key, g);
      }
      g.count += 1;
      g.total_ms += q.ms;
    });

    const suspects = [...groups.values()]
      .filter((g) => g.count >= threshold)
      .sort((a, b) => b.count - a.count)
      .map((g) => {
        // De query direct vóór de eerste herhaling is vaak de 'parent' waarvan
        // het resultaat wordt geïtereerd — de klassieke 1+N-signatuur.
        const parentQ = g.firstIndex > 0 ? queries[g.firstIndex - 1] : undefined;
        const parentShape = parentQ ? normalizeSql(parentQ.sql) : undefined;
        const likely_parent =
          parentQ && parentShape !== g.shape
            ? { sql_shape: parentShape!.slice(0, 300), origin: (() => { const pf = topFrame(parentQ); return pf ? formatFrame(pf) : null; })() }
            : undefined;
        return {
          executions: g.count,
          total_ms: Math.round(g.total_ms * 10) / 10,
          sample_sql: g.sample_sql.slice(0, 400),
          origin: g.originFrame
            ? formatFrame(g.originFrame)
            : "onbekend — zet profiling_collect_backtrace aan voor bestand:regel",
          origin_chain: g.chain.length > 1 ? g.chain : undefined,
          origin_context: sourceContext(g.originFrame),
          likely_parent,
          hint: "Overweeg een JOIN/fetch-join, batch-loading of fetch: EAGER voor deze relatie.",
        };
      });

    return json({ request: summarize(profile), n_plus_one_suspects: suspects });
  },
);

server.registerTool(
  "profile_diff",
  {
    description:
      "Vergelijkt twee profielen van hetzelfde endpoint (voor/na een codewijziging): duur, geheugen, query-count en welke query-shapes zijn verdwenen of bijgekomen. Geef twee tokens, of een url_filter om automatisch de twee recentste requests te vergelijken.",
    inputSchema: {
      token_before: z.string().optional(),
      token_after: z.string().optional(),
      url_filter: z.string().optional(),
    },
  },
  async ({ token_before, token_after, url_filter }) => {
    let before = token_before;
    let after = token_after;
    if (!before || !after) {
      const metas = await listRequests(2, url_filter ?? "");
      if (metas.length < 2) throw new Error("Minstens twee requests naar dit endpoint nodig om te vergelijken.");
      after = after ?? metas[0].token;   // recentst
      before = before ?? metas[1].token; // een eerder
    }

    const [pBefore, pAfter] = await Promise.all([getProfile(before!), getProfile(after!)]);

    const shapes = (p: Profile) => {
      const m = new Map<string, number>();
      for (const q of p.queries ?? []) m.set(normalizeSql(q.sql), (m.get(normalizeSql(q.sql)) ?? 0) + 1);
      return m;
    };
    const sBefore = shapes(pBefore);
    const sAfter = shapes(pAfter);

    const removed = [...sBefore.entries()]
      .filter(([k]) => !sAfter.has(k))
      .map(([sql, count]) => ({ sql_shape: sql.slice(0, 300), executions: count }));
    const added = [...sAfter.entries()]
      .filter(([k]) => !sBefore.has(k))
      .map(([sql, count]) => ({ sql_shape: sql.slice(0, 300), executions: count }));
    const changed = [...sBefore.entries()]
      .filter(([k, c]) => sAfter.has(k) && sAfter.get(k) !== c)
      .map(([sql, c]) => ({ sql_shape: sql.slice(0, 300), before: c, after: sAfter.get(sql) }));

    const delta = (a?: number, b?: number) =>
      a != null && b != null ? Math.round((b - a) * 10) / 10 : null;

    return json({
      before: summarize(pBefore),
      after: summarize(pAfter),
      delta: {
        duration_ms: delta(pBefore.duration_ms, pAfter.duration_ms),
        memory_mb: delta(pBefore.memory_mb, pAfter.memory_mb),
        query_count: delta(pBefore.query_count, pAfter.query_count),
        query_time_ms: delta(pBefore.query_time_ms, pAfter.query_time_ms),
      },
      queries_removed: removed,
      queries_added: added,
      queries_changed_count: changed,
    });
  },
);

server.registerTool(
  "search_logs",
  {
    description:
      "Doorzoekt var/log/dev.log van het project (bind-mounted, dus direct leesbaar). Filtert op substring en optioneel log-level, geeft de laatste matches terug.",
    inputSchema: {
      query: z.string().optional().describe("Substring om op te filteren (case-insensitive)"),
      level: z.enum(["DEBUG", "INFO", "NOTICE", "WARNING", "ERROR", "CRITICAL"]).optional(),
      limit: z.number().int().min(1).max(200).default(40),
    },
  },
  async ({ query, level, limit }) => {
    const logFile = path.join(projectDir, "var", "log", "dev.log");
    if (!fs.existsSync(logFile)) {
      throw new Error(`Logbestand niet gevonden: ${logFile}. Is var/ bind-mounted en logt Monolog naar dev.log?`);
    }
    // Alleen de staart lezen; dev.log kan groot worden
    const stat = fs.statSync(logFile);
    const readBytes = Math.min(stat.size, 4 * 1024 * 1024);
    const fd = fs.openSync(logFile, "r");
    const buffer = Buffer.alloc(readBytes);
    fs.readSync(fd, buffer, 0, readBytes, stat.size - readBytes);
    fs.closeSync(fd);

    let lines = buffer.toString("utf-8").split("\n");
    if (query) lines = lines.filter((l) => l.toLowerCase().includes(query.toLowerCase()));
    if (level) lines = lines.filter((l) => l.includes(`.${level}:`) || l.includes(`"level_name":"${level}"`));
    return json({ matches: lines.filter(Boolean).slice(-limit) });
  },
);

server.registerTool(
  "request_breakdown",
  {
    description:
      "Splitst de wall-clock van één request op per categorie (Doctrine/DB, externe http_client-calls, template-rendering, event listeners, …) — zodat je ziet wáár de tijd heen ging, niet alleen in de DB. Geef een token, of een url_filter om het recentste passende request te pakken. " +
      "Let op: categorieën kunnen nesten (bv. 'controller' omvat 'doctrine'/'template'), dus ze tellen niet per se op tot 100%.",
    inputSchema: {
      token: z.string().optional(),
      url_filter: z.string().optional(),
    },
  },
  async ({ token, url_filter }) => {
    let resolved = token;
    if (!resolved) {
      const metas = await listRequests(1, url_filter ?? "");
      if (!metas.length) throw new Error("Geen requests gevonden — doe eerst een request naar de app.");
      resolved = metas[0].token;
    }
    const p = await getProfile(resolved!);
    const total = p.duration_ms ?? 0;
    const pct = (ms: number) => (total > 0 ? Math.round((ms / total) * 1000) / 10 : null);

    const timeline = p.timeline_ms ?? {};
    const categories = Object.entries(timeline)
      .sort((a, b) => b[1] - a[1])
      .map(([category, ms]) => ({ category, ms, pct_of_total: pct(ms) }));

    return json({
      request: summarize(p),
      total_ms: total,
      database: {
        query_count: p.query_count ?? 0,
        query_time_ms: p.query_time_ms ?? 0,
        pct_of_total: pct(p.query_time_ms ?? 0),
      },
      categories,
      note: categories.length
        ? "Categorieën kunnen nesten (bv. 'controller' omvat 'doctrine'/'template'); ze tellen niet per se op tot total_ms."
        : "Geen timeline beschikbaar — draait de 'time'-collector (standaard aan in dev met de profiler)?",
    });
  },
);

server.registerTool(
  "explain_query",
  {
    description:
      "Draait EXPLAIN op een query en zegt WAAROM die traag is (full table scan, ontbrekende/ongebruikte index, filesort). Werkflow: pak via get_request_profile de ruwe 'sql' (+ 'params') van een trage query uit slow_queries, en geef die hier door. Maakt verbinding via DATABASE_URL. " +
      "analyze=true draait EXPLAIN ANALYZE (échte timings) maar is alleen toegestaan voor SELECT/WITH — voor writes geweigerd, omdat het de query anders echt zou uitvoeren.",
    inputSchema: {
      sql: z.string().describe("Ruwe SQL uit get_request_profile (queries[].sql). Placeholders '?' mogen; geef dan params mee."),
      params: z
        .array(z.union([z.string(), z.number(), z.boolean(), z.null()]))
        .optional()
        .describe("Positionele params voor de '?'-placeholders (uit queries[].params). Vervang '[REDACTED]'/afgekapte waarden door een representatieve waarde."),
      analyze: z.boolean().default(false).describe("EXPLAIN ANALYZE: voert de query écht uit voor echte timings. Alleen SELECT/WITH."),
    },
  },
  async ({ sql, params, analyze }) => {
    const enc = (s: string) => Buffer.from(s, "utf-8").toString("base64");
    const result = await runExtractor([
      "explain",
      enc(sql),
      enc(JSON.stringify(params ?? [])),
      analyze ? "1" : "0",
    ]);
    return json({ ...result, warnings: explainWarnings(result.platform, result.rows) });
  },
);

// ---------- Start ----------

ensureExtractor();
const transport = new StdioServerTransport();
await server.connect(transport);
console.error(`symfony-runtime-mcp draait voor ${projectDir} (service: ${service}, containerpad: ${containerDir})`);
