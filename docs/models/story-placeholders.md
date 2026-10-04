---
title: Story placeholders
purpose: The value-only placeholder grammar an author writes into a Shorthand story — seven token forms covering five WordPress values and one user-defined namespace — and the two filters every token passes through.
updated: 2026-10-04
---

# Story placeholders

The value-only placeholder language for Shorthand stories, and the two
filters every token resolves through.

**Status:** specified, not built. No resolver exists in the plugin yet. The
call sites named in "Product context" are where it is to run; today they
handle only assets and meta-tag escaping.

## Scope

This document specifies a stripped-down placeholder language: value tokens
only.

In scope:

- `{{wp.story.title}}`, `{{wp.story.date}}`, `{{wp.story.date_iso}}`
- `{{wp.parent.url}}`
- `{{wp.meta.<key>}}`
- `{{wp.terms.<taxonomy>}}`
- `{{wp.filter.<key>}}`, a value supplied only by a site's own filter

Out of scope, deliberately: sections and inverted sections
(`{{#wp.…}}…{{/wp.…}}`, `{{^wp.…}}…{{/wp.…}}`), `{{this.…}}` scoping,
`{{@index}}` / `{{@first}}` / `{{@last}}` loop variables, and per-tag
arguments (`key="value"`). See "What this leaves out".

The full language this is a subset of is specified, unaltered, at
`/Users/simon/Projects/Shorthand for WordPress/plans/story-placeholders-full-language.md`.
That copy wrote the story's own fields as `wp.title`, `wp.date` and
`wp.date_iso`; its note "Renamed since parking" records that a later
iteration adopts this subset's `wp.story.` names instead. With that rename,
every key name here is also a key name there, except `wp.filter.<key>`,
which has no counterpart in the full language. Extending this subset later
never requires renaming a key an author has already written into a story.

## Product context

A Shorthand story bundle is a whole HTML page, stored in the `story_head`
and `story_body` post meta of a `tse_story` post (registered in
`/Users/simon/Repos/Shorthand/shorthand_editor_wordpress/php/src/lib/Plugin/PostType.php`).
The story carries no WordPress block template, so no theme fills any part
of it in. A WordPress value an author wants inside the story is written
into the story HTML as a placeholder and substituted at render.

`tse_story` supports `title`, `thumbnail`, `excerpt`, `page-attributes`,
`author`, `custom-fields`; registers the taxonomies `category` and
`post_tag`; and does not pass `hierarchical`, so it defaults to `false`.

Resolution is to run in four places, against the same code:

| Path | Resolved in |
| --- | --- |
| Story body | `Shorthand\Services\StoryKses::echo_extract_and_enqueue_assets()`, called from `php/src/templates/single-tse-story.php`, after `Shorthand\Services\StoryAssetParser` extracts `<script>`/`<style>` |
| Story head | `Shorthand\Services\StoryKses::echo_meta_tags()`, called from `Shorthand\Plugin\Templates::single_head()` on `wp_head` |
| WordPress preview | The same two, against the post `Shorthand\Services\LivePreview` is previewing |
| Editor preview | The same two, called from `php/src/assets/admin/partials/preview-innerhtml.php`, which `Shorthand\Admin\Actions\PostPreview::render_page()` includes |

Neither `StoryKses` method receives a post today. Each call site passes
the `WP_Post` in. `PostPreview::render_page()` holds only `$post_id`, so it
loads the post with `get_post( $post_id )` first.

Resolution happens at render, not at publish, so a title, taxonomy, or
parent change on the WordPress side shows up without the story being
republished.

The story head is never echoed whole. `StoryKses::echo_meta_tags()` lifts
each `<meta>` tag's attributes out and re-emits them through `esc_attr()`
(`StoryKses.php:467`), so a head token always ends up inside an attribute
value that is escaped again downstream. That second escape is harmless:
`esc_attr()` leaves `$double_encode` at its default `false`, so an
already-escaped value is not re-encoded. Markup in a head token always
shows as escaped text, whatever its type.

## Grammar

Every token has the shape `wp.<namespace>.<key>`, wrapped in `{{` and `}}`
with optional horizontal whitespace inside the braces. No sigil, no
argument.

- **Namespace:** one of five fixed lowercase words — `story`, `parent`,
  `meta`, `terms`, `filter`. The namespace fixes the key's character set,
  the default value, and the escape. Every namespace name, now and later,
  is letters `a`–`z` only; see "Namespace names".
- **Key:** everything after the namespace's own dot, up to the closing
  `}}`. For `story` and `parent` the key is one of a fixed list. For
  `meta`, `terms` and `filter` it is open, within a character set.

