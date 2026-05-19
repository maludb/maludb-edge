# MaluDB Edge Application Integration Roadmap

Date: 2026-05-19

Status: roadmap and integration guide

Audience: developers building applications, services, automations, or LLM agents that will communicate with MaluDB through MaluDB Edge.

## Purpose

MaluDB Edge is the HTTP and MCP gateway that sits between client applications and one or more MaluDB databases. Applications should integrate with MaluDB Edge instead of connecting directly to MaluDB when they need API-key authentication, tenant binding, durable source-file storage, consistent error responses, SQL governance, prompt execution, or MCP tool exposure.

This document describes:

- What is currently implemented.
- What API behavior client applications should be designed around.
- What endpoint groups are planned.
- How applications should authenticate, handle errors, store references, and sequence workflows.
- How the API roadmap should be implemented in future backend phases.

The foundation currently implemented is intentionally small. Most endpoint groups in this document are marked as planned. They are included here so client applications can be designed against a stable direction while implementation continues.

## Status Legend

Use these labels when reading endpoint tables:

- **Implemented**: available in the current codebase and covered by tests.
- **Foundation**: supporting infrastructure exists, but the full user-facing endpoint group is not implemented yet.
- **Planned**: part of the approved roadmap, not available until implemented and added to OpenAPI.
- **Future**: likely useful, but not required for the first broad API release.

Client applications should discover live capabilities from `GET /v1/openapi.json`. A roadmap endpoint is not live until it appears in OpenAPI and passes integration testing.

## Current Implemented Surface

The current foundation exposes these live endpoints:

| Method | Path | Status | Purpose |
| --- | --- | --- | --- |
| `GET` | `/v1/health` | Implemented | Health check for load balancers and deployments. |
| `GET` | `/v1/version` | Implemented | Service name and API foundation version. |
| `GET` | `/v1/openapi.json` | Implemented | OpenAPI document for live REST endpoints. |
| `GET` | `/v1/docs` | Implemented | Minimal HTML documentation shell. |
| `GET` | `/v1/me` | Implemented | Authenticated API-key context for the caller. |

The current foundation also includes:

- SQLite metadata database and migrations.
- Encrypted tenant DSN storage.
- API-key generation, hashing, fingerprinting, and verification.
- CLI bootstrap for the first admin user, tenant, and API key.
- Apache `.htaccess` hardening for private paths and known local test files.
- A custom PHP router, request parser, JSON response helper, and test harness.

## System Role

MaluDB Edge has three main responsibilities.

1. **Application gateway**
   - Expose a stable `/v1` HTTP API.
   - Hide direct MaluDB connection details from client applications.
   - Normalize authentication, errors, and metadata.

2. **Governance layer**
   - Bind each API key to exactly one tenant.
   - Store keys as hashes, not plaintext.
   - Apply local roles/scopes before calling MaluDB.
   - Let the target MaluDB database remain authoritative for SQL and stored-function permissions.
   - Keep audit metadata for sensitive operations.

3. **LLM access layer**
   - Expose the same governed capabilities through MCP.
   - Provide tools, resources, and prompts that map to REST behavior.
   - Avoid duplicate business logic between REST and MCP.

## Client Integration Principles

Applications should follow these rules.

1. **Treat MaluDB Edge as the only public API**
   - Do not embed MaluDB database credentials in application code.
   - Do not connect directly to the MaluDB database unless the application is an internal admin utility.

2. **Use one API key per application/user boundary**
   - Use separate keys for separate applications, environments, users, and service roles.
   - Store API keys in secret stores, not source code.
   - Rotate keys when staff, application ownership, or permissions change.

3. **Assume tenant binding**
   - A key is bound to one tenant.
   - Requests should not include tenant override parameters.
   - If an application needs multiple tenants, it should use separate keys or an admin-managed tenant switch workflow outside the request path.

4. **Use OpenAPI as the live contract**
   - Use this roadmap for design.
   - Use `/v1/openapi.json` to determine what is available in the installed deployment.

5. **Keep durable references**
   - Store returned MaluDB object IDs in the consuming application.
   - Store edge file IDs for archived original documents.
   - Do not assume a retrieved object includes all file metadata.

6. **Handle async job responses**
   - Long-running operations may return job IDs.
   - Applications should poll job endpoints or register their own callback layer when available.

7. **Preserve prompt and SQL audit boundaries**
   - Do not expect Edge to store raw SQL text in audit history.
   - Prompt versions should be treated as immutable once created.

## Base URL and Versioning

All REST endpoints are under:

```text
https://<edge-host>/v1
```

The current API version prefix is `v1`. The version prefix is part of the public contract and should be used by every application.

Recommended client configuration:

```text
MALUDB_EDGE_BASE_URL=https://edge.example.com
MALUDB_EDGE_API_KEY=malu_...
```

Recommended request headers:

```http
Authorization: Bearer malu_...
Accept: application/json
Content-Type: application/json
```

Alternative authentication header:

```http
X-MaluDB-Key: malu_...
```

Prefer `Authorization: Bearer` unless an intermediate platform strips Authorization headers.

## Authentication

### API Key Format

API keys are generated by MaluDB Edge and start with:

```text
malu_
```

The plaintext key is shown only once when created. Edge stores only:

- Hash.
- Non-secret fingerprint.
- User ID.
- Tenant ID.
- Role.
- Scopes.
- Revocation and usage timestamps.

### First Admin Key

Bootstrap is performed on the server:

```sh
cd /var/www/html
export MALUDB_EDGE_APP_KEY="replace-with-a-real-32-byte-or-longer-secret"
php bin/edge migrate
php bin/edge admin:create \
  --email=admin@example.com \
  --tenant=default \
  --dsn="pgsql:host=127.0.0.1;port=5432;dbname=maludb" \
  --username=maludb \
  --password="change-me"
```

