# Cutting cards

A card buys a planner and a worker, each with its bootstrap (instructions, `CLAUDE.md` files, skill), a clone and a
Docker stack, a review by a third agent, and its merge in the queue, where the whole suite runs again on the merged
code. That cost is the same for a five-minute card and a two-hour one, so a card is sized to what one worker finishes
in one session, and no smaller.

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
- **Criteria are grouped per surface**: one criterion can name several pages or endpoints when one test proves the same
  fact on each ("The body and the criteria").

## The body and the criteria

The body is for a person, and the plan is for the agents. Agents read the body, but they follow the plan. Write the
body and the criteria close to ASD-STE100 Simplified Technical English:

- Use at most 20 words in an instruction and at most 25 words in a description.
- Put one instruction or one outcome in each sentence or criterion.
- Use the active voice and the present tense.
- Use common words, each with one meaning. Explain a term that the owner may not know.
- Put technical names in backticks (`ImportTest.php`, `/import`). Keep them exact. They do not count as words.

The body says what changes and why: the outcome that the owner sees. Files, steps, traps and commands go in the plan.

A criterion is one fact that the evaluator can check. It names the page, endpoint or command where the fact shows. It
names the test or browser spec that proves it. It never says how to build the change.

Before:

> The import should be refactored so that the CSV parser handles UTF-8 BOM and Windows line endings properly and
> errors are shown to the user in a toast instead of a 500 page.

After, as two criteria:

> An uploaded CSV with a byte-order mark and Windows line endings imports all rows (`tests/E2E/ImportTest.php`).
>
> A CSV with a bad row shows an error message on `/import`; the page does not fail (`frontend/e2e/import.spec.ts`).

`new` and `set` print a `hint:` for a body sentence or a criterion over 25 words. The hint skips code, tables and the
sections that the board writes (`## Folded from`, `## Owner answer`, the questions). It refuses nothing.

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
