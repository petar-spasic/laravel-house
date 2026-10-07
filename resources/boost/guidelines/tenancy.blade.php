@houserules('tenancy')
@php($house = \PetarSpasic\LaravelHouse\Setup\HouseConfig::read(base_path()))
## Tenancy

Row-level multi-tenancy. These rules bind every layer; the layer files hold the detail.

1. **Row-level only.** One database; every tenant-owned row has a non-null `tenant_id`. No tenancy package, no
   subdomain, no database or schema per tenant.
2. **The tenant is never in the URL.** It comes from the session or the Sanctum token.
   - Session: set at login; a user in several tenants changes it with the switcher (`routes/CLAUDE.md`).
   - Token: each token belongs to one tenant; a client needing several holds one token per tenant.
   - No path segment, subdomain, query key or body field selects it; the switcher's `tenant` field only asks to
     change it.
3. **Enforced twice:** the Eloquent `BelongsToTenant` scope, plus Postgres row-level security (RLS) on every
   tenant-owned table. The app connects as `{{ $house->vars['app'] }}_app`, which owns nothing and cannot bypass
   RLS; migrations run as the owner (`pgsql_owner`). Neither layer alone is enough.
4. **Another tenant's record is a 404, never a 403.**
5. **A page's requests carry the tenant it was rendered for** (`X-Tenant`). A mismatch answers 409, so a stale tab
   never writes into the new tenant.
6. **Off-request work carries the tenant in hidden Context.** Cache keys of tenant data include the tenant id. With
   no tenant set, tenant queries throw; they never return "no rows".
7. **Cross-tenant access** (a platform admin) exists only if the project decides it, as an explicit, logged act.
   Never by leaving the tenant unset.

The tenant is also connection state (`app.tenant_id`); its set and reset points are in `app/CLAUDE.md` (Octane).
@endhouserules
