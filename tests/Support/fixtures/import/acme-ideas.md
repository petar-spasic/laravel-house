# Ideas

Things floated but not decided. When one is decided it moves to `docs/decisions.md`; when
it is dropped, say so here rather than deleting it.

| date | idea | status |
|---|---|---|
| 2026-09-27 | **Offline-first editing.** The web app keeps a local copy in the browser and syncs when the connection returns, with the last-write-wins rule settling conflicts. Why: most notes are written on trains and planes. | floated |
| 2026-09-27 | **Filter syntax in the search box.** `tag:work | tag:home` matches either tag and `-tag:old` excludes one; a plain word still searches titles and bodies. Why: power users ask for it within a week of any launch. | floated |
| 2026-09-26 | **Public share links.** A note can be shared read-only by a link that expires after 30 days unless renewed. Why: sharing with people outside the workspace should not need an account. | floated |
| 2026-09-26 | **Paste tables from spreadsheets.** A pasted range becomes a Markdown table, and a pipe inside a cell is escaped as \| so the columns stay put. Why: pasted data is how most tables get into a note. | floated |
| 2026-09-25 | **Version history per note.** Keep the last 30 saves and let the owner restore any of them; older saves are pruned nightly. Why: the conflict copy loses nothing, but people still want to undo a bad edit. | floated |
| 2026-09-24 | **Web clipper.** A browser extension that saves the selected part of a page as a note, with its address. Why: capture is where most notes start. | floated |
| 2026-09-23 | **Email a note in.** Each workspace gets an address; anything sent to it becomes a note, attachments included. Depends on the attachment storage decision. | floated |
| 2026-09-22 | **Keyboard-first navigation.** Every action reachable without the mouse, with a `?` cheat sheet. Why: accessibility, and it is how power users judge whether an app is fast. | floated |
| 2026-09-20 | **Full-text search without a search service.** Ask the database first; add a service only when a measured query needs it. Why: fewer moving parts to run. | decided 2026-09-27 (`decisions.md`, search is a Postgres index) |
| 2026-09-19 | **Built-in video calls.** Start a call from any note. Why: far from a note app, and expensive to run. | dropped 2026-09-25: out of scope for a note app |
| 2026-09-18 | **Dark mode that follows the system.** Why: nearly free with design tokens, and expected by now. | floated |
| 2026-09-16 | **Template gallery.** Meeting notes, weekly review and project brief as starting points. Why: an empty page is the biggest drop-off in the first session. | floated |
