# CLAUDE.md — app/Policies

Scope: `app/Policies/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

Authentication is Fortify's (session) and lives in middleware; **authorization
is policies**, and every authenticated action that touches a record goes
through one.

- Register every policy with `Gate::policy(Model::class, ModelPolicy::class)` in
  `AppServiceProvider::boot()` — one visible inventory. Discovery finds only a
  policy whose name and namespace match its model; one that doesn't match
  silently never runs, and every check on that model is denied.
<!-- if:htmx -->
- Every ability maps to a named method and must actually invoke the check
  (`$this->authorize()` in the action, `@can` in Blade for affordances only —
  hiding a button is not authorization).
<!-- endif -->
<!-- unless:htmx -->
- Every ability maps to a named method and must actually invoke the check
  (`$this->authorize()` in the action — hiding a button in the frontend is not
  authorization).
<!-- endif -->
- **Never authorize by a request-supplied identifier.** The subject comes from
  `$request->user()` and the record from route-model binding; a policy that
  trusts a path or body parameter naming the owner is a cross-user read.
- Authorization lives in ONE place — no `abort(403)` beside a policy call, no
  ownership `where('user_id', …)` in a controller as a substitute for the
  policy. (Scoping a *listing* to the user is a ListQuery/scope concern and
  still goes through the model scope.)
- Deny by default: a policy method that is missing returns false. Prefer
  returning 404 over 403 for records the user must not know exist.
- Machine-facing surfaces (API tokens, webhooks) authenticate in middleware
  and scope from the verified key — a policy does not replace that.