The command returns:

```text
Admin API key: malu_<secret>
```

Store this key immediately in a secret manager.

### Authenticated Context

Applications can validate their key and discover its context:

```http
GET /v1/me
Authorization: Bearer malu_...
```

Example response:

```json
{
  "user_id": 1,
  "api_key_id": 1,
  "tenant_id": 1,
  "role": "admin",
  "scopes": ["*"]
}
```

Applications should call `/v1/me` during startup or deployment smoke tests to detect revoked or misconfigured keys.

## Roles and Scopes

The roadmap uses these role conventions:

| Role | Intended use |
| --- | --- |
| `admin` | Tenant/user/key administration, recovery, all API capabilities. |
| `writer` | Create and update MaluDB objects, upload files, run retrievals and prompts. |
| `reader` | Read and search only, no mutation. |

The roadmap uses these scope conventions:

| Scope | Planned purpose |
| --- | --- |
| `keys:manage` | Create, list, revoke, and update API keys. |
| `users:manage` | Create and update local user records. |
| `tenants:manage` | Create and update tenant DSN records. |
| `files:read` | List metadata and download archived source files. |
| `files:write` | Upload, tag, and delete archived source files. |
| `ingest:write` | Create source packages, claims, facts, memories, and episodes. |
| `retrieve:read` | Search and retrieve MaluDB data. |
| `svpor:read` | Browse subjects, verbs, predicates, claims, facts, and related files. |
| `svpor:write` | Create or update subjects, verbs, predicates, and framed SVPOR data. |
| `pools:read` | Read active memory pools and members. |
| `pools:write` | Create observations, references, promotions, seals, archives, and tombstones. |
| `skills:read` | Read and search skills. |
| `skills:write` | Create skills, states, transitions, executions, and execution steps. |
| `prompts:read` | List, render, and preview prompts. |
| `prompts:write` | Create and update prompt templates. |
| `prompts:run` | Run prompt templates and model requests. |
| `sql:execute` | Run SQL statements, stored functions, and transaction batches. |
| `mcp:use` | Use MCP tools/resources/prompts if separate MCP gating is enabled. |

The database remains authoritative for SQL permissions. A non-admin user with `sql:execute` can run any SQL or stored function the tenant database identity can access.

## Error Contract

All errors should use this shape:

```json
{
  "error": {
    "code": "invalid_request",
    "message": "Human-readable safe message"
  }
}
```

Some errors may include safe detail:

```json
{
  "error": {
    "code": "maludb_error",
    "message": "MaluDB operation failed",
    "detail": {
      "sqlstate": "23505"
    }
  }
}
```

Expected HTTP statuses:

| Status | Meaning | Client behavior |
| --- | --- | --- |
| `400` | Invalid request body, query parameter, or file payload. | Fix the request before retrying. |
| `401` | Missing, malformed, invalid, or revoked API key. | Refresh or rotate credentials. |
| `403` | Authenticated but missing scope or database permission. | Request permission change or stop. |
| `404` | Route or object not found. | Verify ID and endpoint availability. |
| `409` | Conflict, duplicate object, version mismatch, or invalid state transition. | Re-read state and retry intentionally. |
| `422` | Semantically valid JSON but unsupported operation. | Fix domain input. |
| `429` | Rate limited, if configured later. | Back off and retry. |
| `500` | Unexpected Edge failure. | Retry only if operation is safe/idempotent, then escalate. |
| `502` | MaluDB or model provider unavailable, if configured later. | Retry with backoff. |
| `503` | Edge dependency unavailable. | Retry with backoff. |

Client applications should log:

- HTTP status.
- Error code.
- Request ID when added.
- Operation name.
- Object ID or job ID.

Client applications should not log:

- API keys.
- Tenant DSNs.
- Prompt secrets.
- Raw SQL containing sensitive data.
- Uploaded document contents unless the application has its own retention policy.

## Pagination and Filtering Roadmap

List endpoints should support a consistent query shape:

```text
GET /v1/<resource>?limit=50&cursor=<cursor>&sort=-created_at
```

Recommended response shape:

```json
{
  "items": [],
  "next_cursor": "opaque-cursor-or-null"
}
```

Filtering should use simple query parameters for common filters:

```text
GET /v1/files?tag=contract&mime_type=application/pdf&status=ready
```

Do not expose raw SQL filtering in list endpoints. Use the SQL endpoint when applications need SQL.

## Idempotency Roadmap

Mutating endpoints that can safely support retries should accept:

```http
Idempotency-Key: <client-generated-unique-key>
```

Recommended usage:

- File upload metadata creation.
- Source package creation.
- Claim/fact/memory creation when client has a stable external ID.
- Prompt run creation.
- Model request creation.
- Async job creation.

The first implementation can defer idempotency storage, but API clients should be designed so idempotency keys can be added later without redesign.

## Current Foundation Endpoints

### Health

```http
GET /v1/health
```

Example response:

```json
{
  "status": "ok"
}
```

Use for:

- Load balancer checks.
- Deployment smoke tests.
- Uptime monitoring.

Do not use for:

- Verifying tenant DSN credentials.
- Verifying MaluDB database health.
- Verifying model provider health.

### Version

```http
GET /v1/version
```

Example response:

```json
{
  "name": "maludb-edge",
  "version": "0.1.0"
}
```

Use for:

- Deployment inventory.
- Client compatibility checks.
- Support diagnostics.

### OpenAPI

```http
GET /v1/openapi.json
```

Applications should treat this as the live REST contract.

### Docs Shell

```http
GET /v1/docs
```

This is currently a minimal HTML shell that links to OpenAPI.

### Current API Key Context

```http
GET /v1/me
Authorization: Bearer malu_...
```

Use for:

- Startup credential validation.
- Displaying connected account context in admin tools.
- Confirming scopes during deployment.