```ebnf
token         = "{{" , ws , "wp." , path , ws , "}}" ;
ws            = { " " | "\t" } ;
path          = "story."  , story_key
              | "parent." , "url"
              | "meta."   , meta_key
              | "terms."  , taxonomy_name
              | "filter." , filter_key ;
story_key     = "title" | "date_iso" | "date" ;
meta_key      = meta_char , { meta_char } ;      (* 1-255 characters *)
meta_char     = ? any Unicode character except "{" (U+007B), "}" (U+007D),
                  CR (U+000D) and LF (U+000A) ? ;
taxonomy_name = tax_char , { tax_char } ;        (* 1-32 characters *)
tax_char      = ? any Unicode character except whitespace, "{" and "}" ? ;
filter_key    = filter_char , { filter_char } ;  (* 1-64 characters *)
filter_char   = "a" .. "z" | "0" .. "9" | "_" | "-" ;
```

## Namespace names

Every namespace name, the five today and any added later, is one or more
lowercase ASCII letters. It matches `^[a-z]+$`: no digit, `_`, `-`, `.`,
uppercase letter, or non-ASCII letter.

The rule covers the namespace only. The key after the namespace's own dot
keeps the character set its namespace already has; see "Key character
sets". A key may still hold `_`, digits, `-` or `.` where its set allows.

Correct: `story`, `parent`, `meta`, `terms`, `filter`; a later `author` or
`thumbnail`. Incorrect: `featured_image` (`_`), `og2` (digit), `Author`
(uppercase), `tag-group` (`-`); name them `thumbnail`, `og`, `author` and
`taggroup` instead.

The rule limits names. It does not widen the grammar. The `path`
production in "Grammar" and the detection pattern in "Detection pattern"
still list each namespace as one literal alternative with its own key set.
A new namespace is added as one more literal alternative in each. Incorrect:
replacing the literals with `[a-z]+`; that would detect `{{wp.anything.x}}`
and pass an unknown namespace to the filters. Correct: add
`| "author." , author_key` to `path`, and a matching
`(?<ns>author)\.(?<key>…)` branch to the pattern.

The rule keeps three properties true for every future namespace:

- The first `_` after `tse_resolve_placeholder_` in a hook name always ends
  the namespace. See "Hook names are unique".
- The first `.` after `wp.` in a token always ends the namespace. See
  "Ambiguity rules".
- A namespace name obeys "Case and whitespace rules" with no exception.

A unit test asserts that every entry in the resolver's namespace list
matches `/^[a-z]+$/`.

## Detection pattern

One PCRE pattern detects every token and nothing that is not one:

```text
/\{\{[ \t]*wp\.(?|(?<ns>story)\.(?<key>date_iso|date|title)|(?<ns>parent)\.(?<key>url)|(?<ns>meta)\.(?<key>[^{}\r\n]{1,255}?)|(?<ns>terms)\.(?<key>[^\s{}]{1,32})|(?<ns>filter)\.(?<key>[a-z0-9_-]{1,64}))[ \t]*\}\}/u
```

`(?|…)` is a branch-reset group: each alternative numbers its groups from
the same start. All five alternatives therefore set the same two named
groups, `ns` and `key`, and every match sets both. The resolver reads the
namespace and key straight off the match; it never re-splits the matched
text. No `(?J)` flag is needed: PCRE2 accepts a repeated group name when
every use has the same group number, which a branch reset guarantees.

The `u` modifier makes every bound count Unicode codepoints, not bytes.

The pattern has no nested or overlapping quantifiers and no lookahead.
Every alternative ends at the literal `}}`. A wrong partial match — `date`
matching the first four characters of `date_iso` — fails at that literal,
and the engine falls through to the next alternative. The cost per `{{`
found is bounded by the longest quantifier (255), not by the length of the
document, so scanning a 500 KB story body stays linear.

## Key character sets

`[^{}\r\n]{1,255}?` for a meta key accepts every character a real
`meta_key` may legally contain — spaces, `.`, `:`, `/`, uppercase, full
Unicode — because WordPress's own metadata write path rejects none of
them. The four characters excluded are excluded for this syntax's reasons:
the braces are the token's own delimiters, and CR/LF keep a token on one
line so an unclosed `{{` cannot swallow the markup below it. 255 is
`meta_key varchar(255)` (`wp-admin/includes/schema.php`), which counts
characters.

The meta quantifier is lazy. Greedy, it would fold the padding before `}}`
into the key, because a space is legal in a `meta_key`:
`{{wp.meta.colour }}` would look up `colour ` and find no row. Lazy, the
padding falls to `[ \t]*`, where it belongs.

`[^\s{}]{1,32}` for a taxonomy name bounds length and excludes whitespace
and braces, nothing more. `register_taxonomy()` enforces only the 1-32
length, with `strlen()`, and runs no character check, so a taxonomy
registered as `Region` is real and addressable. Whether the taxonomy
exists is settled at resolution by `is_object_in_taxonomy()`. Because
`register_taxonomy()` counts bytes and the pattern counts codepoints, a
32-codepoint multibyte name passes the pattern but could never have been
registered; it resolves to `''`.

`[a-z0-9_-]{1,64}` for a filter key is the plugin's own choice: the key
names nothing WordPress stores, so no WordPress rule applies.

