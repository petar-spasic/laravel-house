# CLAUDE.md — app/Contracts

Scope: `app/Contracts/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

`Contracts/` interfaces are the seams between this app and everything outside
it. Every external system gets one, and the interface exists so a
flow can be run end to end with only the outside system faked (`tests/CLAUDE.md`).

- The pattern: `Contracts/{X}Client.php` interface, implemented by
  `Services/{X}Client.php`, bound in a service provider. Consumers depend on the
  interface, never the implementation.
- Keep contracts **workflow-shaped and minimal** — a client is a port, not a
  generic HTTP wrapper. Don't add speculative methods.
- Read-only unless the workflow needs a write, and then write verbs are explicit
  and few. A contract method that would delete, pay, send, or execute something
  remotely is a deliberate decision, not a missing feature — name it for the
  workflow and document what it may touch.
- Contract methods that return externally-authored text must document that the
  text is **untrusted**.
- Adding or changing a contract method changes what the app can do to the
  outside world — update the governing `CLAUDE.md` in the SAME commit if it
  changes a surface.