## Planned Endpoint Catalog

This section is the roadmap for client-facing API development.

### Operations and Discovery

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/v1/health` | Implemented | none | Basic Edge process health. |
| `GET` | `/v1/version` | Implemented | none | Version metadata. |
| `GET` | `/v1/openapi.json` | Implemented | none | Live REST contract. |
| `GET` | `/v1/docs` | Implemented | none | HTML docs shell. |
| `GET` | `/v1/me` | Implemented | valid key | Current API key context. |

Roadmap additions:

- Add deployment metadata to `/v1/version` when useful.
- Add dependency checks under a protected admin health endpoint, not public `/v1/health`.
- Add request IDs to every response.

### Users, Tenants, and API Keys

Purpose: manage local Edge identities and tenant connection records.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/users` | Planned | `users:manage` | Create a local user. |
| `GET` | `/v1/users` | Planned | `users:manage` | List local users. |
| `GET` | `/v1/users/{id}` | Planned | `users:manage` or self | Read user metadata. |
| `PATCH` | `/v1/users/{id}` | Planned | `users:manage` | Update display name, role, or disabled state. |
| `POST` | `/v1/users/{id}/api-keys` | Planned | `keys:manage` | Create an API key for a user. |
| `GET` | `/v1/api-keys` | Planned | `keys:manage` | List API keys without secret values. |
| `PATCH` | `/v1/api-keys/{id}` | Planned | `keys:manage` | Rename, scope, role, or disable a key. |
| `DELETE` | `/v1/api-keys/{id}` | Planned | `keys:manage` | Revoke an API key. |
| `POST` | `/v1/tenants` | Planned | `tenants:manage` | Create encrypted tenant DSN credentials. |
| `GET` | `/v1/tenants` | Planned | `tenants:manage` | List tenants without secrets. |
| `GET` | `/v1/tenants/{id}` | Planned | `tenants:manage` | Read tenant metadata without decrypted secrets. |
| `PATCH` | `/v1/tenants/{id}` | Planned | `tenants:manage` | Rotate DSN credentials or disable tenant. |

Create user example:

```http
POST /v1/users
Authorization: Bearer malu_admin...
Content-Type: application/json
```

```json
{
  "email": "analyst@example.com",
  "display_name": "Analyst",
  "role": "writer"
}
```

Create API key example:

```http
POST /v1/users/12/api-keys
Authorization: Bearer malu_admin...
Content-Type: application/json
```

```json
{
  "name": "billing-retrieval-service",
  "tenant_id": 1,
  "role": "reader",
  "scopes": ["retrieve:read", "svpor:read", "files:read"]
}
```

Example response:

```json
{
  "id": 44,
  "name": "billing-retrieval-service",
  "key": "malu_newly_generated_secret",
  "fingerprint": "0d7f9a9f03a1",
  "user_id": 12,
  "tenant_id": 1,
  "role": "reader",
  "scopes": ["retrieve:read", "svpor:read", "files:read"],
  "created_at": "2026-05-19T00:00:00Z"
}
```

Client rule: display or store `key` immediately. Edge will never return it again.

### File Archive

Purpose: store source documents on the Edge server so ingestion can reference local durable files.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/files` | Planned | `files:write` | Upload a source document. |
| `GET` | `/v1/files` | Planned | `files:read` | List archived files. |
| `GET` | `/v1/files/{id}` | Planned | `files:read` | Read file metadata. |
| `GET` | `/v1/files/{id}/download` | Planned | `files:read` | Download original file. |
| `DELETE` | `/v1/files/{id}` | Planned | `files:write` | Delete or tombstone file metadata and archive record. |
| `POST` | `/v1/files/{id}/tags` | Planned | `files:write` | Add tags to a file. |
| `GET` | `/v1/files/{id}/tags` | Planned | `files:read` | List tags. |
| `DELETE` | `/v1/files/{id}/tags/{tag_id}` | Planned | `files:write` | Remove a tag. |

Upload example:

```http
POST /v1/files
Authorization: Bearer malu_...
Content-Type: multipart/form-data
```

Fields:

| Field | Required | Meaning |
| --- | --- | --- |
| `file` | yes | Source document bytes. |
| `display_name` | no | Human-readable name. |
| `source_uri` | no | Original external URI, if any. |
| `tags[]` | no | Initial tags. |

Example response:

```json
{
  "id": 101,
  "display_name": "billing-outage-postmortem.pdf",
  "sha256": "b1946ac92492d2347c6235b4d2611184",
  "mime_type": "application/pdf",
  "size_bytes": 184220,
  "status": "ready",
  "tags": ["billing", "incident", "postmortem"],
  "created_at": "2026-05-19T00:00:00Z"
}
```

Storage rules:

- Files are stored outside the public web root.
- Downloads require authentication.
- Physical deduplication may be per user and hash.
- Edge file IDs are local Edge metadata IDs, not MaluDB object IDs.

### Ingest and Retrieval

Purpose: create MaluDB source packages and knowledge objects, then retrieve them through natural language and structured filters.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/source-packages` | Planned | `ingest:write` | Create a source package from uploaded file or inline metadata. |
| `POST` | `/v1/claims` | Planned | `ingest:write` | Create a claim. |
| `POST` | `/v1/facts` | Planned | `ingest:write` | Create a fact. |
| `POST` | `/v1/memories` | Planned | `ingest:write` | Create a memory. |
| `POST` | `/v1/episodes` | Planned | `ingest:write` | Create an episode. |
| `GET` | `/v1/search/text` | Planned | `retrieve:read` | Text search over supported MaluDB objects. |
| `POST` | `/v1/retrievals` | Planned | `retrieve:read` | Natural-language retrieval with optional filters. |
| `POST` | `/v1/episodes/{id}/replay` | Planned | `retrieve:read` | Replay episode context. |