- The set is `sanitize_key()`'s output set, so a developer can write the
  key in a hook name and in PHP without surprises.
- `.` is excluded, which leaves `wp.filter.a.b` free for a later version
  to give a meaning without reinterpreting a token already written.
- Uppercase is excluded, so `{{wp.filter.Byline}}` is not detected and
  stays visible as a typo, instead of calling a hook nobody registered.
- 64 bounds the scan. It is not a WordPress limit.

## Ambiguity rules

The namespace is the literal text straight after `wp.`, up to the first
`.`. No namespace name contains `.` (see "Namespace names"), so no
namespace's `name.` prefix starts another's. After the namespace's own
dot, everything up to `}}` is one opaque key. Dots inside a key are never
re-split.

So no key can collide with another namespace, by construction:

- `{{wp.meta.seo.title}}` resolves the meta key `seo.title`;
  `{{wp.meta.og:image}}` resolves `og:image`; `{{wp.meta.price/usd}}`
  resolves `price/usd`.
- `{{wp.meta.story.title}}` resolves the meta key `story.title`, never the
  story title. `{{wp.meta.filter.x}}` resolves the meta key `filter.x`.
- `{{wp.terms.story}}` resolves the taxonomy `story`. A taxonomy name may
  itself contain `.`: `{{wp.terms.my.tax}}` resolves `my.tax`.

This is not a priority rule the resolver applies after a broader match.
`{{wp.story.title}}` and `{{wp.meta.story.title}}` differ in the literal
text after `wp.`, so they are different alternatives of one regex and
never contend for the same input.

`{`, `}`, CR and LF are the only characters excluded from a meta key. A
key holding one of them is unaddressable permanently, by design. Correct:
rename such a key before addressing it. Incorrect: inventing an escape
sequence for a brace inside a token — that reopens the parsing ambiguity
this grammar avoids.

## Case and whitespace rules

The fixed vocabulary — `wp`, `story`, `title`, `date`, `date_iso`,
`parent`, `url`, `meta`, `terms`, `filter` — is matched case-sensitively,
lowercase only. `{{wp.story.Title}}` and `{{wp.Story.title}}` match no
alternative and are left exactly as written.

No key is case-folded by the detector. WordPress's own `meta_key`
comparison depends on the database collation, which was not verified
against any site; `$wp_taxonomies` is a plain array keyed by the exact
registered string. Folding case could match the wrong stored key or
taxonomy. The detector passes each key on exactly as captured.

Horizontal whitespace immediately inside `{{` and `}}` is trimmed by the
pattern, so `{{ wp.story.title }}` and `{{wp.story.title}}` are the same
token. A `meta_key` that itself starts or ends with a space or tab — legal
in `wp_postmeta` — therefore cannot be reached through this syntax.

A third brace is not part of the token. `{{{wp.story.title}}}` matches the
`{{wp.story.title}}` inside it and leaves the outer `{` and `}` as story
text, so it renders as `{Story title}`. Mustache's triple-stache means "do
not escape"; here escaping is fixed by namespace and never optional.

A malformed token never partially matches. `{{wp.meta.}}` (empty key),
`{{wp.frobnicate.x}}` (unknown namespace) and `{{wp.title}}` (the form
before the `story` namespace) fail every alternative and are left exactly
as written. There is no second "is this namespace known" check after a
wider first match.

## Detection test cases

The pattern was run against these cases on PHP 8.5.5 (PCRE2 10.48) and on
PHP 8.3.33 (PCRE2 10.42), and all behaved as listed. They are the unit
tests for the detector. Each match lists the captured `ns` and `key`.

### Must match

- `{{wp.story.title}}`, `{{ wp.story.title }}`, `{{	wp.story.title	}}` →
  `story`, `title`
- `{{wp.story.date}}` → `story`, `date`; `{{wp.story.date_iso}}` →
  `story`, `date_iso`
- `{{wp.parent.url}}` → `parent`, `url`
- `{{wp.meta.subtitle}}` → `subtitle`; `{{wp.meta.seo.title}}` →
  `seo.title`; `{{wp.meta.og:image}}` → `og:image`;
  `{{wp.meta.price/usd}}` → `price/usd`; `{{wp.meta.a b c}}` → `a b c`;
  `{{wp.meta.Price}}` → `Price`; `{{wp.meta.日本}}` → `日本`
- `{{wp.meta.colour }}` → `colour`, the padding trimmed, not folded in
- `{{wp.meta.story.title}}` → `meta`, `story.title`;
  `{{wp.meta.filter.x}}` → `meta`, `filter.x`
- `{{wp.terms.category}}`, `{{wp.terms.post_tag}}`,
  `{{wp.terms.Region}}`, `{{wp.terms.my.tax}}`, `{{wp.terms.story}}` →
  `terms`, each name
- `{{wp.filter.reading_time}}`, `{{wp.filter.reading-time}}`,
  `{{wp.filter.a}}`, `{{wp.filter.x9}}`, `{{ wp.filter.byline }}` →
  `filter`, each key
