# CLAUDE.md — app/Requests (FormRequests)

Scope: `app/Requests/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

One endpoint ⇒ one FormRequest carrying ALL of its rules. Inline
`$request->validate()` / `Validator::make` in a controller is banned. Requests
live in `app/Requests/` — **not** `app/Http/Requests/` (this app is flat by
layer; a second home would fragment them).

- `authorize()` returns the authentication check and nothing more — resource
  authorization belongs to the policy, never split across both.
- **Server-owned columns get `['prohibited']`** — `user_id` / owner ids (the
  owner comes from `$request->user()`, never the body), plus `status`, and
  anything else the server computes. This pairs with the model's non-fillable
  column: belt and braces.
- Enum-valued fields validate via `Rule::enum(X::class)` — never a hand-typed
  `in:` list that can drift from the enum.
- Free text is validated for **size and shape only**, never content-filtered
  (`app/CLAUDE.md`). Bound everything a user can post: `max:` on every string
  and array, size + mime on uploads. Unbounded input is an easy way to fill the
  database.
<!-- if:htmx -->
- htmx callers get the same 422 as everyone else; the controller re-renders the
  form fragment with `$errors` (root `CLAUDE.md`). A FormRequest never decides
  the response shape.
<!-- endif -->
<!-- if:spa -->
- FormRequests are also the single source of truth for the frontend — the
  exported validation descriptors are generated from them, and the SPA turns
  them into runtime Zod schemas (`frontend/CLAUDE.md`, Validation), so a rule
  that bypasses a FormRequest silently never reaches the SPA. Add/adjust
  validation in the FormRequest and re-export — never hand-maintain the
  frontend rules. A FormRequest never decides the response shape.
<!-- endif -->