Create source package from an uploaded file:

```http
POST /v1/source-packages
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "file_id": 101,
  "title": "Billing outage postmortem",
  "source_type": "pdf",
  "external_id": "incident-2026-05-01",
  "metadata": {
    "system": "billing",
    "owner": "sre"
  }
}
```

Natural-language retrieval:

```http
POST /v1/retrievals
Authorization: Bearer malu_...
Content-Type: application/json
```

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

Parameter rules:

| Field | Required | Meaning |
| --- | --- | --- |
| `prompt` | yes | Natural-language retrieval request. |
| `object_types` | no | Restrict result classes. |
| `memory_pool_id` | no | Restrict to one active memory pool. |
| `subject` | no | SVPOR subject filter. |
| `verb` | no | SVPOR verb filter. |
| `predicate` | no | SVPOR predicate filter. |
| `link_depth` | no | Expand related data. Default should be `0` or `1`; maximum should be bounded. |
| `limit` | no | Maximum results. |

Expected response pattern:

```json
{
  "query": {
    "prompt": "Find the root cause notes for the billing outage",
    "link_depth": 2
  },
  "results": [
    {
      "type": "memory",
      "id": 882,
      "score": 0.92,
      "summary": "The outage was caused by connection pool exhaustion.",
      "source_package_id": 44,
      "related": [
        {
          "type": "fact",
          "id": 931,
          "relationship": "supports"
        }
      ]
    }
  ]
}
```

Client rule: retrieval results identify MaluDB objects. Use file endpoints to inspect archived originals.

### SVPOR

Purpose: create and browse subject-verb-predicate-object-role relationships.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/subjects` | Planned | `svpor:write` | Create or upsert a subject. |
| `GET` | `/v1/subjects` | Planned | `svpor:read` | List subjects. |
| `GET` | `/v1/subjects/{id}` | Planned | `svpor:read` | Read a subject. |
| `PATCH` | `/v1/subjects/{id}` | Planned | `svpor:write` | Update a subject. |
| `GET` | `/v1/subjects/{id}/verbs` | Planned | `svpor:read` | List verbs used with a subject. |
| `GET` | `/v1/subjects/{id}/files` | Planned | `svpor:read`, `files:read` | List archived files tagged/linked with a subject. |
| `POST` | `/v1/verbs` | Planned | `svpor:write` | Create or upsert a verb. |
| `GET` | `/v1/verbs` | Planned | `svpor:read` | List verbs. |
| `GET` | `/v1/verbs/{id}` | Planned | `svpor:read` | Read a verb. |
| `PATCH` | `/v1/verbs/{id}` | Planned | `svpor:write` | Update a verb. |
| `GET` | `/v1/verbs/{id}/subjects` | Planned | `svpor:read` | List subjects related to a verb. |
| `POST` | `/v1/predicates` | Planned | `svpor:write` | Create or upsert a predicate. |
| `GET` | `/v1/predicates` | Planned | `svpor:read` | List predicates. |
| `GET` | `/v1/predicates/{id}` | Planned | `svpor:read` | Read a predicate. |
| `PATCH` | `/v1/predicates/{id}` | Planned | `svpor:write` | Update a predicate. |
| `GET` | `/v1/svpor/claims` | Planned | `svpor:read` | Browse claims by SVPOR filters. |
| `GET` | `/v1/svpor/facts` | Planned | `svpor:read` | Browse facts by SVPOR filters. |
| `POST` | `/v1/svpor/frame-text` | Planned | `svpor:write` | Frame text into SVPOR candidates or records. |

Create subject example:

```http
POST /v1/subjects
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "name": "billing_api",
  "display_name": "Billing API",
  "description": "Internal service for invoice and payment operations",
  "aliases": ["billing-service", "invoice-api"]
}
```

List verbs in a subject:

```http
GET /v1/subjects/42/verbs
Authorization: Bearer malu_...
```

Example response:

```json
{
  "subject": {
    "id": 42,
    "name": "billing_api"
  },
  "verbs": [
    {
      "id": 7,
      "name": "failed",
      "claim_count": 14,
      "fact_count": 8
    }
  ]
}
```

List files tagged with a subject:

```http
GET /v1/subjects/42/files
Authorization: Bearer malu_...
```

Example response:

```json
{
  "subject": {
    "id": 42,
    "name": "billing_api"
  },
  "files": [
    {
      "id": 101,
      "display_name": "billing-outage-postmortem.pdf",
      "tags": ["billing_api", "postmortem"],
      "created_at": "2026-05-19T00:00:00Z"
    }
  ]
}
```

Client workflow:

1. Use `/v1/subjects` to discover or create subjects.
2. Use `/v1/subjects/{id}/verbs` to find actions or relationships around a subject.
3. Use `/v1/verbs/{id}/subjects` to discover related subjects.
4. Use `/v1/svpor/claims` and `/v1/svpor/facts` to inspect evidence.
5. Use `/v1/subjects/{id}/files` when an application needs original documents.

### Active Memory Pools

Purpose: manage short-lived or curated working memory collections.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/pools` | Planned | `pools:write` | Create an active memory pool. |
| `GET` | `/v1/pools` | Planned | `pools:read` | List pools. |
| `GET` | `/v1/pools/{id}` | Planned | `pools:read` | Read pool metadata and state. |
| `POST` | `/v1/pools/{id}/observations` | Planned | `pools:write` | Add observation to pool. |
| `POST` | `/v1/pools/{id}/references` | Planned | `pools:write` | Add reference to MaluDB object. |
| `POST` | `/v1/pools/{id}/search` | Planned | `pools:read` | Search inside a pool. |
| `POST` | `/v1/pool-members/{id}/promote-claim` | Planned | `pools:write` | Promote pool member to claim. |
| `POST` | `/v1/pool-members/{id}/promote-fact` | Planned | `pools:write` | Promote pool member to fact. |
| `POST` | `/v1/pools/{id}/seal` | Planned | `pools:write` | Seal pool against further mutation. |
| `POST` | `/v1/pools/{id}/archive` | Planned | `pools:write` | Archive pool. |
| `POST` | `/v1/pools/{id}/tombstone` | Planned | `pools:write` | Tombstone pool. |

