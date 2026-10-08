---
title: Story placeholders — full language, parked
purpose: The complete placeholder language designed on 2026-09-12, including sections, inverted sections, scoping and loop variables. Parked in favour of a value-only subset. Kept as the reference for what a later iteration would restore.
updated: 2026-10-04
---

# Story placeholders — full language, parked

**Status:** Parked on 2026-09-28, not discarded. This document is the complete
language: value placeholders and sections, inverted sections, `this` scoping,
`{{@index}}`/`{{@first}}`/`{{@last}}`, arguments, and the author, featured
image, site and Tag Groups field families.

It was judged to go beyond the scope of the task by defining a syntax for
iterated and complex objects before one was needed. The specification of record
is now the value-only subset at `docs/models/story-placeholders.md` in the
plugin repo, on branch `test/story-placeholders` until it merges.

Nothing here is withdrawn. The subset is designed so that everything below can
be added later without changing a key an author has already written.

**Renamed since parking (2026-10-03):** the subset writes the story's own
fields as `wp.story.title`, `wp.story.date` and `wp.story.date_iso`, not
`wp.title`, `wp.date` and `wp.date_iso`. A later iteration that restores this
language adopts the subset's names, and moves every other post field below
(`wp.excerpt`, `wp.url`, `wp.modified`, and the rest) under `wp.story.` the
same way.

**Hook names (2026-10-03, revised 2026-10-04):** the subset resolves every
token through a filter named
`theshed_resolve_placeholder_{$namespace}_{$key}`, and requires every
namespace name to be lowercase letters only, `^[a-z]+$` (its section
"Namespace names"). This language's `featured_image`,
`primary_term`, `tag_group` and `tag_groups` break that rule and must be
renamed when restored. Suggested: `featured_image` to `thumbnail`, which is
the `tse_story` support name; `primary_term` to `primary`; `tag_group` and
`tag_groups` to `taggroup` and `taggroups`. The post fields that move under
`wp.story.` (`menu_order`, `comment_count`, `modified_iso`) become keys, and
keys keep their own character sets. Tokens with no key, such as
`{{wp.featured_image}}`, need a hook name rule of their own when restored.

The original text follows unaltered.

---

A Shorthand story bundle is a whole page. It carries no WordPress block
template, so nothing in it is filled in by a theme. Any WordPress value an
author wants inside the story — the post title, the author, the categories, a
Tag Groups group — has to be written into the story as a placeholder and
replaced when the page is rendered.

This document specifies that placeholder language: its grammar, its field
vocabulary, its escaping rules, and where in the render path it is resolved.

## Shape

A placeholder is a Mustache-style tag. Two forms exist.

| Form | Written | Renders |
| --- | --- | --- |
| Value | `{{wp.title}}` | The value, escaped for its type |
| Section | `{{#wp.tags}}…{{/wp.tags}}` | The body, once per item, or not at all |

Sections carry the whole language's conditional behaviour. A section over an
empty value, an empty list, or a missing image renders nothing, so the markup
around it disappears with it. An inverted section, `{{^wp.tags}}…{{/wp.tags}}`,
renders only when the value is empty.

There is no `{{#if}}`, no `{{#unless}}`, no `{{else}}`, and no `{{{triple}}}`.

## Namespace

Every placeholder begins `wp.`. The plugin claims `{{wp.…}}`, `{{#wp.…}}`,
`{{^wp.…}}` and `{{/wp.…}}`, and — inside a section body only — `{{this.…}}`,
`{{#this.…}}` and the loop variables `{{@index}}`, `{{@first}}`, `{{@last}}`.

Every other pair of braces in the story is left exactly as written. A story may
contain a code sample, a third-party embed, or a Shorthand feature that uses
`{{ }}` for its own purpose, and none of them are touched.

## Grammar

```ebnf
placeholder = "{{" , [ sigil ] , path , { argument } , "}}" ;
sigil       = "#" | "^" | "/" ;
path        = root , { "." , segment } ;
root        = "wp" | "this" | "@" , name ;
segment     = name | slug ;
name        = lower , { lower | digit | "_" } ;
slug        = lower | digit | "-" , { lower | digit | "-" } ;
argument    = space , name , "=" , quote , value , quote ;
quote       = '"' | "'" | "“" | "”" | "‘" | "’" ;
```

