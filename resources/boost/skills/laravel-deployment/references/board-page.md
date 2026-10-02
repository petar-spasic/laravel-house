# The board page from the local stack

The house serves the board at `/kanban`. The page syncs from the app container: the php-fpm worker `exec()`s
`kanban sync --background`.

- **An ssh `origin`** syncs with the clone's deploy key, `.git/laravel-house/deploy_key`. `GIT_SSH_COMMAND` in the
  local compose names it, and a repository admin must register its public half with write access.
- **An https `origin`** (a host that disables deploy keys) syncs with a token: set `KANBAN_GIT_TOKEN` in `.env` to a
  fine-grained token with read and write on this repository's contents only, then `up -d`. The local compose's
  credential helper hands it to git in the main stack's container, and only for origin's own host; no git config
  holds it. It sits in the container's environment, so scope it to this one repository.
- Only main's stack syncs: a worktree stack mounts no main `.git`, and its `.env` sets the key and the token empty.

## Diagnose

Run the sync as the compose user, never `-u root`:

```shell
docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync
```

| Output | Cause |
|---|---|
| `Permission denied (publickey…)` | The key is not registered yet. |
| `could not read Username` | An https `origin` with `KANBAN_GIT_TOKEN` unset, or set after the last `up -d`. |
| `Authentication failed` or `403` over https | The token expired, or lacks read and write on this repository's contents. |
| `sync: no remote configured`, although the host has an origin | git refuses `/app` as dubious ownership, because the process uid does not own the checkout: set `HOST_UID`/`HOST_GID` in `.env` and rebuild. Or `git` is off the PATH. |
| `sync: up to date`, yet the page never updates | `exec()` is disabled, `php` is off the php-fpm worker's PATH (the CLI has its own), or `.git/laravel-house` is not writable. |
| `N UI writes are saved but not committed yet` | `.git` is not writable for the worker, or `git` is off its PATH. |

- Run `doctor` and `attach` on the host only: in the container they see host paths.
- A write from the page names no one unless `KANBAN_USER` is in `.env`.
- The `kanban` skill's gotchas own everything else.
