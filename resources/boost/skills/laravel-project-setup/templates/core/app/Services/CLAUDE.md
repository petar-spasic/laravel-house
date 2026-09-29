# CLAUDE.md — app/Services

Scope: `app/Services/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

| Kind | Naming / placement | Use for |
|------|--------------------|---------|
| List query | `Services/{Resource}ListQuery.php` — `build(array $params): Builder` (unexecuted) | Index filtering/sorting + export, allow-list defined ONCE and shared |
| Domain service | Named for the WORKFLOW (`OrderCheckout`, `InvoiceIssuer`) | Multi-model transactional workflows; owns the transaction and event dispatch |
| Client (port impl) | `Services/{X}Client.php` implementing `Contracts/{X}Client.php` | Anything talking to an external system (HTTP APIs, git, mail providers, LLMs) |

Never create `{Entity}Service` grab-bags — if a class accumulates unrelated
verbs, split it along workflows.

Services are reachable off-request (jobs, listeners, commands) —
scope from the user/entity they are HANDED, never from ambient context
(`auth()`, `request()`).

## Client rules

The contract side (one per system, write verbs, untrusted text) is
`app/Contracts/CLAUDE.md`. The implementation:

- **Bounded retries and a real timeout on everything.** An external system that
  hangs must surface as a recorded failure, not as a stuck worker.
- Credentials come from `config/services.php` (backed by `env()` there and
  only there), never from `env()` in the client.

## LLM-backed services (if the project gets one)

Put them in `Services/{Name}/` with the prompt, the structured output schema and
the context assembly together. **Default to no tools**: tool-less with
structured output means the worst case is a bad row you can filter; with tools,
injected text becomes actuation. Giving one a tool, a fetch or a shell is a
change to the security model — record it in the root `CLAUDE.md` first. Use the
SDK's fakes to iterate prompts without live calls.
