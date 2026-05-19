# maludb-edge API and MCP Design

Date: 2026-05-18

## Purpose

`maludb-edge` is a plain PHP API server for MaluDB. It exposes MaluDB core capabilities through a versioned REST API and an MCP server facade so human clients, applications, and LLM agents can use the same governed functionality.

The first release prioritizes broad API coverage over a deep service layer. REST endpoints are resource-oriented, but most handlers are thin wrappers around the MaluDB PHP driver or direct PDO calls for MaluDB functions not yet wrapped by the driver.

## Goals

- Provide a `/v1` REST API for MaluDB ingest, retrieval, SVPOR navigation, pools, skills, prompt/runtime operations, PageIndex, ChatIndex, node sync, SQL execution, files, users, tenants, and API keys.
- Provide an MCP server that exposes the same governed API capabilities directly to LLM clients.
- Use plain PHP with no web framework.
- Use local SQLite for edge metadata.
- Support per-user API keys, role defaults, optional scopes, fixed tenant binding per key, and encrypted tenant DSNs.
- Store uploaded source documents durably on the API server and allow authenticated downloads.
- Support synchronous calls by default and optional async jobs for long operations.
- Keep success responses close to the MaluDB PHP driver or SQL result shape.

## Non-Goals

- Do not build a UI in v1.
- Do not replace MaluDB core authorization. The target database remains authoritative for SQL and stored-function permissions.
- Do not implement a generic RPC fallback. Public endpoints are resource-oriented REST endpoints.
- Do not require prompt approval workflow in v1. Saved prompts can be run.
- Do not classify SQL as read/write in edge. Clients choose the SQL endpoint, and database permissions decide access.

## Architecture

The API is a plain PHP application under `html/` with:

- A single front controller.
- A small custom router.
- JSON request parsing and response helpers.
- Middleware-style auth, scope, tenant, and error handling.
- A MaluDB client factory that resolves the API key's fixed tenant and decrypts the tenant DSN credentials.
- Thin endpoint handlers that call the MaluDB PHP driver where available.
- Direct PDO query helpers for SVPOR browsing, prompt runtime, SQL execution, MCP, and other MaluDB functions not yet represented in the PHP driver.

The MCP facade uses the same handlers and authorization checks as REST. It does not duplicate business logic.

## Local Metadata Database

SQLite stores edge-local operational metadata:

- `users`: local account records.
- `tenants`: encrypted MaluDB DSNs and connection credentials.
- `api_keys`: hashed keys, owner user, fixed tenant, role, scopes, status, timestamps.
- `files`: durable archive metadata, storage path, hash, MIME type, size, owner user, linked MaluDB IDs, and status.
- `file_tags`: local file tags for fast archive filtering.
- `jobs`: async job state for upload/ingest/index work.
- `audit_events`: API and MCP request metadata.
- `sql_history`: statement hash, user, key, tenant, status, duration, row count, and error metadata. Full SQL text is not stored.

Tenant secrets are encrypted with an application secret. The application must fail closed if the encryption key is missing.

## Authentication and Authorization

Clients authenticate with either:

- `Authorization: Bearer <api_key>`
- `X-MaluDB-Key: <api_key>`

API keys are shown once when created and stored only as hashes. Each key belongs to one user and targets one fixed tenant. Requests cannot override the tenant.

Keys have a role plus optional scopes. Roles provide defaults such as `admin`, `writer`, and `reader`. Explicit scopes can narrow or extend capabilities. SQL access uses one local scope, `sql:execute`, for single statements, stored functions, and transaction batches.

The first admin user and key are created by a CLI bootstrap command. After bootstrap, admins can manage users, tenants, and keys through REST. CLI remains available for recovery.

## Response and Error Shape

Successful responses are raw, driver-shaped JSON or SQL-result JSON. There is no universal `{ "data": ... }` wrapper.

Errors use a consistent JSON shape:

```json
{
  "error": {
    "code": "maludb_not_found",
    "message": "Object not found",
    "detail": "Optional diagnostic detail"
  }
}
```

MaluDB driver exceptions map to appropriate HTTP statuses. Validation errors return `400`, authentication failures return `401`, scope failures return `403`, missing routes return `404`, conflicts return `409`, and unexpected failures return `500`.

## REST Endpoint Catalog

All REST API endpoints live under `/v1`.

### Operations and Docs

- `GET /v1/health`
- `GET /v1/version`
- `GET /v1/openapi.json`
- `GET /v1/docs`

### Users, Tenants, and API Keys

- `POST /v1/users`
- `GET /v1/users`
- `GET /v1/users/{id}`
- `PATCH /v1/users/{id}`
- `POST /v1/users/{id}/api-keys`
- `GET /v1/api-keys`
- `PATCH /v1/api-keys/{id}`
- `DELETE /v1/api-keys/{id}`
- `POST /v1/tenants`
- `GET /v1/tenants`
- `GET /v1/tenants/{id}`
- `PATCH /v1/tenants/{id}`