- A 255-character meta key, a 32-character taxonomy name, a 64-character
  filter key

### Must not match

- `{{wp.title}}`, `{{wp.date}}`, `{{wp.date_iso}}` — the form before the
  `story` namespace
- `{{wp.story}}`, `{{wp.story.}}`, `{{wp.story.excerpt}}`,
  `{{wp.story.date-iso}}` — incomplete or unknown story key
- `{{wp.story.Title}}`, `{{wp.Story.title}}`, `{{WP.story.title}}`,
  `{{ wp . story . title }}` — wrong case or wrong literal text
- `{{wp.frobnicate}}`, `{{wp.parent}}`, `{{wp.parent.title}}`,
  `{{wp.custom.x}}`, `{{wp}}`, `{{wp.}}`, `{{title}}` — unknown or
  incomplete namespace or key
- `{{wp.meta.}}`, `{{wp.terms.}}`, `{{wp.filter.}}` — empty key
- `{{wp.terms.with space}}`, `{{wp.meta.a{b}}`, a meta key spanning a
  newline — excluded characters
- `{{wp.filter.Reading}}`, `{{wp.filter.a.b}}`, `{{wp.filter.a b}}`,
  `{{wp.filter.日本}}`, `{{wp.filter.a:b}}` — outside the filter key set
- A 256-character meta key, a 33-character taxonomy name, a 65-character
  filter key — over bounds
- `.card{margin:0}`, `@media screen{.a{b:c}}`, `` `${y}` `` — ordinary CSS
  and JavaScript braces
- `{{ wp.story.title }`, `{wp.story.title}` — unbalanced delimiters

### Edge behaviour

- `{{wp.meta.a}}b}}` captures the key `a`, stopping at the first `}}`.
- `{{{wp.story.title}}}` renders as `{Story title}`.
- An unclosed `{{wp.meta.` followed by 200,000 characters and no `}}`
  fails in under 1 ms: the bounded quantifier cannot run away.

### Hook name tests

- For every must-match case, the specific hook name differs from the
  generic hook name `tse_resolve_placeholder`.
- No two must-match cases with different (`ns`, `key`) pairs share a
  specific hook name.
- For every must-match case, splitting the hook name at the first `_`
  after `tse_resolve_placeholder_` gives back the same `ns` and `key`.
- Every entry in the namespace list matches `/^[a-z]+$/`, so no namespace
  holds the `_` that the split relies on.

## Keys

| Placeholder | `ns` | `key` | Type | Default value |
| --- | --- | --- | --- | --- |
| `{{wp.story.title}}` | story | `title` | text | `get_the_title( $post )` |
| `{{wp.story.date}}` | story | `date` | text | `get_the_date( '', $post )` — site date format |
| `{{wp.story.date_iso}}` | story | `date_iso` | text | `get_the_date( 'c', $post )` — ISO 8601, with UTC offset |
| `{{wp.parent.url}}` | parent | `url` | url | `$parent = get_post_parent( $post )`, then `$parent ? get_permalink( $parent ) : ''` |
| `{{wp.meta.<key>}}` | meta | `<key>` | text | `get_post_meta( $post->ID, <key>, true )`; `''` for a denied key |
| `{{wp.terms.<taxonomy>}}` | terms | `<taxonomy>` | html | `''` when `! is_object_in_taxonomy( $post->post_type, <taxonomy> )`, else `get_the_term_list( $post->ID, <taxonomy>, '', ', ', '' )` |
| `{{wp.filter.<key>}}` | filter | `<key>` | text | `''`, always |

The `wp.parent.url` default never passes `get_post_parent()`'s result
straight to `get_permalink()`. For a parent that was deleted,
`get_post_parent()` returns `null`, and `get_permalink( null )` falls back
to the global `$post` — the story itself — so the token would link the
story to itself.

If `get_post_meta()` returns a non-scalar value (an array, from a
serialized key) or `get_the_term_list()` returns a `WP_Error`, the default
is `''`, not the raw value. Nothing stringifies a non-scalar; a site that
wants that does it in a filter.

## Why these namespaces

The story's own fields sit under `story`, not `post`. The post type's
labels call it a Story (`PostType.php`), and that is the word an author
sees. A later version adds other post fields as more `story` keys
(`wp.story.excerpt`, `wp.story.url`).

`wp.parent.url` stays outside `story`. The parent is a different post,
reached through a relationship; its URL is not a field of the story.

`filter` names the value's only source: a site's filter. `custom` was
rejected because WordPress labels post meta "Custom Fields" (the
`postcustom` meta box, `wp-admin/includes/meta-boxes.php`), so
`wp.custom.x` would read as post meta. `site` was rejected because the
full language reserves `wp.site.*` for site options.

`wp.date` and `wp.date_iso` are two fixed story keys, not one key with a
format argument, so the site's `date_format` option — admin-editable free
text that may contain `{{`/`}}` — never enters a token.

