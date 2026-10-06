# Cutting cards

A card buys a planner and a worker, each with its bootstrap (instructions, `CLAUDE.md` files, skill), a clone and a
Docker stack, a review by a third agent, a `refresh` and a `finish` with their merges. That cost is the same for a
five-minute card and a two-hour one, so a card is sized to what one worker finishes in one session, and no smaller.

## The rules

- **One area, one open card.** Every card carries an `area:*` label (`promote` refuses one without). Cards sharing an
  area never run at once, so splitting work on one area buys nothing but cost. Name areas after surfaces or
  directories (`area:auth`, `area:catalogue-import`, `area:public-pages`), never after old boards or epics (an epic is a finite goal; `--epic=` on the card), and keep
  enough of them for every slot of `max_parallel`.
- **No enabler cards.** Groundwork (a component set, a shared table, a helper) is part of the first card that needs
  it. A card only others wait on is folded into them.
- **Split only to run in parallel**, on different areas, when each part is worth an agent on its own.
- **A shared contract** that several areas use (a type, a validation rule, an enum, an event) is one small card on its
  own area, done first; the cards that use it depend on it.
- **An open question on part of a card** moves that part to its own narrow card, so the rest can start.
- **Criteria are grouped per surface**: one criterion can name several pages or endpoints when one test shows them all.

## Signs a board is split too small

- `hubs:` in the brief, or the `hint: … blocks N open cards` line from `new` and `set`.
- `hint: area:x already has an open card` when creating one.
- Ready holds several cards and `next` starts one (`next -v`: they share an area).
- Cards of one or two criteria that change the same files.
- Discovered items that repeat an open card's title, or a criterion already done elsewhere.

## Folding

```shell
vendor/bin/kanban fold ACME-B7Q2 ACME-C9X4 --into=ACME-A1K8
```

The cards to fold and the one that takes them are in backlog or ready. The bodies arrive as `## Folded from` sections,
the criteria are appended, labels and dependencies are joined, and the higher priority wins. The folded cards are
dropped ("folded into ACME-A1K8"), and every card that waited on them now waits on ACME-A1K8. Read the result with
`show` and reword the criteria that now say the same thing twice.

## Example

The owner asks for the public pages to follow a decided design. Split by page, that is one design-primitives card
and thirteen page cards of one or two criteria each, all on the same components: thirteen cards wait on one, and none
of them can run beside another.

Planned for agents instead:

| Card | Area | Criteria |
|---|---|---|
| Design primitives and the page frame | `area:design-system` | the primitives, the header and footer, each with a browser spec |
| Home and index pages in the design | `area:public-browse` | home sections, A–Z index, category pages, one spec per page |
| Detail pages in the design | `area:public-detail` | template and guide pages, the account and 404 pages |

The second and third cards depend on the first and then run side by side: three agents, three reviews, and no card
that exists only to unblock another.