Before matching, the parser decodes HTML entities inside the braces and
normalises curly quotes and non-breaking spaces. A Shorthand text field may
store `&quot;` or a smart quote where the author typed `"`, and an argument
must still parse.

## Types and escaping

Each field declares one of three types. The type alone decides the escape, so
an author never chooses between a double and a triple stash.

| Type | Escaped with | Safe in |
| --- | --- | --- |
| `text` | `esc_attr()` | Element text and attribute values |
| `url` | `esc_url()` | `href`, `src`, attribute values |
| `html` | `wp_kses_post()` | Element text only |

`text` is escaped at attribute strength — quotes included — which is also valid
element text. One rule covers both contexts, so the resolver never has to work
out where in the document a placeholder sits.

Do not place an `html` field inside an attribute. The field table marks every
`html` field; each has a `text` or `url` counterpart for attribute use.

## Post fields

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.title` | text | `post_title` |
| `wp.excerpt` | text | `post_excerpt`, generated when empty |
| `wp.content` | html | `post_content`, filtered |
| `wp.url` | url | Permalink |
| `wp.id` | text | Post ID |
| `wp.slug` | text | `post_name` |
| `wp.status` | text | `post_status` |
| `wp.date` | text | Publish date, site format |
| `wp.date_iso` | text | Publish date, ISO 8601, for `datetime` |
| `wp.modified` | text | Last modified date, site format |
| `wp.modified_iso` | text | Last modified date, ISO 8601 |
| `wp.comment_count` | text | Approved comment count |
| `wp.menu_order` | text | `menu_order`, from `page-attributes` |
| `wp.parent.title` | text | Parent post title |
| `wp.parent.url` | url | Parent post permalink |

`wp.date` and `wp.modified` take `format`, a `date_i18n()` format string:
`{{wp.date format="j F Y"}}`.

## Author fields

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.author.name` | text | Display name |
| `wp.author.first_name` | text | First name |
| `wp.author.last_name` | text | Last name |
| `wp.author.url` | url | Author archive |
| `wp.author.website` | url | The `user_url` profile field |
| `wp.author.bio` | text | Biographical info |
| `wp.author.avatar` | url | Avatar URL |
| `wp.author.id` | text | User ID |

`wp.author` is the post's `post_author`. Sites with a co-authors plugin iterate
instead:

```html
{{#wp.authors}}<a href="{{this.url}}">{{this.name}}</a>{{^@last}}, {{/@last}}{{/wp.authors}}
```

`wp.authors` yields one item by default. A co-authors integration replaces the
list through the `theshed_placeholder_authors` filter.