No term slug appears in a token, so no token carries a URL component.
`wp.terms.` takes a taxonomy name, and single terms are never addressed in
this subset. A term slug's character set is stricter than a taxonomy
name's — `sanitize_title()` lowercases, rewrites `.` to `-`, and
percent-encodes non-ASCII — and matters only if a later version addresses
a single term.

## Types and escaping

Three types. The type is fixed per namespace. Neither an author nor a
filter callback chooses the escape.

| Type | Escaped with | Namespaces |
| --- | --- | --- |
| `text` | `esc_attr()` | `story`, `meta`, `filter` |
| `url` | `esc_url()` | `parent` |
| `html` | `wp_kses_post()` | `terms` |

`esc_attr()` is quote-safe, so it is also valid element text: one function
covers a token wherever it sits. The resolver escapes once, after both
filters return, and never lets a callback escape.

`filter` is text only. Markup a callback returns is escaped and shows as
text. A head token is escaped to text whatever its type (see "Product
context"), and one namespace with one type behaves the same in head and
body. Correct: a later version that needs markup from a filter adds a new
namespace with its own fixed `html` type. Incorrect: letting a callback
choose the escape.

## Filter contract

Every detected token passes through two filters, in this order:

1. The generic filter, `tse_resolve_placeholder`, for every token except a
   denied meta key. See "Denied meta keys".
2. The specific filter, `tse_resolve_placeholder_{$namespace}_{$key}`, for
   that one token, always.

Both take the same four arguments. The plugin coerces the value after each
filter and escapes once, after the second.

```php
$value = $default;
if ( ! $denied ) {
	$value = apply_filters( 'tse_resolve_placeholder', $value, $namespace, $key, $post );
	$value = is_scalar( $value ) ? (string) $value : '';
}
$value = apply_filters( "tse_resolve_placeholder_{$namespace}_{$key}", $value, $namespace, $key, $post );
$value = is_scalar( $value ) ? (string) $value : '';
```

The prefix `tse_` is as proposed. The plugin's eight existing hooks use
`theshed_`; see "Open decision: hook prefix".

`$denied` is true only for a denied meta key. See "Denied meta keys".

### Arguments

| Argument | Value |
| --- | --- |
| `$value` | Generic filter: `$default`, from the Keys table. Specific filter: the value the generic filter returned, coerced to a string; for a denied meta key, `$default` (`''`). |
| `$namespace` | One of `story`, `parent`, `meta`, `terms`, `filter` |
| `$key` | The captured key, exactly as written in the token |
| `$post` | The `WP_Post` being rendered or previewed |

The specific filter repeats `$namespace` and `$key` although its name
already holds them. This lets one callback serve several specific hooks,
and keeps both filters on one signature.

`$post` is a `WP_Post`, not an ID. The plugin's three older hooks that
carry a post — `theshed_story_content`, `theshed_story_excerpt_length` and
`theshed_story_excerpt` (`PostAPI.php`) — pass `int $post_id`. The new
filters pass the object on purpose: nearly every callback needs a field of
it, and the resolver already holds it.

### Values

Three values are named, so a callback author knows which one they see:

1. `$default` — the built-in value, from the Keys table.
2. The generic value — `$default` after `tse_resolve_placeholder`,
   coerced.
3. The resolved value — the generic value after the specific filter,
   coerced. This is what gets escaped.

For a denied meta key the generic filter is skipped, so the generic value
is `$default`, `''`, unchanged.

**Coercion** after each filter: `is_scalar( $value ) ? (string) $value : ''`.
`null`, `false`, arrays and objects become `''`.

**Declining.** Return the `$value` received. There is no separate "not
handled" sentinel; `null` and `false` are values, coerced to `''`.

**Escaping.** Always the plugin, always after the specific filter, by the
namespace's type. A return is never trusted as pre-escaped, not even for
the `html`-typed `terms` namespace: `wp_kses_post()` still runs, so a
callback can only produce markup `wp_kses_post()` allows.

### Filter order

The generic filter runs first and the specific filter last, so the
narrowest callback has the final word. A site-wide callback on the generic
filter — a fallback, a log, a translation pass — cannot undo a callback
written for one exact token.

WordPress core is split on this order: `pre_option_{$option}` runs before
`pre_option`, while `transition_post_status` runs before
`{$old_status}_to_{$new_status}`. The choice rests on giving the narrowest
callback the final word, not on core precedent.

## Hook names

The specific hook name is built by plain interpolation:

```php
"tse_resolve_placeholder_{$namespace}_{$key}"
```

| Token | Specific hook name |
| --- | --- |
| `{{wp.story.title}}` | `tse_resolve_placeholder_story_title` |
| `{{wp.story.date_iso}}` | `tse_resolve_placeholder_story_date_iso` |
| `{{wp.parent.url}}` | `tse_resolve_placeholder_parent_url` |
| `{{wp.meta.subtitle}}` | `tse_resolve_placeholder_meta_subtitle` |
| `{{wp.meta.og:image}}` | `tse_resolve_placeholder_meta_og:image` |
| `{{wp.meta._price}}` | `tse_resolve_placeholder_meta__price` |
| `{{wp.terms.category}}` | `tse_resolve_placeholder_terms_category` |
| `{{wp.filter.reading_time}}` | `tse_resolve_placeholder_filter_reading_time` |

The key goes into the hook name verbatim: no `sanitize_key()`, no case
folding. A hook name is an array key and accepts any string, and core does
the same with `sanitize_{$object_type}_meta_{$meta_key}`, in `sanitize_meta()`.
Sanitizing would merge distinct keys — `Price` and `price`, `og:image` and
`ogimage` — onto one hook.

### Hook names are unique

Every (namespace, key) pair has its own hook name, and no specific hook
name equals the generic one.

- The generic name lacks the `_` that the specific template puts after
  `tse_resolve_placeholder`, so the two can never be equal.
- A namespace name is letters only (see "Namespace names"), so it never
  contains `_`. The first `_` after `tse_resolve_placeholder_` always ends
  the namespace, and the rest is the key. One hook name therefore splits
  back into exactly one (namespace, key) pair.

A key may contain `_` (`date_iso`, `post_tag`, `_price`) without breaking
this. Only the namespace must be free of `_`.

Correct: a future namespace named `author`. Incorrect: a future namespace
named `meta_extra`; its key `x` and the `meta` key `extra_x` would share
`tse_resolve_placeholder_meta_extra_x`. Name it `extra` instead. Incorrect:
`featured_image`, from the full language; name it `thumbnail` instead.

The unit test in "Namespace names" asserts the letters-only rule over the
namespace list, and the hook name tests in "Detection test cases" assert
uniqueness over real keys.

## Finding a hook name

A developer who wants to override one token needs its exact hook name,
including any odd characters in the key. The quickest way is to log every
token a story resolves:

```php
add_filter( 'tse_resolve_placeholder', function ( $value, $namespace, $key ) {
	error_log( "tse_resolve_placeholder_{$namespace}_{$key}" );
	return $value;
}, 10, 3 );
```

Then register the logged name:

```php
add_filter( 'tse_resolve_placeholder_meta_og:image', function ( $value, $namespace, $key, $post ) {
	return get_the_post_thumbnail_url( $post, 'large' ) ?: $value;
}, 10, 4 );
```

The generic log never sees a denied meta key, because the generic filter
skips it. See "Denied meta keys".

## The filter namespace

`{{wp.filter.<key>}}` resolves only through a site's own filter. Its
default is always `''`, and the plugin reads nothing for it.

- The specific filter serves one key:
  `tse_resolve_placeholder_filter_reading_time`.
- The generic filter can serve the whole namespace, by testing
  `'filter' === $namespace`.
- With neither, the token renders as `''`.

### Unhandled filter keys

When a `filter` token has no callback on either filter, the resolver calls
`_doing_it_wrong()` with a message naming the token and its specific hook
name. The rendered value stays `''`.

`_doing_it_wrong()` has an effect only when `WP_DEBUG` is on (its own
`if ( WP_DEBUG && … )` check), so a production page shows nothing. The check is
`! has_filter( 'tse_resolve_placeholder' ) && ! has_filter( $hook )`: when
any generic callback exists, the resolver cannot tell whether it served
the key, and stays silent.

### Filter keys are author input

The key is chosen by whoever edits the story — any user with `edit_post`
on it — not by the developer who wrote the callback. A callback that uses
`$key` to index a broad store lets an author copy whatever that store
holds into a public page. Escaping stops injection; it does not stop
disclosure.

Correct:

```php
add_filter( 'tse_resolve_placeholder', function ( $value, $namespace, $key, $post ) {
	$allowed = array( 'reading_time', 'byline' );
	if ( 'filter' !== $namespace || ! in_array( $key, $allowed, true ) ) {
		return $value;
	}
	return my_site_value( $key, $post );
}, 10, 4 );
```

Incorrect: `return get_option( $key );`, or any lookup in `$_SERVER`, the
database, or the environment keyed directly by `$key`.

## Trust boundary

A callback on either filter is ordinary PHP holding the `WP_Post`, exactly
like a callback on `the_title`. Whoever may register a PHP filter on the
site can already read any meta key; this layer neither widens nor narrows
that.

The plugin's own resolver never reads a denied meta key's stored value.
That does not stop a callback from calling `get_post_meta()` itself and
returning a denied value as the resolved string.

What the layer does guarantee is the escape: whatever a callback returns
is coerced and escaped by the plugin, by namespace type, afterwards.

## Memoisation

Each distinct (post, namespace, key) resolves once per request, and the
escaped result is memoised for the rest of that request, across head and
body. The memo key is `(int) $post->ID`, `$namespace` and `$key`, never
the `WP_Post` object's identity: call sites load the post separately, and
`get_post()` can return a different object for the same ID.

`preg_replace_callback()` runs its callback for every occurrence, and a
story printing `{{wp.story.title}}` in forty places should not run forty
`get_the_title()` calls or eighty filter passes.

So a callback's answer must depend only on the post, the namespace and the
key. Correct: a value computed from `$post` and `$key`. Incorrect: a value
that differs between the head and the body, or counts how many times it
was called — the second and later occurrences never reach the callback.

## Cost

An unhooked filter costs one `isset()` on `$wp_filter` (in `apply_filters()`).
Two filter calls per distinct token per request, after memoisation, are
negligible next to the database reads the defaults make.

## DocBlocks

`php/AGENTS.md` asks for a DocBlock on every function, with `@param`,
`@since` and the other tags as appropriate. Each of the two
`apply_filters()` calls gets one too, in the WordPress inline-docs style
for hooks. The specific filter's DocBlock names its dynamic parts in core's
style: "The dynamic portions of the hook name, `$namespace` and `$key`,
refer to the token's namespace and key." Both DocBlocks link to this
document for the list of namespaces and keys.

## Decision: meta keys are open, minus two denials

Any meta key on the story resolves. Two classes of key are denied:

1. A key WordPress treats as protected — `is_protected_meta( $key, 'post' )`
   returns true. That covers every key starting with `_`, and anything a
   site has protected through the `is_protected_meta` filter.
2. One of the plugin's own nine keys — `story_id`, `story_body`,
   `story_head`, `story_version`, `story_update_nonce`,
   `story_update_state`, `story_manifest`, `story_pulls`, `story_excerpt`
   (`/Users/simon/Repos/Shorthand/shorthand_editor_wordpress/php/src/lib/Plugin/PostType.php`).

The second check reads that list directly, not through
`is_protected_meta()`, because that function is filterable. A site that
un-protects `story_body` for some other reason would otherwise print the
story's raw HTML wherever an author writes `{{wp.meta.story_body}}`: up to
megabytes, with every other placeholder in it shown as literal `{{…}}`
text. It is not recursion — `preg_replace_callback()` never rescans a
replacement — but it is a size and disclosure risk the list stops.

The alternative was closed by default — an allowlist filter a site opts
each key into. Rejected. `tse_story` supports `custom-fields`, so an editor
adds a meta key in the admin UI without writing code; a placeholder that
then needs a developer to write PHP before it resolves is not a feature
that author can use. The exposure an allowlist would prevent is small: the
value is escaped, and anyone who can edit the story can already read its
custom fields in the editor.

A site that wants one specific non-underscore key kept out of stories adds
it to WordPress's `is_protected_meta` filter. No plugin-specific allowlist
filter is introduced, and `theshed_placeholder_meta_allowlist`, named in
the full language, is dropped.

## Denied meta keys

For a denied key of either class:

- `$default` is `''`, and the plugin never reads the stored value.
- The generic filter is skipped.
- The specific filter still runs, with `''`.

The generic filter is skipped so a catch-all callback cannot leak a denied
key by accident. A generic fallback such as
`return get_post_meta( $post->ID, $key, true );` would otherwise print
`_edit_lock` or `story_body` for any author who writes the token.

The specific filter still runs because naming one exact key in
`add_filter()` is a deliberate act, and it keeps every placeholder
overridable. A site that wants `{{wp.meta._price}}` to show a value
registers `tse_resolve_placeholder_meta__price` and decides what to
return.

## Resolution order

1. Scan the story body or head with the detection pattern.
2. Read `ns` and `key` from the match.
3. If the memo holds (post, `ns`, `key`), replace the token with the
   memoised value and stop.
4. If `ns` is `meta` and the key is denied, set `$default` to `''` and go
   to step 7.
5. Compute `$default` per the Keys table. When `ns` is `terms` and the
   taxonomy is not on the post type, `$default` is `''`; both filters still
   run, so a site can serve terms the post type does not carry.
6. Run the generic filter and coerce.
7. Run the specific filter and coerce.
8. If `ns` is `filter` and neither filter has a callback, call
   `_doing_it_wrong()`.
9. Escape with the namespace's fixed function.
10. Memoise the escaped value, and replace the token with it.

## Unknown and empty values

| Case | Result |
| --- | --- |
| Meta key with no stored value | `''`; both filters run |
| Meta key denied, as protected or as a plugin key | `''`; generic filter skipped; specific filter runs |
| Taxonomy not on the post type, or no terms in it | `''`; both filters run |
| `parent.url` with no parent | `''`; both filters run |
| `filter` key with no callback | `''`; `_doing_it_wrong()`, seen only under `WP_DEBUG` |
| Text matching `{{wp.` but no alternative, including `{{wp.title}}` | Left exactly as written |
| Any other `{{ }}` in the story | Left exactly as written |

A detected token is never printed as literal placeholder text. Text that
merely looks like one — an unknown namespace, a malformed key — is not
detected at all and is ordinary story content.

## What this leaves out

- Sections, inverted sections, `this` scoping, and the loop variables.
  Matching a close tag and tracking nesting is a recursive parsing
  problem; a flat regex cannot do it.
- Arguments (`format=`, `separator=`, `limit=`, `default=`). No arguments
  means no quoted strings inside a token, which removes the injection and
  parsing risk a quoting grammar carries.
- Other story fields (`wp.story.excerpt`, `wp.story.url`,
  `wp.story.modified`). Each is a new key in the `story` namespace when
  added.
- Author, featured-image, site, and Tag Groups fields. Each needs its own
  character-set analysis and a letters-only namespace name (see "Namespace
  names"). The full language's `featured_image`, `tag_group` and
  `tag_groups` must be renamed when restored; for example, `thumbnail`.
- `wp.categories`, `wp.tags` and `wp.primary_term.<taxonomy>`. `category`
  and `post_tag` already work as `wp.terms.category` and
  `wp.terms.post_tag`; a "primary term" depends on an unverified
  third-party SEO plugin, and `primary_term` would need a letters-only
  name, such as `primary`.
- `wp.parent.title`. The brief asks only for the parent's URL.
- An `html`-typed variant of `filter`. See "Types and escaping".
- The single `theshed_placeholder_value` filter of the 2026-09-28 draft.
  Replaced by the generic and specific filters.
- `theshed_placeholder_meta_allowlist`. Meta access is settled by the two
  denials and WordPress's own `is_protected_meta` filter.
- `theshed_placeholder_unknown` and `theshed_placeholder_unclosed`. Both
  needed a wider first-pass match than this grammar makes. A mistyped
  token (`{{wp.story.titel}}`) is left as literal text, not reported.

## Risks accepted

- The `meta` key accepts control characters a real meta key rarely holds.
  This mirrors WordPress; such a key matches no row and resolves to `''`.
- The bounds 255 and 32 are copied from WordPress's current schema and
  code. If WordPress moves either, the regex must move with it; keep each
  commented with its source (`wp-admin/includes/schema.php`;
  `wp-includes/taxonomy.php`). The bound 64 is the plugin's own.
- Meta-key and taxonomy-name case sensitivity depends on the database
  collation and on registration, and was not verified against a live
  database. `{{wp.meta.Price}}` can resolve differently on two installs.
  That belongs in operator documentation.
- `esc_url()` on `parent.url` is safe because `get_permalink()` is trusted
  core output. A callback that returns a URL built from user- or
  meta-controlled data gets no scheme or host check beyond what
  `esc_url()` strips.
- A callback can read a denied meta key by calling `get_post_meta()`
  itself. See "Trust boundary".
- A generic callback sees every token's key, including author-chosen
  `filter` keys. See "Filter keys are author input".
- Hook names can hold characters PHP code rarely puts in a hook name
  (`tse_resolve_placeholder_meta_og:image`). They work, but are hard to
  guess; "Finding a hook name" gives the way to discover them.

## Known gaps

- `wp.parent.url` is correct in principle and dead in practice today.
  Nothing writes a non-zero `post_parent` on a `tse_story`: the post type
  is not hierarchical, so neither editor shows a parent control, and
  `WP_REST_Posts_Controller` drops `parent` from the schema for a
  non-hierarchical type. The gap is the authoring path, not resolution.
- A story cannot display a placeholder as literal text.
  `<pre><code>{{wp.story.title}}</code></pre>` is detected and resolved
  like any other token: `StoryAssetParser` carves out only `<script>` and
  `<style>`. In a Shorthand custom-HTML block an author can write
  `&#123;&#123;wp.story.title&#125;&#125;`, which the scanner does not
  match. The named fix, if needed: an `extract_verbatim_tags()` pass in
  `StoryAssetParser` that lifts `<pre>` and `<code>` out before
  substitution, mirroring the script/style pass.
- A mistyped placeholder renders as visible text on the front end.
  `{{wp.story.titel}}` matches no alternative, so nothing reports it.
- A live preview and the published page can resolve differently.
  `LivePreview::filter_meta()` swaps only `story_body`, `story_head` and
  `story_version`; every other value is read from the saved post. A
  placeholder resolves live by design.
- The detection pattern is untested on PHP 7.4, the plugin's minimum. It
  passed on PHP 8.3.33 and 8.5.5. PHP 7.4 bundles PCRE2 10.33; the
  pattern's one unusual feature, a group name repeated inside a branch
  reset without `(?J)`, is expected to work there but was not run. Run the
  detection tests on PHP 7.4 before shipping.

## Open decision: hook prefix

Recommendation: rename the two filters to `theshed_resolve_placeholder`
and `theshed_resolve_placeholder_{$namespace}_{$key}`.

- The plugin's eight existing hooks all use `theshed_`
  (`theshed_story_content`, `theshed_post_process_body`,
  `theshed_before_story`, and five more).
- The Plugin Handbook asks for one unique prefix per plugin.
- `tse_` reads as the post type `tse_story`, not as the plugin.

The rename is one string in two places. Nothing else in this document
depends on the prefix.
