# Dynamic content in our WordPress plugin — handoff notes

**Source:** Google Drive, `Dynamic Content in Our WordPress Plugin — Handoff Notes.md`, signed 2026-09-11.
**Status:** Corrected on 2026-09-25. The original assumed placeholders are HTML elements carrying a class or ID, read with DOMDocument at publish time. They are text tokens in mustache form. The untouched Drive copy is kept at `docs/research/superseded/2026-09-11-dynamic-content-handoff-element-placeholders.md`.
**Specification:** `docs/models/story-placeholders.md`.

## The decision

Stories carry dynamic content — title, publish date, taxonomy terms, post meta — that updates without the story being republished. Our API generates the story HTML once at publish. At render, the plugin scans that HTML for mustache tokens and substitutes the current value of each. The placeholder path parses no HTML, at publish or at render.

## What a placeholder is

A placeholder is a text token written into the story HTML: `{{wp.title}}`, `{{wp.author.name}}`, `{{wp.featured_image.url}}`. Lists use sections — `{{#wp.categories}}…{{/wp.categories}}` — which repeat their body once per term. Nothing about the markup around a token identifies it. The token identifies itself.

A token is claimed only when its path starts with `wp.`, `this.` or `@`. Every other `{{ }}` in the story is left alone, so a story that uses mustache for its own purpose keeps working.

## Why string substitution fits WordPress

WordPress thinks in strings. Its main content filter passes HTML around as a string. The functions that produce a live value — the image for a media item, a caption, a list of taxonomy terms — hand back ready-made HTML strings, not structured objects. Splicing a string into a string goes with the grain of the platform.

Tokens make that fit exact. The unit being replaced is already a string, so there is no document to load and no element to swap.

## How the dynamic part works

1. `Shorthand\Services\PostAPI::extract_story_content()` stores the story HTML in the `story_head` and `story_body` post meta, tokens and all, unchanged.
2. At render, the resolver scans the stored HTML, matches each claimed token to a field, reads the field's current value from WordPress, escapes it by type, and splices it in.
3. The stored HTML never changes until the story is republished. A retitled post or an edited term shows up on the next render.

Resolution points, from the specification:

| Path | Resolved in |
| --- | --- |
| Story body | `Shorthand\Services\StoryKses::echo_extract_and_enqueue_assets()` |
| Story head | `Shorthand\Plugin\Templates::single_head()` |
| Live preview | The same two, unchanged |

In the body the resolver runs after `Shorthand\Services\StoryAssetParser` has pulled out `<script>` and `<style>` blocks, so no WordPress value can ever be written into JavaScript.

## What the token form removes

The element form needed a second DOMDocument parse at publish, one dedicated to finding placeholders. The token form needs none: scanning for `{{` is a string operation.

It does not remove the DOM dependency, because the plugin already has one. `Shorthand\Services\StoryTextExtractor::extract()` parses the story body at publish to produce the search text, prepends the UTF-8 `Content-Type` meta that stops libxml assuming Latin-1, and returns empty strings when `DOMDocument` is absent. The encoding hint and the missing-extension guard the original notes asked for are already written and already tested. What the token form avoids is extending that dependency onto the placeholder path, where a failure would mean a story rendering with raw tokens in it rather than a story with no search text.

It also removes the map. The element form had to record, in post meta, what each element pointed at, because the markup alone did not say. A token says what it points at.

## Where elements would have won, and why tokens still win

Elements are the better tool when a placeholder expands into nested markup — a bare `<img>` becoming a full captioned figure. Tokens cover that with sections instead: the story author writes the figure markup once inside `{{#wp.featured_image}}…{{/wp.featured_image}}`, and the section renders its body when a thumbnail exists and nothing when it does not. The markup stays in the story, where a designer can see and change it, rather than in PHP.

## Notes for whoever picks this up

Escape by field type and by position. String substitution does not check that what goes in is valid HTML. A token in an attribute (`alt="{{wp.featured_image.alt}}"`) needs `esc_attr()`; a URL needs `esc_url()`; rich text needs `wp_kses_post()`. The specification fixes a type per field so this is not a per-site judgement.

Strip tokens from the search text. `Shorthand\Services\StoryTextExtractor` writes to `post_content` and `post_excerpt`, which feed core search. A raw `{{wp.title}}` in a search result is noise.

---

Signed, Claude, on Friday, September 11, 2026. Corrected Thursday, September 25, 2026.
