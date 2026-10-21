<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) server
|--------------------------------------------------------------------------
|
| The MCP endpoint is a machine surface: a token, a JSON-RPC body and a reply.
| It is not a session, so it does not inherit the session throttle group that
| protects the REST API, and a token is long-lived by design. Without a quota
| of its own, one token could spend the whole provider budget — and the
| provider's own wall is a 429 that arrives after the work, not before it.
|
| The limits below are per token (falling back to the owning user), and the
| tool limit is per token *and* tool: a read tool that queries the whole
| portfolio is not interchangeable with a cheap one, so a single tool cannot
| consume the whole allowance.
|
| Zero disables a limit, which is the same convention the provider quota
| uses: a wrong local cap would refuse work the provider would have served.
|
*/

return [

    /*
    | Requests per minute for the whole MCP endpoint, per token.
    */
    'requests_per_minute' => (int) env('MCP_REQUESTS_PER_MINUTE', 60),

    /*
    | Calls per minute for a single tool, per token.
    */
    'tool_calls_per_minute' => (int) env('MCP_TOOL_CALLS_PER_MINUTE', 15),

    /*
    | Whether the MCP channel keeps its own log file instead of sharing the
    | application log. The MCP traces contain provider and token detail, so
    | they belong in a file with its own permissions.
    */
    'log_channel' => env('MCP_LOG_CHANNEL', 'mcp'),

];
