---
name: infer-conventions
description: "Not used in this project: conventions live in per-directory CLAUDE.md files, never in .ai/rules or record-rule. Replaces Boost's convention-sweep skill of the same name."
disable-model-invocation: true
---

# Not used here

This project's conventions are written by hand in the `CLAUDE.md` of the directory they govern (root `CLAUDE.md`,
"Where the docs live"). Boost's `infer-conventions` sweep records into `.ai/rules` through `record-rule`, which this
project does not use — the two systems would drift.
