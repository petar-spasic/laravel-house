# Kanban board ({{key}})

This is the `kanban` branch of the project: the plan of record, managed by
[petar-spasic/laravel-house](https://github.com/petar-spasic/laravel-house). It is checked out at `docs/kanban` of the
main checkout and never merges into `main`.

- `kanban.json` — board settings; `<board>/board.json` (one board, `work`) and one `<board>/<ID>.json` per card;
  `_epics/<epic>.json` for each epic a card names in its `epic` field.
- `decisions.md` — the owner's decisions, read only: `fold-boards` and `import-house-docs` write it.
- Edit through `vendor/bin/kanban …` or the `/kanban` UI only: every write is validated and committed here.
- A merge driver (`merge=kanban` in `.gitattributes`) merges cards field by field; `vendor/bin/kanban attach`
  configures it on each machine.
