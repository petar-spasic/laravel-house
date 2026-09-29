# CLAUDE.md — app/Resources (API Resources)

Scope: `app/Resources/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

One exposed entity ⇒ one `JsonResource` shaping EXACTLY the fields the API
exposes. **The Resource is the sensitive-data leak boundary** — a raw model
return leaks hidden columns (tokens, secrets, internal flags). Resources live in
`app/Resources/`, not `app/Http/Resources/`.

<!-- if:htmx -->
This app is server-rendered (Blade + htmx), so Resources are for the JSON
surfaces only: an API if one exists, and any JSON embedded in a page for the
client (`@json(new XResource($m))`, never `@json($m)`).
<!-- endif -->
<!-- if:spa -->
Every response the SPA reads is a Resource — and so is every machine-API
response, if one exists.
<!-- endif -->

- Responses are `{data, meta}`; collections and pagination go through the
  Resource too.
- Where the same entity has a public shape and an owner/operator shape, they
  are **two Resources** (`PublicXResource` / `XResource`) — never one Resource
  with `when()` branches on the viewer, which is one missed branch from a
  leak.
- Relations via `whenLoaded()`, with the ListQuery eager-loading what the
  Resource needs. A Resource that lazy-loads per row IS the N+1.
- No `::query()` inside `toArray()`.
- Enum-cast columns are emitted as `->status->value`; timestamps as ISO 8601
  with offset (`->toIso8601String()`).

Leak proofs: an E2E test of the endpoint asserts sensitive fields are ABSENT from the payload (`tests/CLAUDE.md`).
