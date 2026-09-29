# Decisions

Owner decisions, newest first. One line each: date, the decision, why. A decision here is
final until a later entry replaces it; ideas that are not yet decided live in
`docs/ideas.md`.

- **2026-09-28 — A workspace has exactly one owner.** Members join by invite link; the owner can hand the workspace over but cannot leave it without doing so. Why: billing and deletion need one accountable person.
- **2026-09-27 — Search is a Postgres full-text index, not a separate engine.** Titles weigh above bodies (`setweight` A and B) and results never cross workspaces. Why (owner): one datastore to run, and the volumes stay far below where it stops being enough.
- **2026-09-26 — Sync is last-write-wins per note, and the losing edit is kept as a conflict copy.** The copy is a sibling note titled "(conflict)", so nothing is silently lost. Why: real-time co-editing needs a CRDT, which is a second product.
- **2026-09-24 — The free plan keeps 50 notes; paid plans are unlimited.** The count is per workspace and archived notes do not count. Why: only teams that already rely on the app should feel the limit.
- **2026-09-22 — Notes are stored as Markdown.** Rendering happens on the client; the server parses it only to extract search text. Why: plain text exports cleanly and diffs well.
- **2026-09-20 — Mobile apps wait until the web app is stable.** Why: two clients at once would halve the pace of both.
- **2026-09-18 — Attachments go to object storage behind signed URLs, capped at 25 MB.** The database keeps only the key, size and checksum. Why: attachments grow fastest, and the database must stay small enough to back up in seconds.
- **2026-09-15 — Acme Notes is a note-taking app for small teams.** Web first, then mobile; sign-in with email or a Google or GitHub account. Why: the founders' own itch.
