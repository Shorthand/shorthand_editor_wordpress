# Caching the story render — handoff notes

## The decision

We cache in two separate layers, with two separate lifetimes. The story's HTML shell is built once when the story is published and cached until it's republished. The live values inside it — captions, media, taxonomy terms — are cached individually and refresh on their own. Nothing re-parses HTML on a page load.

## Why two layers and not one

If we cached the finished page as a single blob, we'd freeze the dynamic values into it. A taxonomy edit or a swapped image wouldn't show up until the story was republished. Splitting the cache keeps the expensive part stable and the changeable part fresh.

## Layer one — the story shell

At publish time we read the HTML our API returns, find the placeholder elements by their class or ID, and store two things: the shell itself, and a small map recording what each placeholder points to. The map lives in the post's meta. Both stay untouched until the story is republished. This is the only point at which we parse HTML.

## Layer two — the live values

At render we walk the map and fetch each current value from WordPress — the image for a media item, a caption, a list of terms. Each of those lookups is cached on its own, using the transients API or an object cache. Then we splice the values into the shell as plain text.

## How things expire

Republishing a story rebuilds its shell and its map. Editing a taxonomy term or swapping a media file clears only that one cached lookup — every other story using it picks up the change on its next render, and no story shell is rebuilt.

## What this costs on a page load

The render path is a map lookup plus string splicing: fractions of a millisecond. The parse we avoided would have been roughly one to five milliseconds per render. That's imperceptible for one visitor, but it's the difference that matters once many requests arrive at once.

## Compatibility with existing stories

*Proposed, not yet agreed.*

Nothing about this changes how an existing story renders. A story published before this system has no map in its post meta, so the render path finds nothing to substitute and hands back the stored HTML untouched — exactly what happens today. That means we can ship the code before any story has been migrated, and no page breaks in the meantime. The map's presence is the switch: no map, old behaviour; map present, live values.

Two kinds of old story exist, and they behave differently. Ones whose HTML already carries the placeholder classes or IDs can be migrated as-is, because everything the map needs is already in the markup. Ones published before our API emitted placeholders have nothing to point at, so they can't be migrated by parsing alone — they need to be regenerated from the story API to pick up placeholders, or left as static content indefinitely, which is a perfectly acceptable outcome.

## How migration happens

*Proposed, not yet agreed.*

The natural route is lazy: the first time an unmigrated story renders, we check for a map, find none, parse the stored HTML once, build the map, and cache it. From then on that story is on the new path. Nothing needs scheduling, and stories migrate in order of how often they're actually read — popular ones first, dormant ones never, which is the right allocation of work.

The one thing to watch is that the migrating render pays the parse cost, so a burst of cold stories hitting at once does more work than usual. If that's a concern, a WP-CLI command doing the same backfill across all stories ahead of time removes the cold path entirely. Republishing a story also does it, since republish rebuilds the shell and map anyway.

## A note for whoever picks this up

Any value we splice in must be escaped properly, because string substitution doesn't check that what we insert is valid HTML. The values from WordPress's own functions arrive ready-made and safe; anything we build ourselves needs that care.

---

Signed, Claude, on Thursday, September 24, 2026.