Typical application use:

- Incident response workspace.
- Research session memory.
- LLM planning scratchpad.
- Curated set of claims and facts to promote later.

### Skills

Purpose: store, search, and execute skill definitions and state transitions.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/skills` | Planned | `skills:write` | Create a skill. |
| `GET` | `/v1/skills` | Planned | `skills:read` | List skills. |
| `GET` | `/v1/skills/{id}` | Planned | `skills:read` | Read a skill. |
| `PATCH` | `/v1/skills/{id}` | Planned | `skills:write` | Update skill metadata. |
| `POST` | `/v1/skills/search` | Planned | `skills:read` | Natural-language skill search. |
| `POST` | `/v1/skills/{id}/states` | Planned | `skills:write` | Add state. |
| `POST` | `/v1/skills/{id}/transitions` | Planned | `skills:write` | Add transition. |
| `POST` | `/v1/skills/{id}/executions` | Planned | `skills:write` | Start skill execution. |
| `POST` | `/v1/skill-executions/{id}/steps` | Planned | `skills:write` | Record execution step. |
| `POST` | `/v1/skill-executions/{id}/abort` | Planned | `skills:write` | Abort execution. |

Natural-language skill search:

```http
POST /v1/skills/search
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "prompt": "Find a skill for summarizing incident reports and extracting root causes",
  "limit": 5
}
```

Search scope:

- Skill name.
- Description.
- Applicability.
- Preconditions.

It should not search linked claims, facts, or memories unless a future endpoint explicitly adds that behavior.

### Prompt Templates and LLM Runtime

Purpose: let applications store prompt templates, render them, run them, and track model requests.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/prompts` | Planned | `prompts:write` | Create prompt template. |
| `GET` | `/v1/prompts` | Planned | `prompts:read` | List prompt templates. |
| `GET` | `/v1/prompts/{id}` | Planned | `prompts:read` | Read prompt template and latest version. |
| `PATCH` | `/v1/prompts/{id}` | Planned | `prompts:write` | Create a new prompt version. |
| `POST` | `/v1/prompts/{id}/preview` | Planned | `prompts:read` | Preview variables and rendered structure without model call. |
| `POST` | `/v1/prompts/{id}/render` | Planned | `prompts:read` | Render prompt with variables. |
| `POST` | `/v1/prompts/{id}/run` | Planned | `prompts:run` | Render and execute through LLM extension. |
| `POST` | `/v1/sessions` | Planned | `prompts:run` | Create runtime session. |
| `POST` | `/v1/sessions/{id}/context` | Planned | `prompts:run` | Add context. |
| `GET` | `/v1/sessions/{id}/context` | Planned | `prompts:read` | Read session context. |
| `DELETE` | `/v1/sessions/{id}/context` | Planned | `prompts:run` | Clear session context. |
| `POST` | `/v1/sessions/{id}/steps` | Planned | `prompts:run` | Add step to session. |
| `POST` | `/v1/model-requests` | Planned | `prompts:run` | Create direct model request. |
| `GET` | `/v1/model-requests/{id}` | Planned | `prompts:read` | Read model request status. |
| `POST` | `/v1/model-requests/{id}/cancel` | Planned | `prompts:run` | Cancel model request. |
| `GET` | `/v1/model-requests/{id}/response` | Planned | `prompts:read` | Fetch model response. |

Create prompt:

```json
{
  "name": "incident_root_cause_summary",
  "description": "Summarize incident evidence and identify likely root cause",
  "template": "Use the evidence below to summarize the incident.\n\n{{evidence}}\n\nReturn root cause, impact, and next actions.",
  "input_schema": {
    "type": "object",
    "required": ["evidence"],
    "properties": {
      "evidence": {
        "type": "string"
      }
    }
  }
}
```

Run prompt:

```http
POST /v1/prompts/9/run
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "variables": {
    "evidence": "Connection pool exhausted after deployment..."
  },
  "model": "default",
  "store_response": true
}
```

Prompt versioning rules:

- Creating a prompt creates version `1`.
- Updating prompt content creates a new version.
- Prior versions remain available for audit and repeatability.
- A run should record prompt ID, version ID, model, caller, and status.

### SQL

