# Caching the story render — handoff notes

**Source:** Google Drive, `Caching the Story Render — Handoff Notes.md`, signed 2026-09-24.
**Status:** Corrected on 2026-09-25. The original assumed placeholders are HTML elements found by a DOMDocument parse at publish, with a map in post meta recording what each one points at. They are text tokens in mustache form, and there is no map. The untouched Drive copy is kept at `docs/research/superseded/2026-09-24-caching-the-story-render-element-placeholders.md`.
**Specification:** `docs/models/story-placeholders.md`.

## The decision

Cache in two layers, with two lifetimes. The compiled story — the HTML split into literal text and token slots — is built by a token scan and cached until the story is republished. The live values that fill those slots are cached one by one and refresh on their own. Nothing parses HTML on a page load, and nothing rescans the story body on a page load.

## Why two layers and not one

Caching the finished page as a single blob freezes the dynamic values into it. A retitled post, an edited term or a swapped image would not appear until the story was republished. Two layers keep the expensive part stable and the changeable part fresh.

## Layer one — the compiled story

Compiling means one linear scan of the stored `story_body` and `story_head`, splitting each into literal segments and token slots, with sections nested. Render is then a walk over that structure: concatenate the literals, resolve the slots. The compiled form is keyed on the post ID and the `story_version` meta, so republishing a story produces a new key and the old entry falls out on its own.

**Proposed, not yet agreed.** Where the compiled form lives is the one open question. Post meta makes it durable but roughly doubles the bytes stored per story, and story bodies are large. The object cache or a transient makes it cheap to hold and cheap to lose, because a miss costs one scan rather than one parse. The token form is what makes the second option viable — with element placeholders, a miss cost a full DOM parse, so the result had to be durable.

## Layer two — the live values

At render, each token slot is resolved against WordPress: the post's title, the author, the featured image at a given size, a list of terms, a Tag Groups group. Each of those lookups is cached on its own, using the transients API or an object cache. The value is then escaped by its field type and spliced in as text.

## How things expire

Republishing a story changes `story_version`, so the compiled story is rebuilt.

Editing a taxonomy term or swapping a media file clears only that one cached lookup. Every story using it picks the change up on its next render, and no compiled story is rebuilt.

## What this costs on a page load

A warm render is a cache read plus string concatenation: fractions of a millisecond.

A cold compile is one regex pass over the story body — well under a millisecond for a typical story. The DOM parse the element form needed was roughly one to five milliseconds, and had to happen before the story could be served at all. Both are imperceptible to one visitor; the difference shows once many requests arrive together.

## Compatibility with existing stories

Existing stories render byte for byte as they do today. A story published before this system contains no claimed tokens, the scan finds nothing to substitute, and the stored HTML is handed back untouched. The switch is the presence of a token, not a flag or a stored map, so the code can ship before any story contains one.

This replaces the original note, which needed a map in post meta as the switch and had to hedge on stories whose markup predated placeholders.

## Migration

There is none, and none is needed. No backfill, no lazy first-render migration, no WP-CLI command. A story gains dynamic content when our API regenerates it with tokens in it, and not before. A story that is never regenerated stays static indefinitely, which is a correct outcome rather than a gap to close.

## A note for whoever picks this up

Any value spliced in must be escaped properly, because string substitution does not check that what goes in is valid HTML. Values from WordPress's own template functions arrive ready-made and safe. Anything built by the plugin needs that care, and a token sitting inside an attribute needs `esc_attr()` rather than the escaping its field would get in body text.

---

Signed, Claude, on Thursday, September 24, 2026. Corrected Thursday, September 25, 2026.