## Featured image fields

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.featured_image` | html | A complete `<img>` element |
| `wp.featured_image.url` | url | Source URL |
| `wp.featured_image.width` | text | Width in pixels |
| `wp.featured_image.height` | text | Height in pixels |
| `wp.featured_image.alt` | text | Alt text |
| `wp.featured_image.caption` | text | Caption |
| `wp.featured_image.srcset` | text | `srcset` attribute value |
| `wp.featured_image.sizes` | text | `sizes` attribute value |
| `wp.featured_image.id` | text | Attachment ID |

Every field takes `size`, a registered image size: `{{wp.featured_image.url
size="large"}}`. The default is `full`.

`{{#wp.featured_image}}` renders its body once when the post has a thumbnail,
with the same fields bound to `this`:

```html
{{#wp.featured_image}}
<figure style="background-image:url({{this.url size="large"}})">
  <figcaption>{{this.caption}}</figcaption>
</figure>
{{/wp.featured_image}}
```

## Site fields

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.site.name` | text | `blogname` |
| `wp.site.description` | text | `blogdescription` |
| `wp.site.url` | url | `site_url()` |
| `wp.site.home_url` | url | `home_url()` |
| `wp.site.logo` | url | Custom logo URL |
| `wp.site.language` | text | Site locale |

## Taxonomy fields

Three list fields cover categories, tags, and any other taxonomy registered for
the story post type.

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.categories` | html | The post's `category` terms, as links |
| `wp.tags` | html | The post's `post_tag` terms, as links |
| `wp.terms.<taxonomy>` | html | The post's terms in `<taxonomy>`, as links |
| `wp.primary_term.<taxonomy>` | html | The primary term, where an SEO plugin sets one |

The value form renders a default list. Arguments shape it.

| Argument | Default | Effect |
| --- | --- | --- |
| `separator` | `", "` | Text between items |
| `last_separator` | `separator` | Text before the final item |
| `link` | `"true"` | `"false"` renders names without anchors |
| `limit` | none | Render at most this many items |
| `order` | `"name"` | `name`, `count`, or `term_order` |
| `empty` | `""` | Text rendered when the post has no terms |

Example: `{{wp.categories separator=" · " link="false" limit="3"}}`.

## Taxonomy sections

The section form gives full control of the markup. Each item binds these fields
to `this`.

| Field | Type | Value |
| --- | --- | --- |
| `this.name` | text | Term name |
| `this.slug` | text | Term slug |
| `this.url` | url | Term archive |
| `this.id` | text | Term ID |
| `this.count` | text | Posts in the term |
| `this.description` | text | Term description |
| `this.taxonomy` | text | Taxonomy name |
| `this.parent.name` | text | Parent term name |

```html
{{#wp.tags}}<a class="chip" href="{{this.url}}">{{this.name}}</a>{{/wp.tags}}
```

`limit` and `order` apply to sections as well as to the value form.

## Tag Groups fields

The Tag Groups plugin assigns a term to one or more groups. Group id, label and
position live in the `term_groups`, `term_group_labels` and
`term_group_positions` options; a term's groups live in its `_cm_term_group_array`
term meta. Groups cut across taxonomies, so a group holds whichever of the
post's terms belong to it, whatever taxonomy each came from.

| Placeholder | Type | Value |
| --- | --- | --- |
| `wp.tag_group.<slug>` | html | The post's terms in that group, as links |
| `wp.tag_group.<slug>.label` | text | The group's label |
| `wp.tag_group.<slug>.terms` | html | Same as `wp.tag_group.<slug>` |
| `wp.tag_groups` | — | Section only: every group holding a term on this post |

`<slug>` is `sanitize_title()` of the group label: the group `Topics covered`
is addressed `wp.tag_group.topics-covered`. A group may also be addressed by
its stable numeric id, as `wp.tag_group.id-4`, for sites that rename groups.

Every taxonomy argument applies, plus `taxonomy`, which narrows a group to one
taxonomy: `{{wp.tag_group.topics taxonomy="post_tag"}}`.

## Tag Groups sections

A group section binds the group's own fields, and nests a section over its
terms.

| Field | Type | Value |
| --- | --- | --- |
| `this.label` | text | Group label |
| `this.slug` | text | Slugified label |
| `this.id` | text | `term_group` id |
| `this.position` | text | Group position, the order Tag Groups shows |
| `this.terms` | — | Section: the post's terms in this group |

```html
{{#wp.tag_groups}}
<dl>
  <dt>{{this.label}}</dt>
  {{#this.terms}}<dd><a href="{{this.url}}">{{this.name}}</a></dd>{{/this.terms}}
</dl>
{{/wp.tag_groups}}
```

Groups iterate in Tag Groups' own position order. A group with no term on this
post is skipped, so a story shows only the groups that apply to it.

## Custom field access

`wp.meta.<key>` resolves one post meta key as `text`.

Access is closed by default. A key resolves only when it is on the allowlist
built by the `theshed_placeholder_meta_allowlist` filter. Two families are
denied unconditionally and cannot be allowlisted: keys beginning `_`, and the
plugin's own `story_*` keys. `story_body` is the rendered page; resolving it
into itself would nest the story in the story.

## Where placeholders resolve

Resolution happens at render, not at publish. A post's title, terms, author and
featured image all change without the story being republished, so a value baked
into `story_body` at publish time would go stale.

| Path | Resolved in | Against |
| --- | --- | --- |
| Story body | `Shorthand\Services\StoryKses::echo_extract_and_enqueue_assets()` | The queried post |
| Story head | `Shorthand\Plugin\Templates::single_head()` | The queried post |
| Live preview | The same two, unchanged | The post being previewed |

In the body, the resolver runs after `Shorthand\Services\StoryAssetParser`
has extracted `<script>` and `<style>` blocks and before the markup is echoed.
Scripts and styles are therefore never a substitution target, and no WordPress
value can be written into JavaScript.

`Shorthand\Services\LivePreview` swaps the story meta and lets the template
render, so the preview resolves placeholders by the same code as the published
page. No second implementation exists.

## Publish-time handling

`Shorthand\Services\PostAPI::extract_story_content()` does not resolve
placeholders. It passes the markup through unchanged, exactly as it does today.

`Shorthand\Services\StoryTextExtractor` strips claimed placeholders from the
text it writes to `post_content` and `post_excerpt`. Those columns feed core
search and listing views; a raw `{{wp.title}}` in a search result would be
noise.

## Unknown and empty values

| Case | Result |
| --- | --- |
| Claimed placeholder, unknown field | Removed, and `theshed_placeholder_unknown` fires |
| Claimed placeholder, empty value | Empty string |
| Unclaimed braces | Left exactly as written |
| Unclosed section | Left exactly as written, and `theshed_placeholder_unclosed` fires |

A claimed placeholder is never printed as literal text on the front end. A
visible `{{wp.titel}}` on a live story is worse than a missing value, and the
typo is reported through the action instead.

Every value field takes `default`, rendered when the value is empty:
`{{wp.excerpt default="Read the full story."}}`.

## Extension

| Hook | Kind | Purpose |
| --- | --- | --- |
| `theshed_placeholder_fields` | filter | Register fields, or replace a resolver |
| `theshed_placeholder_value` | filter | Override one resolved value |
| `theshed_placeholder_authors` | filter | Replace the `wp.authors` list |
| `theshed_placeholder_meta_allowlist` | filter | Allow `wp.meta.<key>` reads |
| `theshed_placeholder_unknown` | action | Report an unknown field |
| `theshed_placeholder_unclosed` | action | Report an unclosed section |

A registered field declares its type, so a third party gets the same escaping
guarantee as a built-in.

## What an author has to know

Three constraints come from the Shorthand editor, not from this language.

1. A placeholder must sit in one unbroken run of text. Applying bold or a link
   to part of `{{wp.title}}` splits it across elements and it will not match.
2. The Shorthand editor shows the literal placeholder. Values appear only on
   the WordPress page and in the WordPress preview.
3. An `html` field inside an attribute produces broken markup. Use the `url` or
   `text` counterpart there.

## Decision: one namespace, claimed strictly

Every placeholder carries a `wp.` root so the resolver can find its own tags by
prefix alone. Without it, the resolver would have to parse every `{{ }}` in the
story and decide whether it was addressed to WordPress, and a story containing a
code sample or a third-party template would be corrupted.

## Decision: typed fields, no triple stash

Handlebars leaves escaping to the author, through `{{ }}` against `{{{ }}}`.
Here the field's type carries it. An author writing a story in Shorthand has no
way to reason about the HTML context their text lands in, and a wrong choice is
an injection. The type is fixed by the field, so there is no choice to get
wrong.

## Decision: sections instead of conditionals

Mustache section semantics — a section over an empty value renders nothing —
give conditional behaviour without `{{#if}}`, `{{#unless}}` or `{{else}}`.
One construct covers "repeat per item", "render when present", and "remove the
label along with the value". The parser stays small enough to read in one
sitting, which matters because it runs on every story page view.

## Decision: resolve at render, not at publish

Terms, title, author and featured image change on the WordPress side without
Shorthand being involved. Resolving at publish would mean a story showing the
categories it had when it was last published. The cost is per-request work; it
is bounded by pre-scanning the markup and resolving only the fields that occur.
