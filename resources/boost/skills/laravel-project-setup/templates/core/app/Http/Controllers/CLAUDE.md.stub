# CLAUDE.md — app/Http/Controllers

Scope: `app/Http/Controllers/`. The design standard is `app/CLAUDE.md`;
project-wide facts and the frontend rules are in the root `CLAUDE.md`.

A controller action is HTTP translation ONLY — target ≤ ~20 lines:

1. Authorize first (`$this->authorize()` / `Gate`), where the surface has
   authorization at all. Public read-only pages have nothing to authorize;
   token-authenticated API routes authenticate in middleware, not by policy.
2. ALL validation in the endpoint's own FormRequest in `app/Requests/` — never
   inline `$request->validate()` or `Validator::make`.
3. Delegate the work: model method / domain service / `app/Contracts/` client /
   ListQuery.
<!-- if:htmx -->
4. Return one of: a Blade view (full page or htmx fragment via the shared base
   helper — never an inline `HX-Request` check), a Resource from
   `app/Resources/` (`{data, meta}`) for JSON, or a redirect (`HX-Redirect` for
   htmx callers). Raw `response()->json()` only for non-entity aggregates,
   never for models.
<!-- endif -->
<!-- unless:htmx -->
4. Return one of: a Resource from `app/Resources/` (`{data, meta}`) for JSON,
   or a redirect. Raw `response()->json()` only for non-entity aggregates,
   never for models.
<!-- endif -->

Never own in a controller what `app/CLAUDE.md` places below it: transaction
boundaries, status writes, derived business computation (private helpers
computing values are the tell), event assembly, hand-rolled export plumbing.

- Every `index()` delegates to `Services/{Resource}ListQuery::build(array): Builder`
  — the same instance backs any export. Eager-load what the view or Resource
  needs there, so the template never triggers N+1.
<!-- if:htmx -->
- **Know which surface the action serves** (`routes/CLAUDE.md`); they have
  different trust levels, and a fragment endpoint is still an endpoint — it
  gets the same auth, policy and validation as the full page.
<!-- endif -->
<!-- unless:htmx -->
- **Know which surface the action serves** (`routes/CLAUDE.md`); they have
  different trust levels.
<!-- endif -->
- **Scope to the authenticated user from `$request->user()`**, never from a
  path or body parameter naming the user. A request that names its own owner id
  is a cross-user read waiting to happen.
<!-- if:htmx -->
- Session flashes for htmx callers do not render on the next page — surface
  them through the fragment (or a dedicated toast partial with `HX-Trigger`),
  not `back()->with()`.
<!-- endif -->