### File Archive

- `POST /v1/files`
- `GET /v1/files`
- `GET /v1/files/{id}`
- `GET /v1/files/{id}/download`
- `DELETE /v1/files/{id}`
- `POST /v1/files/{id}/tags`
- `GET /v1/files/{id}/tags`
- `DELETE /v1/files/{id}/tags/{tag_id}`

Files are stored durably outside the public web root. Per-user deduplication reuses the physical file only for the same user and hash. Downloads require authenticated file-read permission and ownership or admin access.

### Ingest and Retrieval

- `POST /v1/source-packages`
- `POST /v1/claims`
- `POST /v1/facts`
- `POST /v1/memories`
- `POST /v1/episodes`
- `GET /v1/search/text`
- `POST /v1/retrievals`
- `POST /v1/episodes/{id}/replay`

`POST /v1/retrievals` accepts natural-language retrieval input:

```json
{
  "prompt": "Find the root cause notes for the billing outage",
  "object_types": ["memory", "fact", "claim"],
  "memory_pool_id": 12,
  "subject": "billing_api",
  "verb": "failed",
  "predicate": "root_cause",
  "link_depth": 2,
  "limit": 20
}
```

`prompt` is the natural-language query. `subject`, `verb`, and `predicate` narrow via SVPOR. `memory_pool_id` narrows to a pool. `link_depth` expands related MaluDB objects only. Retrieval responses do not include edge file metadata; clients use file endpoints for archived originals.

### SVPOR

- `POST /v1/subjects`
- `GET /v1/subjects`
- `GET /v1/subjects/{id}`
- `PATCH /v1/subjects/{id}`
- `GET /v1/subjects/{id}/verbs`
- `GET /v1/subjects/{id}/files`
- `POST /v1/verbs`
- `GET /v1/verbs`
- `GET /v1/verbs/{id}`
- `PATCH /v1/verbs/{id}`
- `GET /v1/verbs/{id}/subjects`
- `POST /v1/predicates`
- `GET /v1/predicates`
- `GET /v1/predicates/{id}`
- `PATCH /v1/predicates/{id}`
- `GET /v1/svpor/claims`
- `GET /v1/svpor/facts`
- `POST /v1/svpor/frame-text`

Subject, verb, and predicate creation uses MaluDB SVPOR registration/upsert functions. Relationship browsing reads resolved claim/fact views and relevant document/file tags.

### Active Memory Pools

- `POST /v1/pools`
- `GET /v1/pools`
- `GET /v1/pools/{id}`
- `POST /v1/pools/{id}/observations`
- `POST /v1/pools/{id}/references`
- `POST /v1/pools/{id}/search`
- `POST /v1/pool-members/{id}/promote-claim`
- `POST /v1/pool-members/{id}/promote-fact`
- `POST /v1/pools/{id}/seal`
- `POST /v1/pools/{id}/archive`
- `POST /v1/pools/{id}/tombstone`

### Skills

- `POST /v1/skills`
- `GET /v1/skills`
- `GET /v1/skills/{id}`
- `PATCH /v1/skills/{id}`
- `POST /v1/skills/search`
- `POST /v1/skills/{id}/states`
- `POST /v1/skills/{id}/transitions`
- `POST /v1/skills/{id}/executions`
- `POST /v1/skill-executions/{id}/steps`
- `POST /v1/skill-executions/{id}/abort`

`POST /v1/skills/search` searches only the skill registry fields: name, description, applicability, and preconditions. It does not search linked claims, facts, or memories.

### Prompt Templates and LLM Runtime

- `POST /v1/prompts`
- `GET /v1/prompts`
- `GET /v1/prompts/{id}`
- `PATCH /v1/prompts/{id}`
- `POST /v1/prompts/{id}/preview`
- `POST /v1/prompts/{id}/render`
- `POST /v1/prompts/{id}/run`
- `POST /v1/sessions`
- `POST /v1/sessions/{id}/context`
- `GET /v1/sessions/{id}/context`
- `DELETE /v1/sessions/{id}/context`
- `POST /v1/sessions/{id}/steps`
- `POST /v1/model-requests`
- `GET /v1/model-requests/{id}`
- `POST /v1/model-requests/{id}/cancel`
- `GET /v1/model-requests/{id}/response`

Prompt content updates create new prompt versions. Older versions remain available for audit and repeatability. Any saved prompt can be run in v1.

`POST /v1/prompts/{id}/run` creates a short-lived session automatically. Lower-level session and model-request endpoints are available for clients that need stateful runtime control.

### SQL

- `POST /v1/sql/query`
- `POST /v1/sql/execute`
- `POST /v1/sql/functions/{schema}/{function}`
- `POST /v1/sql/transaction`
- `GET /v1/sql/history`

