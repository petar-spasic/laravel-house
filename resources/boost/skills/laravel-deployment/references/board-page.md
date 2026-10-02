# The board page from the local stack

The house serves the board at `/kanban`. The page syncs from the app container: the php-fpm worker `exec()`s
`kanban sync --background` with the clone's deploy key.

- The key is `.git/laravel-house/deploy_key`. `GIT_SSH_COMMAND` in the local compose names it. A worktree stack
  mounts no main `.git` and gets the variable empty, so only main's stack syncs.
- Sync needs an ssh `origin`, and a repository admin must register the key's public half with write access.
- An https `origin` needs a credential helper inside the container. The house image has none, so the sync prints
  `fetch failed: … could not read Username`.

## Diagnose

Run the sync as the compose user, never `-u root`:

```shell
docker compose -f docker-compose.local.yml exec app vendor/bin/kanban sync
```

| Output | Cause |
|---|---|
| `Permission denied (publickey…)` | The key is not registered yet. |
| `sync: no remote configured`, although the host has an origin | git refuses `/app` as dubious ownership, because the process uid does not own the checkout: set `HOST_UID`/`HOST_GID` in `.env` and rebuild. Or `git` is off the PATH. |
| `sync: up to date`, yet the page never updates | `exec()` is disabled, `php` is off the php-fpm worker's PATH (the CLI has its own), or `.git/laravel-house` is not writable. |
| `N UI writes are saved but not committed yet` | `.git` is not writable for the worker, or `git` is off its PATH. |

- Run `doctor` and `attach` on the host only: in the container they see host paths.
- A write from the page names no one unless `KANBAN_USER` is in `.env`.
- The `kanban` skill's gotchas own everything else.