Purpose: expose governed SQL execution while preserving database-native permissions.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/sql/query` | Planned | `sql:execute` | Run one SQL statement expected to return rows. |
| `POST` | `/v1/sql/execute` | Planned | `sql:execute` | Run one SQL statement not necessarily returning rows. |
| `POST` | `/v1/sql/functions/{schema}/{function}` | Planned | `sql:execute` | Execute stored function. |
| `POST` | `/v1/sql/transaction` | Planned | `sql:execute` | Run explicit transaction batch. |
| `GET` | `/v1/sql/history` | Planned | `sql:execute` or admin | Read hash-only audit history. |

Query example:

```http
POST /v1/sql/query
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "sql": "select id, title from public.documents where status = :status order by id desc limit 25",
  "params": {
    "status": "ready"
  }
}
```

Stored function example:

```http
POST /v1/sql/functions/public/search_memories
Authorization: Bearer malu_...
Content-Type: application/json
```

```json
{
  "args": {
    "query": "billing outage root cause",
    "limit": 10
  }
}
```

Transaction example:

```json
{
  "statements": [
    {
      "sql": "insert into audit_labels(name) values (:name)",
      "params": {
        "name": "billing"
      }
    },
    {
      "sql": "select public.rebuild_label_index()",
      "params": {}
    }
  ]
}
```

SQL rules:

- Edge requires `sql:execute`.
- The database identity configured for the tenant determines actual SQL access.
- Edge does not classify SQL as read or write.
- Raw SQL endpoints accept one statement.
- Transaction batches are explicit and all-or-nothing.
- SQL audit stores statement hashes and metadata, not full SQL text.

Client applications should use stored functions for stable business operations when possible.

### PageIndex and ChatIndex

Purpose: expose MaluDB indexing and question-answering capabilities.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/page-indexes` | Planned | `ingest:write` | Create a page index. |
| `GET` | `/v1/page-indexes` | Planned | `retrieve:read` | List page indexes. |
| `GET` | `/v1/page-indexes/{id}` | Planned | `retrieve:read` | Read page index metadata. |
| `POST` | `/v1/page-indexes/{id}/ask` | Planned | `retrieve:read` | Ask a question over a page index. |
| `POST` | `/v1/page-indexes/{id}/supersede` | Planned | `ingest:write` | Supersede old index. |
| `POST` | `/v1/chat-indexes` | Planned | `ingest:write` | Create chat index. |
| `POST` | `/v1/chat-indexes/{id}/messages` | Planned | `ingest:write` | Add message. |
| `GET` | `/v1/chat-indexes` | Planned | `retrieve:read` | List chat indexes. |
| `POST` | `/v1/chat-indexes/{id}/ask` | Planned | `retrieve:read` | Ask question over chat index. |

Long-running builds should support `async=true` and return a job ID.

### Local Node Sync

Purpose: support local node submission and governance workflows.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/v1/nodes` | Planned | admin or node scope | Register node. |
| `POST` | `/v1/nodes/{id}/submissions` | Planned | node scope | Submit node payload. |
| `POST` | `/v1/node-submissions/{id}/accept` | Planned | admin | Accept submission. |
| `POST` | `/v1/node-submissions/{id}/reject` | Planned | admin | Reject submission. |
| `POST` | `/v1/nodes/{id}/revoke` | Planned | admin | Revoke node. |

### Jobs

Purpose: track async work for upload, ingestion, indexing, prompt runtime, and MCP tool calls.

| Method | Path | Status | Scope | Purpose |
| --- | --- | --- | --- | --- |
| `GET` | `/v1/jobs` | Planned | varies | List jobs visible to caller. |
| `GET` | `/v1/jobs/{id}` | Planned | varies | Read job status. |
| `POST` | `/v1/jobs/{id}/retry` | Planned | varies | Retry failed job if supported. |

Recommended job shape:

```json
{
  "id": 501,
  "type": "file_ingest",
  "status": "running",
  "progress": {
    "current": 3,
    "total": 7,
    "message": "Extracting text"
  },
  "result": null,
  "error": null,
  "created_at": "2026-05-19T00:00:00Z",
  "updated_at": "2026-05-19T00:01:00Z"
}
```

## MCP Roadmap

MaluDB Edge should expose an MCP server facade that uses the same handlers and authorization checks as REST.

Initial transport:

```text
POST /mcp
POST /v1/mcp
```

Future local transport:

```text
bin/maludb-edge-mcp
```

### MCP Authentication

Hosted MCP calls should authenticate with the same API key model:

```http
Authorization: Bearer malu_...
```

Tool calls should be audited as MCP operations, including:

- Tool name.
- User ID.
- API key ID.
- Tenant ID.
- Status.
- Duration.
- Error metadata.

### Planned MCP Tools

| Tool | REST equivalent | Scope | Purpose |
| --- | --- | --- | --- |
| `maludb.retrieve` | `POST /v1/retrievals` | `retrieve:read` | Natural-language retrieval. |
| `maludb.search_text` | `GET /v1/search/text` | `retrieve:read` | Text search. |
| `maludb.sql` | `POST /v1/sql/query` or `/execute` | `sql:execute` | Run SQL. |
| `maludb.sql_transaction` | `POST /v1/sql/transaction` | `sql:execute` | Run SQL transaction batch. |
| `maludb.subject_create` | `POST /v1/subjects` | `svpor:write` | Create subject. |
| `maludb.subject_list` | `GET /v1/subjects` | `svpor:read` | List subjects. |
| `maludb.subject_verbs` | `GET /v1/subjects/{id}/verbs` | `svpor:read` | List verbs for subject. |
| `maludb.verb_list` | `GET /v1/verbs` | `svpor:read` | List verbs. |
| `maludb.verb_subjects` | `GET /v1/verbs/{id}/subjects` | `svpor:read` | List subjects for verb. |
| `maludb.file_upload` | `POST /v1/files` | `files:write` | Upload source file. |
| `maludb.file_tag` | `POST /v1/files/{id}/tags` | `files:write` | Tag file. |
| `maludb.prompt_run` | `POST /v1/prompts/{id}/run` | `prompts:run` | Run saved prompt. |
| `maludb.skill_search` | `POST /v1/skills/search` | `skills:read` | Find relevant skill. |

### Planned MCP Resources

| Resource URI | Purpose |
| --- | --- |
| `maludb://files/{id}/metadata` | File metadata. |
| `maludb://source-packages/{id}` | Source package metadata. |
| `maludb://memories/{id}` | Memory detail. |
| `maludb://subjects/{id}` | Subject detail. |
| `maludb://verbs/{id}` | Verb detail. |
| `maludb://jobs/{id}` | Job status. |

### Planned MCP Prompts

MCP prompts should expose saved templates from `/v1/prompts`.

Applications that support both REST and MCP should use REST for deterministic workflows and MCP for LLM-driven tool use.

## End-to-End Workflows

### Workflow 1: Application Startup Check

