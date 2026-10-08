# Dynamic content in our WordPress plugin — handoff notes

## The decision

We can give our stories dynamic content injection — captions, media, and taxonomy tags that update on their own, independently of the post. It works, and it's reliable. The approach: our API generates the story HTML once at publish time, and at render we fill in the live values using simple string substitution. That's the whole idea in one line. Everything below explains why this works and why we chose it.

## Why this is safe and fast

String substitution means we treat the stored HTML as plain text and splice the live values into it at render. It's cheap — a fraction of a millisecond — because there's no parsing involved. This matters under traffic, where every millisecond per render multiplies across many requests.

## Why string substitution fits WordPress

WordPress itself thinks in strings. Its main content filter passes HTML around as a string. And the functions we'd call to get a live value — the image for a media item, a caption, or a list of taxonomy terms — all hand back ready-made HTML strings, not structured objects. So splicing a string into a string goes with the grain of the platform, not against it.

## How the dynamic part actually works

When a story is published, our API returns a full HTML page with placeholders where the dynamic content goes. Those placeholders are plain elements — a span, a div, or an image — each carrying an identifying class or ID. At publish time we read the page once and build a small map, stored in the post's meta, that records what each placeholder points to: this one is a taxonomy term, that one is a media item. At render time we look up each entry against its own live source and drop the current value in. The story itself stays fixed until it's republished, and a change to a taxonomy term or a swapped media file refreshes only its own value — never the whole story.

## Why we parse once, not on every load

Reading the HTML into a document to work with its structure is fast for a single page — a few milliseconds. But doing it on every page load repeats that work for no reason. So we do the structural work once, at publish, and cache the result. The common practice for that caching in WordPress is the transients API, or an object cache, refreshed only when the source content changes.

## The alternative we didn't lead with — DOM parsing

There's a heavier approach where, instead of treating the HTML as text, we keep it as a parsed document and swap out actual elements. It's structurally safer and it's the right tool when a replacement is genuinely nested — for example, expanding a placeholder into a full captioned figure. The cost is a parse-and-serialize cycle on each render, roughly one to five milliseconds. Our recommendation is to use it only for that publish-time structural work, and keep string substitution on the render path.

## The parser behind all this

The structural work relies on PHP's built-in DOM extension, which gives us the DOMDocument tool. It's part of PHP's core, it's been there since PHP 5, and it's present on WordPress VIP, which runs full PHP builds. So on our target platform there's nothing to install.

## Two smaller notes for whoever picks this up

First, encoding: DOMDocument assumes an older character set by default, so UTF-8 content needs an encoding hint to avoid mangled characters — and our story API already includes that hint. Second, portability: if this ever ships to environments we don't control, the DOM extension can technically be disabled on stripped-down or hardened servers, so a quick check for the DOMDocument tool on load — with a friendly admin notice if it's missing — is worth adding. On WordPress VIP it's not necessary.

---

Signed, Claude, on Friday, September 11, 2026.