All SQL endpoints require `sql:execute`. Non-admin users can run any SQL or stored function their fixed tenant database identity can access. Edge does not classify SQL statements as read or write. Raw SQL endpoints accept a single statement. Transaction batches are explicit and run as one transaction; failure rolls back the entire batch.

SQL audit stores only statement hashes and metadata, not full SQL text. There is no edge-enforced global row cap.

### PageIndex and ChatIndex

- `POST /v1/page-indexes`
- `GET /v1/page-indexes`
- `GET /v1/page-indexes/{id}`
- `POST /v1/page-indexes/{id}/ask`
- `POST /v1/page-indexes/{id}/supersede`
- `POST /v1/chat-indexes`
- `POST /v1/chat-indexes/{id}/messages`
- `GET /v1/chat-indexes`
- `POST /v1/chat-indexes/{id}/ask`

Long-running build operations support `async=true`.

### Local Node Sync

- `POST /v1/nodes`
- `POST /v1/nodes/{id}/submissions`
- `POST /v1/node-submissions/{id}/accept`
- `POST /v1/node-submissions/{id}/reject`
- `POST /v1/nodes/{id}/revoke`

### Jobs

- `GET /v1/jobs`
- `GET /v1/jobs/{id}`
- `POST /v1/jobs/{id}/retry`

Jobs track optional async work. Simple calls remain synchronous by default.

## MCP Server

`maludb-edge` also acts as an MCP server. The MCP facade exposes the same governed capabilities as REST, using the same authentication, tenant binding, scopes, audit logging, and database permissions.

Initial hosted transport:

- `POST /mcp` or `/v1/mcp` using MCP Streamable HTTP.

Future local transport:

- `bin/maludb-edge-mcp` using stdio.

MCP tools map to stable API capabilities. Initial tools include:

- `maludb.retrieve`
- `maludb.search_text`
- `maludb.sql`
- `maludb.sql_transaction`
- `maludb.subject_create`
- `maludb.subject_list`
- `maludb.subject_verbs`
- `maludb.verb_list`
- `maludb.verb_subjects`
- `maludb.file_upload`
- `maludb.file_tag`
- `maludb.prompt_run`
- `maludb.skill_search`

MCP resources expose readable context:

- `maludb://files/{id}/metadata`
- `maludb://source-packages/{id}`
- `maludb://memories/{id}`
- `maludb://subjects/{id}`
- `maludb://verbs/{id}`
- `maludb://jobs/{id}`

MCP prompts expose saved prompt templates from `/v1/prompts`.

Sensitive MCP tools require the same explicit scopes as REST. Tool calls are audited as MCP calls and include tool name, user, key, tenant, status, duration, and error metadata.

## OpenAPI and MCP Discovery

`GET /v1/openapi.json` documents the REST API. `GET /v1/docs` serves a simple docs page.

The MCP endpoint supports standard MCP discovery for tools, resources, and prompts. Tool definitions include JSON Schema input definitions, concise descriptions, and structured results where useful.

References:

- MCP tools: https://modelcontextprotocol.io/specification/2025-06-18/server/tools
- MCP resources: https://modelcontextprotocol.io/specification/2025-06-18/server/resources
- MCP prompts: https://modelcontextprotocol.io/specification/2025-06-18/server/prompts
- MCP transports: https://modelcontextprotocol.io/specification/2025-06-18/basic/transports

## Error Handling

Handlers translate MaluDB PHP driver exceptions and PDO exceptions into consistent JSON errors. SQLSTATE and MaluDB exception detail are included when safe.

MCP protocol errors are reserved for malformed JSON-RPC, unknown tools/resources/prompts, and invalid protocol requests. Business and database failures from tool calls return MCP tool results with `isError: true`.

## Testing Strategy

The first implementation plan should include:

- Unit tests for routing, request parsing, JSON responses, scope checks, key hashing, tenant decryption, and SQLite repositories.
- Integration tests against a test MaluDB database for driver-backed endpoints.
- SQL endpoint tests for single statements, stored functions, transaction rollback, and hash-only audit.
- File archive tests for upload, per-user dedupe, tags, and authenticated downloads.
- SVPOR tests for create/list/resolve, subject-to-verb, verb-to-subject, and file tag lookup.
- Prompt/runtime tests for versioning, preview, render, run, model request status, and response retrieval.
- MCP tests for initialize, tools/list, tools/call, resources/list, resources/read, prompts/list, and prompts/get.
- OpenAPI validation tests for documented routes and schemas.

## Implementation Notes

The local Composer package currently exposes many driver methods, including ingest, retrieve, pools, skills, nodes, PageIndex, ChatIndex, and version. SVPOR registry functions, prompt/runtime operations, advanced pool functions, SQL execution, and MCP support should use direct PDO calls until the PHP driver provides first-class wrappers.

The project should keep handlers thin. If repeated validation or serialization grows complex, extract small helpers rather than adopting a framework.