1. Load `MALUDB_EDGE_BASE_URL`.
2. Load API key from secret manager.
3. Call `GET /v1/health`.
4. Call `GET /v1/version`.
5. Call `GET /v1/me`.
6. Verify expected role and scopes.
7. Cache OpenAPI from `GET /v1/openapi.json` for diagnostics.

Failure behavior:

- `401`: rotate or replace API key.
- `403`: wrong key role/scope.
- `404`: deployment does not support expected endpoint.
- `500`: Edge deployment failure.

### Workflow 2: Upload Source Document and Ingest

Status: planned.

1. Upload file to `POST /v1/files`.
2. Store returned `file_id` locally.
3. Add file tags if not supplied during upload.
4. Create source package using `POST /v1/source-packages`.
5. Create claims, facts, memories, or episodes referencing the source package.
6. If operation is async, poll `/v1/jobs/{id}`.
7. Store returned MaluDB IDs in the application.

### Workflow 3: Natural-Language Memory Search

Status: planned.

1. Collect user prompt.
2. Add optional filters: `memory_pool_id`, `subject`, `verb`, `predicate`, `link_depth`.
3. Call `POST /v1/retrievals`.
4. Render results grouped by object type.
5. Use related IDs to fetch detail or file metadata.

Example request:

```json
{
  "prompt": "What did we decide about invoice retry policy?",
  "subject": "invoice_retry",
  "verb": "requires",
  "link_depth": 1,
  "limit": 10
}
```

### Workflow 4: SVPOR Exploration UI

Status: planned.

1. List subjects with `GET /v1/subjects`.
2. User selects a subject.
3. Fetch verbs with `GET /v1/subjects/{id}/verbs`.
4. User selects a verb.
5. Fetch claims/facts with `/v1/svpor/claims` and `/v1/svpor/facts`.
6. Fetch files with `/v1/subjects/{id}/files`.

This workflow is useful for graph browsers, research tools, and evidence-review applications.

### Workflow 5: Skill Search and Prompt Run

Status: planned.

1. Call `POST /v1/skills/search` with natural-language task description.
2. Present matching skills to user or LLM agent.
3. Retrieve relevant memories or facts with `POST /v1/retrievals`.
4. Run saved prompt using `POST /v1/prompts/{id}/run`.
5. Store response ID or model request ID.

### Workflow 6: SQL Stored Function Call

Status: planned.

1. Use a key with `sql:execute`.
2. Call `POST /v1/sql/functions/{schema}/{function}`.
3. Pass named arguments.
4. Handle database permission errors as `403` or safe SQL error responses.
5. Store only business result, not raw SQL, in client logs.

### Workflow 7: LLM Agent Through MCP

Status: planned.

1. Configure MCP client with Edge MCP URL.
2. Authenticate with API key.
3. Let client discover tools/resources/prompts.
4. LLM calls `maludb.retrieve`, `maludb.skill_search`, `maludb.prompt_run`, or `maludb.sql`.
5. Edge records audit metadata using the same auth context as REST.

## Application Client Recommendations

### HTTP Client Behavior

Recommended defaults:

- Timeout: 10 seconds for read operations.
- Timeout: 60 seconds for uploads or prompt runs unless async is used.
- Retries: only for idempotent `GET` requests and explicitly idempotent `POST` requests.
- Backoff: exponential with jitter.
- Maximum retries: 2 or 3.

Do not automatically retry:

- SQL mutation requests.
- Prompt runs without an idempotency key.
- File upload completion after an uncertain network failure unless idempotency is implemented.

### Logging

Log:

- Method and path.
- Status code.
- Error code.
- Request duration.
- Job ID.
- MaluDB object ID.

Do not log:

- API keys.
- `Authorization` header.
- Tenant DSN.
- Uploaded document content.
- Model prompt content unless application policy permits it.
- Full SQL text unless application policy permits it.

### Local Object References

Applications should store these IDs separately:

| ID type | Meaning |
| --- | --- |
| Edge `file_id` | Local archived source file metadata. |
| MaluDB `source_package_id` | Source package in target MaluDB database. |
| MaluDB `claim_id` | Claim object. |
| MaluDB `fact_id` | Fact object. |
| MaluDB `memory_id` | Memory object. |
| MaluDB `episode_id` | Episode object. |
| Edge `job_id` | Async operation status. |
| Edge `model_request_id` | Prompt/model execution tracking. |

Avoid conflating Edge local metadata IDs with MaluDB core IDs.

## Implementation Roadmap

### Phase 0: Foundation

Status: implemented.

Delivered:

- Composer autoloading and test harness.
- Config loading.
- SQLite metadata database.
- Migrations.
- API-key crypto and verification.
- Auth context and service.
- Request, response, and router.
- Health/version/docs/OpenAPI routes.
- CLI migration/admin bootstrap.
- `/v1/me`.
- README setup docs.

Next hardening:

- Install `pdo_sqlite` in system PHP so `composer test` works without extension flags.
- Move or delete the untracked `html/test-connection.php` after credential rotation.
- Ensure Apache `AllowOverride FileInfo` or equivalent vhost rules are active.

### Phase 1: Tenant Connection and Admin API

Goal: make tenant resolution available to all future endpoints.

Deliverables:

- Tenant repository with decrypt-on-use behavior.
- MaluDB client factory.
- Admin REST endpoints for users, tenants, and API keys.
- Request audit event insertion.
- First full OpenAPI schemas.

Acceptance criteria:

- Admin can create a user through REST.
- Admin can create and revoke an API key through REST.
- A service key can call `/v1/me`.
- Revoked keys fail immediately.
- Tenant secrets are never returned by REST.

### Phase 2: Driver-Backed Core MaluDB Endpoints

Goal: expose the main MaluDB PHP driver methods.

Deliverables:

