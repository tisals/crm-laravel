<?php

/*
|--------------------------------------------------------------------------
| Empresas (entity enrichment) — configuration
|--------------------------------------------------------------------------
|
| PR3 of `complementar-entidad` — entity-empresa-enrichment D-config.
|
| All knobs that the Empresas module reads at run-time. Mirrors the
| env vars documented in design.md §14. Every default is a safe
| no-enrichment value so an out-of-the-box deploy does NOT silently
| start firing outbound webhooks / MCP calls.
|
*/

return [
    /*
    | Production MCP transport endpoint (HTTP/SSE) when
    | EMPRESAS_MCP_TRANSPORT=http. Empty string = transport disabled.
    */
    'mcp_url' => env('EMPRESAS_MCP_URL', 'http://localhost:8001/mcp'),

    /*
    | MCP HTTP timeout in seconds.
    */
    'mcp_timeout' => (int) env('EMPRESAS_MCP_TIMEOUT', 10),

    /*
    | Cache TTL (seconds) for `Cache::remember` on enrichment payloads.
    | Spec range: 86400..259200 (24h..72h).
    */
    'cache_ttl' => (int) env('EMPRESAS_MCP_TTL', 86400),

    /*
    | Habeas Data filter mode (PR4 extension). PR3 supports 'mask' only;
    | 'exclude' returns null; 'raw' returns the payload unchanged.
    */
    'habeas_data_mode' => env('EMPRESAS_MCP_HABEAS_DATA_MODE', 'mask'),

    /*
    | Master kill-switch for the entire enrichment pipeline.
    | When false, the service short-circuits with SkippedKillSwitch.
    */
    'enable_emission' => filter_var(
        env('EMIT_EMPRESAS_ENRIQUECIMIENTO', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    | Optional Socrata X-App-Token header for the upstream HTTP request.
    | Empty by default; PR4 wires the actual header injection.
    */
    'socrata_app_token' => env('EMPRESAS_MCP_SOCRATA_APP_TOKEN'),

    /*
    | Number of retry attempts for transient MCP errors. PR3 stores the
    | value; PR4's Http adapter consumes it.
    */
    'mcp_max_retries' => (int) env('EMPRESAS_MCP_MAX_RETRIES', 3),
];