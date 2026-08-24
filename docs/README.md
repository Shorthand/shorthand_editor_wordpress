---
title: Documentation index
purpose: How this documentation is organised, and what it currently covers.
updated: 2026-08-25
---

# Documentation index

Internal documentation for the Shorthand plugin for WordPress. Product and
install documentation is in `README.md`; release process is in
`DEVELOPMENT.md`; PHP coding conventions are in `php/AGENTS.md`.

## Organisation

Each document covers exactly one of four kinds of subject.

| Directory | Kind of subject |
| --- | --- |
| `docs/services/` | Cross-cutting horizontal services, used by many callers |
| `docs/flows/` | End-to-end vertical call chains, one path from entry to result |
| `docs/lifecycles/` | State machines, one entity moving between states |
| `docs/models/` | Data models: what is stored, where, and in what shape |

Key decisions are recorded in the document that owns the subject, under a
heading beginning `Decision:`. Justification is given only where the decision
would otherwise look arbitrary or be reversed by accident.

## Contents

Services:

- `docs/services/file-system.md` — writing into the uploads directory on any
  host, disk or object store.

Flows:

- `docs/flows/publishing.md` — Shorthand story archive to rendered WordPress
  post.

Data:

- `docs/models/story-post-meta.md` — the post meta a story post carries.
- `docs/models/options.md` — every WordPress option the plugin owns.

## Not yet written

These subjects belong in this structure but have no document yet:

- `docs/flows/` — story templating, from `story_head` and `story_body` through
  `Shorthand\Services\StoryContentTransformer` and the
  `theshed_post_process_body` and `theshed_post_process_head` filters to the
  rendered page.
- `docs/lifecycles/` — a story post through creation, editing, publishing, and
  deletion.
- `docs/lifecycles/` — authorisation, the `shorthand_auth_state` state machine
  and key rotation.