- Source packages.
- Claims.
- Facts.
- Memories.
- Episodes.
- Text search.
- Retrievals.
- Episode replay.

Acceptance criteria:

- Integration tests run against a test MaluDB database.
- Driver exceptions map to safe JSON errors.
- OpenAPI examples match tested request/response shapes.

### Phase 3: File Archive and SVPOR

Goal: support uploaded source documents and structured relationship browsing.

Deliverables:

- File upload, list, metadata, download, delete, tags.
- Subject create/list/get/update.
- Verb create/list/get/update.
- Predicate create/list/get/update.
- Subject-to-verb browsing.
- Verb-to-subject browsing.
- Subject-to-file browsing.
- SVPOR claims/facts filters.

Acceptance criteria:

- Files are stored outside docroot.
- Downloads require `files:read`.
- SVPOR read/write scopes are enforced.
- Subject file listing combines Edge file tags and MaluDB relationships where available.

### Phase 4: Pools, Skills, and Retrieval UX

Goal: support application workflows around active memory and skills.

Deliverables:

- Active memory pool CRUD/actions.
- Pool observations and references.
- Pool member promotion.
- Skill CRUD.
- Skill search.
- Skill execution state tracking.
- Better retrieval response shaping and link-depth handling.

Acceptance criteria:

- Natural-language retrieval can narrow by pool and SVPOR filters.
- Skill search works from natural-language prompts.
- Pool state transitions are validated.

### Phase 5: Prompts, LLM Runtime, SQL, and MCP

Goal: expose higher-level automation and LLM access.

Deliverables:

- Prompt templates and versions.
- Prompt preview/render/run.
- Sessions and model requests.
- SQL query/execute/functions/transaction/history.
- MCP Streamable HTTP endpoint.
- MCP tools/resources/prompts discovery.

Acceptance criteria:

- Non-admin users can run SQL they have database permission to run when they hold `sql:execute`.
- SQL audit stores hashes and metadata only.
- MCP tools call the same handlers as REST.
- Prompt runs record version and model metadata.

### Phase 6: Production Hardening and Client Experience

Goal: make the API easy and safe for applications to adopt.

Deliverables:

- Full OpenAPI schemas.
- Example clients.
- Postman or Bruno collection.
- Deployment guide for Apache/Nginx.
- Request IDs and structured logs.
- Rate limiting.
- CORS configuration if browser clients need direct access.
- SDK generation or hand-written client helpers.
- Backward compatibility rules.

Acceptance criteria:

- A new app can integrate using only the public docs and an API key.
- OpenAPI validates in CI.
- Every documented endpoint has tests.
- Security deployment checklist is complete.

## Client Readiness Checklist

Use this checklist for each application that will consume MaluDB Edge.

- [ ] Base URL configured per environment.
- [ ] API key stored in secret manager.
- [ ] Startup check calls `/v1/health`, `/v1/version`, and `/v1/me`.
- [ ] Client uses `Authorization: Bearer`.
- [ ] Client parses JSON error shape.
- [ ] Client does not log API keys or raw secrets.
- [ ] Client stores Edge file IDs separately from MaluDB object IDs.
- [ ] Client handles `401`, `403`, `404`, `409`, and `500`.
- [ ] Client treats OpenAPI as live capability discovery.
- [ ] Client marks planned endpoints as unavailable until deployed.
- [ ] Upload workflows can support async jobs.
- [ ] Retrieval workflows can pass `prompt`, `memory_pool_id`, `subject`, `verb`, `predicate`, and `link_depth`.
- [ ] SQL workflows use stored functions where possible.
- [ ] MCP workflows use the same security model as REST.

## Documentation Roadmap

Future documentation should be split into these files as implementation grows:

| Document | Purpose |
| --- | --- |
| `README.md` | Install, bootstrap, and local smoke checks. |
| `docs/maludb-edge-application-integration-roadmap.md` | This roadmap and client integration guide. |
| `docs/api-auth.md` | API key, users, tenants, scopes, and rotation. |
| `docs/api-files-ingest.md` | File archive, source packages, claims, facts, memories, episodes. |
| `docs/api-svpor.md` | Subjects, verbs, predicates, SVPOR browsing, and examples. |
| `docs/api-retrieval.md` | Natural-language search, memory pools, link depth, result handling. |
| `docs/api-prompts-llm.md` | Prompts, sessions, model requests, LLM extension. |
| `docs/api-sql.md` | SQL execution, stored functions, transactions, audit rules. |
| `docs/mcp.md` | MCP transport, tools, resources, prompts, and client configuration. |
| `docs/deployment.md` | Apache/Nginx/PHP deployment, permissions, secrets, backups. |
| `docs/client-examples.md` | Curl, PHP, JavaScript, Python, and MCP examples. |

## Open Questions To Resolve During Implementation

- What exact MaluDB PHP driver methods should each driver-backed endpoint call?
- Which SVPOR registry functions are stable enough for REST wrappers?
- What is the maximum allowed `link_depth`?
- Should file deduplication be per user, per tenant, or global?
- Which prompt/model providers are available through the LLM extension in the target deployment?
- Should browser applications call Edge directly, or should they use an application backend as proxy?
- Should rate limits be per API key, per user, per IP, or per tenant?
- Should SQL statement hashes include normalized SQL or raw submitted SQL?
- Should MCP be exposed on the same host as REST or a separate host/path with separate rate limits?

## Practical Next Step

The next implementation plan should start with Phase 1:

1. Add repositories for users, tenants, API keys, and audit events.
2. Add MaluDB tenant connection resolution.
3. Add admin REST endpoints for user/key/tenant management.
4. Expand OpenAPI with schemas and examples.
5. Add integration tests for key creation, revocation, and tenant connection failure handling.

After Phase 1, client applications can manage their own service keys through REST instead of relying on CLI bootstrap.
