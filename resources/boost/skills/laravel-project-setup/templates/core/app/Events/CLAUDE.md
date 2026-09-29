# CLAUDE.md — app/Events

Scope: `app/Events/`. The design standard is `app/CLAUDE.md`; project-wide
facts are in the root `CLAUDE.md`.

`Events/` holds domain events raised inside the app — something was created,
transitioned, failed, or observed.

- Wiring is one `Event::listen` line per listener (`app/Providers/CLAUDE.md`);
  a new event without one silently never reaches its listeners — run the flow to
  see it arrive.
- Payloads carry **ids and the changed fields, not whole models** — a serialized
  model in a queued payload goes stale between dispatch and handling.
- The layer that performs the state change dispatches its own events. A
  controller hand-assembling an event is doing the model's or service's job.

<!-- if:reverb -->
## Broadcasting (Reverb)

The default broadcaster is **Reverb** (`BROADCAST_CONNECTION=reverb`). Events
implementing `ShouldBroadcast` are pushed to private channels and consumed by
the frontend over WebSocket.

- **Payloads carry only safe ids + scalars** — the client refetches what it
  needs through the ordinary Resource-backed endpoints; "live" is
  refetch-on-event.
- Channel auth (`routes/channels.php`) mirrors the policy that gates the
  record — never a looser check.
- A broadcast fires AFTER commit, never from inside the transaction that made
  the change.
- **Reverb MUST be running in every non-local environment.** Without it,
  `ShouldBroadcast` dispatches silently fail to reach connected clients (Laravel
  still queues to the configured driver — there is no exception). Its listen,
  connect and browser addresses: root `CLAUDE.md`, Hosting.

<!-- endif -->
## Inbound webhooks are NOT these events

If the project receives webhooks from external systems, those payloads are
**untrusted input**, and they are accelerators, never the record:

- Verify the signature **before parsing** (middleware, `app/Http/Middleware/CLAUDE.md`).
- Delivery is at-least-once with retries and drops. A webhook must never be the
  sole trigger for a critical state transition — a scheduled reconciliation
  remains the floor.
- Persist the raw event (cheaply), then dispatch a job; the receiver does no
  work inline.
- High-volume event streams belong in a log store, not in the primary
  database — keep only aggregates in Postgres.
