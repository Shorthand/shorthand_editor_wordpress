---
title: Story placeholders implementation hand-off
purpose: Everything in flight on the story placeholders build in The Shorthand Editor plugin on 2026-10-08 — where the code is, what passed, what is blocked, and the four decisions the user still owes.
updated: 2026-10-08
---

# Story placeholders implementation hand-off

State of the story placeholders build in The Shorthand Editor (S4W) WordPress plugin, for the session that picks it up.

## Status

- The resolver is built, tested and committed as `c0aadb6`. The branch is not rebased and not pushed.
- The done criteria pass on all three render paths (S4W admin preview, live preview and published front end), using stand-ins for a story typed in the Shorthand editor.
- A real Shorthand story with typed tokens is blocked on a user choice.
- The security review confirmed two gaps in the story body. The user must choose a fix.
- `origin/master` has moved since the branch was cut. A rebase conflicts in one file.
- On 2026-10-08 this file and the related plans and research moved from the project folder into `docs/plans/` and `docs/research/`, in the docs commit that follows `c0aadb6`.
- Four user decisions are open. The section "Open decisions" lists them.

Correct: wait for the user's answer to each open decision before acting on it.
Incorrect: committing, pushing, rebasing or undoing commits on the strength of this document alone.

## The task

The user's request, verbatim:

> Ignore the PHP7.4 test for now. Use `theshed_` as a hook prefix.
>
> Check out a new branch for this in a worktree under the `~/Projects/Shorthand for WordPress` project. Ensure the story-placeholders.md model file and the changes to docs/README.md are moved over into the new branch.
>
> Implement the story placeholders model.
>
> You are done when a Shorthand story containing the given placeholder types can be previewed/published with S4W, the content rendered, and custom filters can be defined in a child theme to pre-process on the placeholder content.

The done criteria are the last sentence of the request: a Shorthand story with the placeholder types previews and publishes with S4W, its content renders, and child-theme filters pre-process the placeholder values.

Later additions from the user:

- "If there are existing local environments running, you can kill them."
- "Make sure those doc changes are committed first, for posterity". Done as commit `90a92cd`.
- "Write a hand-off document capturing everything in flight." This file.
- "Commit the outstanding changes in this branch". Done as commit `c0aadb6`, on 2026-10-08. Not pushed, not rebased: neither was asked.
- "Then, move the plans, handoff and research documents dealing with placeholders, dynamic content and caching into appropriate paths in this repository and add them as a new commit." Done as the docs commit after `c0aadb6`, on 2026-10-08.

The specification is `docs/models/story-placeholders.md` in the plugin worktree. The specification wins over this document on any point of design.

## Where the work is

| Item | Value |
|---|---|
| Plugin repository | `/Users/simon/Repos/Shorthand/shorthand_editor_wordpress` |
| Worktree | `/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders` |
| Branch | `test/story-placeholders` |
| Branch base | `1d847ea` (origin/master on 2026-10-04) |
| Last code commit | `c0aadb6` |
| Branch head | The docs commit after `c0aadb6`; `git log --oneline -1` shows it |
| Current origin/master | `3f68ea4` |
| Pushed | No |
| Plugin version | 1.0.9 |
| Post type | `tse_story` |
| This file | `docs/plans/story-placeholders-handoff.md` in the worktree |
| dylan | The Shorthand web app and API. Repository `/Users/simon/Repos/Shorthand/dylan`; local copy at `https://localhost:9443` |

The primary checkout at `/Users/simon/Repos/Shorthand/shorthand_editor_wordpress` has two unrelated changes, `docker-compose.yml` and `php/src/the-shorthand-editor.php`. Leave both alone.

## Related documents

All paths are in the worktree. The specification wins over each of these documents.

- `docs/models/story-placeholders.md`: the specification.
- `docs/plans/story-placeholders-full-language.md`: the full placeholder language with sections and loops, parked.
- `docs/research/dynamic-content-handoff.md`: why a story gets live values by token substitution at render.
- `docs/research/caching-the-story-render-handoff.md`: caching the render in two layers.
- `docs/research/superseded/`: the original notes, which assumed placeholders are HTML elements.

Before 2026-10-08 these plans and research notes lived in `/Users/simon/Projects/Shorthand for WordPress/plans/` and `/Users/simon/Projects/Shorthand for WordPress/research/`. The project folder repository now shows the five tracked ones as deleted, uncommitted. This file was untracked there.

## Commits on the branch

| SHA | Subject | Content |
|---|---|---|
| `90a92cd` | 📝 Add the story placeholders model | The specification and the `docs/README.md` index line, committed at the user's request |
| `48a3a8c` | 📝 Decide the placeholder resolver hook prefix | Specification edits only; unrequested; kept |
| `c0aadb6` | 🧩 Resolve story placeholders in the story head and body | The whole implementation, its tests and the specification status "built", committed at the user's request |
| After `c0aadb6` | 📝 Move the placeholder plans and research into the repository | This file, the plans, the research notes and the `docs/README.md` index, committed at the user's request |

Only `c0aadb6` holds code. The worktree is clean apart from git-ignored files.

Commit trailers and messages:

- `90a92cd`, `c0aadb6` and the docs commit end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- `48a3a8c` ends with a "Claude Sonnet 5" trailer, as does `14610b5` in the project folder. A stray workflow agent made both; see "Earlier agent commits".
- The `48a3a8c` message says "eight existing theshed_ hooks". Specification line 891 says nine.

Ask the user whether to reword `48a3a8c` before pushing. The open decision "Rebase and push" includes this.

## Files in the implementation commit

Commit `c0aadb6` holds 12 files: 1558 insertions, 47 deletions.

Modified (9 files, 361 insertions, 47 deletions):

- `docs/models/story-placeholders.md`: status "built", call sites, known gaps split into authoring and rendering.
- `php/src/assets/admin/partials/preview-innerhtml.php`: passes `$post` to both `StoryKses` calls.
- `php/src/lib/Admin/Actions/PostPreview.php`: sets `$post = get_post( $post_id )` for the partial.
- `php/src/lib/Plugin/PostType.php`: adds `PROTECTED_META_KEYS` (11 keys); `is_protected_meta()` reads it.
- `php/src/lib/Plugin/Templates.php`: `single_head()` passes `get_post()` to `echo_meta_tags()`.
- `php/src/lib/Services/StoryKses.php`: both echo methods take an optional `?WP_Post $post` and resolve placeholders.
- `php/src/templates/single-tse-story.php`: passes `get_post()` to the body call.
- `php/tests/Plugin/StoryTemplateTest.php`: one new template test.
- `php/tests/bootstrap.php`: test stubs for the WordPress functions the resolver calls.

Added:

- `php/src/lib/Services/StoryPlaceholders.php` (255 lines): the resolver.
- `php/tests/Services/StoryPlaceholdersTest.php` (775 lines): the resolver tests.
- `php/tests/Services/StoryKsesPlaceholdersTest.php` (167 lines, 11 tests): head and body render tests.

Git-ignored, never to be committed:

- `data/e2e/theshed-e2e-child/`: the child theme example.
- `data/e2e/scripts/`: the end-to-end scripts.

The worktree has no `php/vendor` folder. The section "How to run the checks" says how to get one.

## How the resolver works

A token has the form `{{wp.<namespace>.<key>}}`. The class is `Shorthand\Services\StoryPlaceholders`.

| Namespace | Keys | Type | Escaped with |
|---|---|---|---|
| `story` | `title`, `date`, `date_iso` | text | `esc_attr()` |
| `parent` | `url` | url | `esc_url()` |
| `meta` | any post meta key, 1–255 characters | text | `esc_attr()` |
| `terms` | a taxonomy name, 1–32 characters | html | `wp_kses_post()` |
| `filter` | `[a-z0-9_-]`, 1–64 characters | text | `esc_attr()` |

Public API:

- `PATTERN` (line 34): the detection regex, byte-identical to the specification.
- `NAMESPACES` (line 42): the namespace to type map.
- `replace( string $content, WP_Post $post ): string` (line 64).
- `hook_name( string $ns, string $key ): string` (line 91).
- `flush(): void` (line 98): clears the memo.

Resolution order for one token, in `resolve()` (lines 110–172):

1. Look up the memo, keyed by `[(int) $post->ID][$ns][$key]`. A hit returns the stored, escaped value.
2. Check denial. A denied `meta` key gets the value `''`; its default is never read.
3. Otherwise read the default. `terms` uses `get_the_term_list( $post->ID, $key, '', ', ', '' )`, guarded by `is_object_in_taxonomy()`.
4. If the key is not denied, apply the generic filter `theshed_resolve_placeholder`, then coerce to a string.
5. Apply the specific filter `theshed_resolve_placeholder_{$ns}_{$key}`, then coerce to a string. This filter runs for a denied key too.
6. For a `filter` token with no generic and no specific callback, call `_doing_it_wrong()`.
7. Escape once, by namespace type, and store the result in the memo.

Coercion: a scalar becomes a string; anything else becomes `''`.

Both filters take `( $value, $namespace, $key, WP_Post $post )`, as the hook docblocks say. The code names the second parameter `$ns`.

## Resolver rules worth knowing

- **Denied meta.** A key in `PostType::PROTECTED_META_KEYS`, or any key `is_protected_meta()` reports, resolves to `''`. Both are checked, because a site can filter `is_protected_meta()`.
- **Unhooked filter token.** A `filter` token with no generic and no specific callback resolves to `''` and calls `_doing_it_wrong()` with version `1.0.10`. The notice shows only under `WP_DEBUG`. The version is a guess: specification lines 596–598 say to change it if the release that ships the resolver is not 1.0.10.
- **Memo.** A static array keyed by `[(int) $post->ID][$ns][$key]`, shared by head and body in one request.
- **Invalid UTF-8.** `preg_replace_callback()` fails and `replace()` returns the content unchanged.

## Call sites

| Path | Method | Line | Behaviour |
|---|---|---|---|
| Story head | `StoryKses::echo_meta_tags()` | 494 | Prints `<meta>` tags only; resolves at line 510 |
| Story body | `StoryKses::echo_extract_and_enqueue_assets()` | 438 | Resolves at line 448; echoes raw at line 456 |
| Front end, head | `Templates::single_head()` | 138 | Passes `get_post()` |
| Front end, body | `templates/single-tse-story.php` | 70 | Passes `get_post()` |
| Admin preview | `assets/admin/partials/preview-innerhtml.php` | 12, 22 | Passes `$post` from `PostPreview` |
| Live preview | `LivePreview::filter_meta()` | unchanged | Swaps `story_body`, `story_head` and `story_version`; the front-end template then resolves |

- Story head: each string attribute value is decoded, resolved, then escaped again with `esc_attr()` at line 513.
- Story body: `<script>` and `<style>` are taken out before resolving. Line 455 holds the phpcs:ignore comment "Trusted Shorthand content".

Line numbers in `StoryKses.php` are for commit `c0aadb6`.

## End-to-end results

All three render paths passed on 2026-10-04 with the child theme active. The site ran WordPress 7.1.2 on PHP 8.3.28 in the container `dev_wordpress6_8_php8_3`. The container is built on the `wordpress:6.8-php8.3` image, but serves the WordPress core from the pla-2789 worktree's `data/wordpress`.

| Path | Tokens resolve | Child-theme filters run |
|---|---|---|
| S4W admin preview | Yes | Yes |
| Live preview (`?preview=true`) | Yes | Yes |
| Published front end | Yes | Yes |

What each token did:

- **Resolved, with filters applied:** `story.title`, `story.date`, `story.date_iso`, `parent.url`, `meta.e2e_subtitle`, `terms.category`, `terms.post_tag`, `filter.byline`, `filter.markup` (markup printed as escaped text).
- **Printed as empty:** a denied plugin key (`meta.story_id`), an underscore key (`meta._edit_lock`), and `filter.unhooked`.
- **Stayed literal:** a mistyped token, tokens inside `<script>` and `<style>`, and encoded braces (`&#123;&#123;…&#125;&#125;`) in the body.
- **Head:** encoded braces in a `<meta>` value resolve, as the specification says.
- **Notice:** `_doing_it_wrong()` fired for `filter.unhooked` with the right hook name and version, once the generic callback was removed.

The 2026-10-04 runs used stand-ins, not a story typed in the Shorthand editor:

- **Previews:** the tokens were added in memory to dylan's real preview response, keys `head` and `article`, through an `http_response` filter.
- **Published page:** the tokens were written into the stored `story_head` and `story_body` meta with `update_post_meta()` and `wp_slash()`, the same calls publish uses. The real story URL was then fetched.

## Child theme example

Path: `/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/data/e2e/theshed-e2e-child/`. Files: `style.css` and `functions.php`.

| Hook | Callback |
|---|---|
| `theshed_resolve_placeholder` | Trims string values |
| `theshed_resolve_placeholder_story_title` | Appends ` (child theme)` |
| `theshed_resolve_placeholder_meta_e2e_subtitle` | `strtoupper()` |
| `theshed_resolve_placeholder_filter_byline` | `'By '` plus the post author's display name |
| `theshed_resolve_placeholder_filter_markup` | Returns markup, to prove text escaping |

The theme's parent is `twentytwentyfive`.

## Checks

| Check | Result |
|---|---|
| PHPUnit, PHP 8.5.5, host | 669 tests, 1648 assertions, OK. Re-run on 2026-10-08 just before commit `c0aadb6` |
| PHPUnit, PHP 8.3.28, `wordpress:6.8-php8.3` container | 669 tests, 1648 assertions, OK |
| Detection tests, PHP 8.3.33, wp-env, 2026-10-03 | Pass |
| PHP 7.4 | Tests not run, by the user's instruction. `php -l` passes on the 7 changed source files, on PHP 8.5.5 only |
| phpcs, changed source files | 19 errors, 8 warnings, all also on origin/master |
| phpcs, new tests | 35 doc-comment findings, the same kinds the existing tests have |
| Specification review | No findings |
| Security review | 2 confirmed gaps |

`StoryPlaceholders.php`, the template and the partial are clean under phpcs. The phpcs ruleset includes PHPCompatibility with `testVersion` `7.2-`, and it reports nothing new on the branch.

The suite grew from 444 to 669 tests: 213 resolver tests, 11 render tests and 1 template test.

Deprecations are all old. PHP 8.5.5 shows 3: two `setAccessible()` calls in `tests/WordPressTestCase.php` (lines 34 and 49) and one `rawurlencode(null)` at `Shorthand.php:190`. PHP 8.3.28 shows only the `Shorthand.php:190` one. PHPUnit also prints 72 doc-comment metadata deprecations, the same style as the existing tests.

## Security review: areas with no finding

The security review found no problem in these areas:

- re-resolution of a resolved value;
- memo leaks between posts;
- a bypass of the denied meta keys;
- render paths with no post;
- escaping drift between namespaces and call sites.

Regex cost is acceptable on PHP 8.5.5 for content up to 200,000 characters. It was not checked on PCRE2 10.33, which PHP 7.4 bundles.

## How to run the checks

The unit suite needs a `php/vendor` folder. The primary checkout has one. Symlink the primary checkout's `php/vendor` into the worktree:

```bash
ln -s /Users/simon/Repos/Shorthand/shorthand_editor_wordpress/php/vendor "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/vendor"
```

Run the suite on the host:

```bash
cd "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php" && XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result 2>&1 | grep -v Xdebug
```

Run the suite on PHP 8.3, in the `wordpress:6.8-php8.3` image. This needs no symlink: it mounts the primary checkout's `php/vendor` read-only.

```bash
docker run --rm --pull never --network none -v "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/src:/app/src:ro" -v "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/tests:/app/tests:ro" -v "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/phpunit.xml.dist:/app/phpunit.xml.dist:ro" -v "/Users/simon/Repos/Shorthand/shorthand_editor_wordpress/php/vendor:/app/vendor:ro" -w /app --entrypoint php wordpress:6.8-php8.3 -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result
```

Run phpcs:

```bash
cd "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php" && XDEBUG_MODE=off php vendor/bin/phpcs --standard=.phpcs.xml -q src/lib/Services/StoryPlaceholders.php
```

Remove the symlink before any commit. It must never be committed:

```bash
unlink "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/vendor"
```

After a rebase onto `3f68ea4`, `pnpm test:php` also runs the unit suite, and `pnpm env:test` then `pnpm test:php:integration` runs the integration suite.

## Security gap 1: URL and event attributes

Severity: High.

A text token (`meta`, `story` or `filter`) used as a whole `href` or `src` value keeps a `javascript:` scheme. `esc_attr()` only encodes quotes and angle brackets; it does not check URL schemes. Event-handler attributes (`on*`) have the same gap. A token in a `style` attribute may have a similar gap; that case is untested, and Option A covers it.

Authors put a token in `href` in normal use: the specification's section "Known gaps: authoring" says a Shorthand "Dynamic URL" link puts a token straight into `href`.

Who can exploit gap 1: a user who can edit the story's custom fields but has no `unfiltered_html`. Examples:

- an Author assigned to the story;
- most roles on multisite;
- every role when `DISALLOW_UNFILTERED_HTML` is set.

The head is safe: it prints only `<meta>` tags, and every value is escaped with `esc_attr()`.

## Security gap 2: terms in attributes

Severity: Medium.

A `terms` token inside an attribute value can break the element. `get_the_term_list()` returns link markup with double-quoted attributes. So:

- in a double-quoted or unquoted attribute, the element breaks for any term name;
- in a single-quoted attribute, the element breaks only when a term name contains `'`.

Checked with a real HTML5 parser (PHP `Dom\HTMLDocument`, script `breakout.php`), using a harmless `data-injected` marker attribute only. The script covers double- and single-quoted attributes. An unquoted attribute value ends at the first space, so it breaks the same way.

- **Double-quoted or unquoted attribute:** the link's `rel` attribute lands on the author's element, and stray text shows on the page. No attacker is needed.
- **Double-quoted attribute, term name containing `"`:** the name ends up as page text. It does not add attributes. The security review's report was wrong on this point.
- **Single-quoted attribute, term name containing `'`:** the name can add attributes.

Who can add attributes: a user with `manage_categories` but no `unfiltered_html`.

WordPress facts behind the finding, checked in the `wordpress:6.8-php8.3` image source:

- `pre_term_name` runs `sanitize_text_field`, `wp_filter_kses` and `_wp_specialchars()` with its default `ENT_NOQUOTES`, so saved term names keep their quotes.
- `get_the_term_list()` prints the raw `$term->name` (`category-template.php:1356`).

## Fix options

The user has not chosen.

**Option A (recommended).** In the body, resolve tokens inside attributes by context:

- a `terms` token becomes plain term names;
- in a URL attribute (any name in `wp_kses_uri_attributes()`), the value becomes `''` when its scheme is not in `wp_allowed_protocols()`;
- in an `on*` or `style` attribute, a token prints as `''`.

Option A needs a tag parser. Two ways:

- `WP_HTML_Tag_Processor`: needs WordPress 6.2, so "Requires at least" rises from 6.0 to 6.2 in the plugin header and `php/src/readme.txt`. The plugin does not use it anywhere today. Upstream `bin/wp-matrix.sh` tests down to 6.0, so the matrix floor changes too.
- A small scanner written for the plugin.

One trap: `WP_HTML_Tag_Processor::get_attribute()` decodes entities. Resolving the decoded value would make encoded braces in a body attribute resolve, which breaks the specification's rule that encoded braces stay literal in the body.

**Option B.** Change the specification only: correct the claim at line 412, add an authoring rule, and add an accepted risk. Gap 1 stays open.

Under either option, specification line 412 is wrong today. It claims `esc_attr()` "covers a token wherever it sits".

## Blocked: a real story

The done criteria ask for a real Shorthand story, typed in the editor, then previewed and published. That run has not happened.

Why: local dylan returns 403 on the story content fetch, because the local organisation lacks the "story-api" feature flag. A real publish needs that fetch.

The permission check denied two actions. Neither may be worked around, by any route:

| Denied action | Why |
|---|---|
| Reading dylan's process environment or database connection, or turning on "story-api" | Reads dylan's secrets and changes its database |
| `POST /v2/stories/gaQ9f3UeUq/settings` | Changes a dylan story ("Modify Shared Resources") |

The user must pick one:

- **(a)** The user turns on "story-api" for the local organisation `R0svqOk1hz`.
- **(b)** The user allows a change to a local dylan story.
- **(c)** The user signs in to local dylan in the browser pane, and the agent types the tokens in the Shorthand editor.

The Shorthand MCP server points at production. Correct: use local dylan for the real-story run. Incorrect: using the Shorthand MCP tools.

## Rebase onto origin/master

A rebase onto `origin/master` (`3f68ea4`) conflicts in one file, `php/src/lib/Plugin/PostType.php`, from commit `c0aadb6`. Resolve it this way:

1. Keep `PostType::PROTECTED_META_KEYS`, and add `story_title_pending` to it (12 keys).
2. Keep upstream's `register_post_meta()` call for `story_title_pending`.
3. In the specification, change "eleven keys" to "twelve keys" at line 687, and add `story_title_pending` to the key list at lines 689–692.

Why the conflict: upstream adds `story_title_pending` to the inline protected list in `is_protected_meta()`, and registers it with `register_post_meta()`. The branch moves the inline list into `PostType::PROTECTED_META_KEYS`.

`origin/master` moved from `1d847ea` to `3f68ea4`:

- `10933f5` 🐳 Replace docker-compose with wp-env and gate releases on PHP tests (#30)
- `3f68ea4` 🔌 Save WordPress properties without a Shorthand connection (PLA-2806) (#29)

A dry-run merge (`git merge-tree`) on 2026-10-08 finds only that conflict. The doc commits merge cleanly.

After the rebase, CircleCI runs `wp-plugin-php-unit` (PHP 8.4) and `wp-plugin-php-integration` on every branch, so a push runs the new tests in CI. Before the rebase, CI ran no PHP tests and no phpcs.

Correct: rebase only on the user's yes to the open decision "Rebase and push". Incorrect: rebasing or pushing because the tree is ready.

## End-to-end environment: current state

State on 2026-10-08:

- The container `dev_wordpress6_8_php8_3` runs on port 4577. It was recreated from the pla-2789 compose file alone, so it mounts the pla-2789 worktree's `php/src`, not this branch's code, and has no child theme mount.
- That compose file sets `WP_HOME` to `https://localhost:9443/wordpress`, so local dylan's HTTPS server serves the site, and `http://localhost:4577` redirects there.
- `restore.php all` ran on 2026-10-04. Post 6 meta matches the backup; `e2e_subtitle` is deleted; terms 3, 4 and 5 are deleted; the parent is 0; the theme is `twentytwentyfive`; the title is unchanged.
- The dylan story `gaQ9f3UeUq` was never changed.

Site facts:

- Post 6, "Add your title", is the published `tse_story` bound to Shorthand story `gaQ9f3UeUq`.
- Post 2 is the page the stand-in used as the story's parent.
- Local dylan serves `https://localhost:9443`; the plugin's API URL is `https://host.docker.internal:9443/api`. The local organisation is `R0svqOk1hz`.

Other local environments, all running on 2026-10-08:

| Port | Environment |
|---|---|
| 8888 | `wp-env-shorthand_editor_wordpress-test-wp-env-dev-environment-897e6d03`: WordPress 7.1, PHP 8.3.33, not connected to dylan |
| 8890 | wp-env, plugin-integration worktree |
| 8891 | wp-env, yoast-rankmath-support worktree |
| 8898 | wp-env, `site-2e31b2e2` |
| 4580 | `s4w-stateless-harness` |
| 8443, 8446 | Caddy HTTPS proxies |

## End-to-end environment: run and tear down

Start the stand-in container from the pla-2789 compose file plus the worktree's override:

```bash
docker compose -f "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-pla-2789-coverimage/docker-compose.yml" -f "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/data/e2e/scripts/override.yml" up -d --no-deps --no-build --pull never wordpress6_8_php8_3
```

The override mounts the worktree's `php/src` as the plugin, mounts the child theme read-only, and sets `WP_HOME` and `WP_SITEURL` to `http://localhost:4577/wordpress`. The built JavaScript came from the pla-2789 worktree's `public/`; the JavaScript sources match.

Docker cannot see the session scratchpad. Mounted files must live under the worktree.

Teardown, in order:

1. In the container, run `php /tmp/restore.php all`. It reads `/tmp/post6.backup.json`; if the container was recreated, copy the backup in first (see "End-to-end scripts").
2. Recreate the container from the pla-2789 compose file alone.
3. Remove the two empty mount-point folders that Docker created.
4. Check that `http://localhost:4577` answers 301, and the story's permalink on `https://localhost:9443/wordpress` answers 200.

Step 2 command:

```bash
docker compose -f "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-pla-2789-coverimage/docker-compose.yml" up -d --no-deps --no-build --pull never wordpress6_8_php8_3
```

Step 3 command:

```bash
rmdir "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-pla-2789-coverimage/data/wordpress/wp-content/themes/theshed-e2e-child" "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/php/src/public"
```

## End-to-end scripts

Path: `/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/data/e2e/scripts/`. Git ignores the folder.

| File | Purpose |
|---|---|
| `override.yml` | Compose override for the stand-in container |
| `state.php` | Prints theme, plugins, admins and every `tse_story` |
| `setup.php` | Backs up post 6 and prepares the site for a run |
| `preview.php` | Runs the S4W admin preview as user 1. Usage: `php /tmp/preview.php 6 [inject]`; the second argument `inject` adds the tokens |
| `live-preview.php` | Renders `?preview=true` as user 1. Argument `inject` adds the tokens |
| `inject-meta.php` | The publish stand-in; also checks the `_doing_it_wrong()` notice |
| `restore.php` | `story` restores head and body meta; `all` restores everything |
| `sh-api.php` | Calls the Shorthand API through the plugin's own client; the Shorthand API token stays inside WordPress |
| `e2e-head.html`, `e2e-body.html` | The token snippets |
| `breakout.php` | The HTML5 parser check for gap 2, with a harmless marker |

`setup.php` backs up post 6 to `/tmp/post6.backup.json`, switches to the child theme, adds `e2e_subtitle` meta and the E2E terms, and sets the parent to post 2.

Each PHP script except `breakout.php` runs inside the container. The HTML snippets must be copied to `/tmp` in the container too.

```bash
docker cp "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/data/e2e/scripts/state.php" dev_wordpress6_8_php8_3:/tmp/state.php && docker exec -e XDEBUG_MODE=off dev_wordpress6_8_php8_3 php -d display_errors=stderr /tmp/state.php
```

`breakout.php` needs no WordPress. It runs on the host with PHP 8.4 or later, for `Dom\HTMLDocument`:

```bash
php "/Users/simon/Projects/Shorthand for WordPress/shorthand_editor_wordpress-test-story-placeholders/data/e2e/scripts/breakout.php"
```

The in-container backup is gone: the container was recreated. Copies are in the build session's scratchpad, under `/private/tmp`, which is not durable:

- `/private/tmp/claude-501/-Users-simon-Repos-Shorthand-shorthand-editor-wordpress/091b2e10-7a59-402f-a498-dadc95ff74b6/scratchpad/e2e/backup/post6.backup.json`
- `settings-gaQ9f3UeUq.before.json` in the same folder: the settings of dylan story `gaQ9f3UeUq`, saved before the denied settings change.

## Earlier agent commits

A workflow agent in the build session ignored its read-only research task and made two commits, neither requested:

- `48a3a8c` on `test/story-placeholders`: docs only. Kept.
- `14610b5` in `/Users/simon/Projects/Shorthand for WordPress/`: the root commit, 40 files, local only.

Before `14610b5`, the project folder repository had no commits and 37 files staged. The commit added three files that were not staged:

- `plans/story-placeholders-full-language.md`
- `research/superseded/2026-09-11-dynamic-content-handoff-element-placeholders.md`
- `research/superseded/2026-09-24-caching-the-story-render-element-placeholders.md`

It also committed working-tree versions of three files whose staged versions were older: `docs/story-template-resolution.md`, `research/caching-the-story-render-handoff.md` and `research/dynamic-content-handoff.md`.

Cause, verified: the Workflow harness relays the latest user message to every workflow agent as "[Workflow harness — user request]", and that message wins over the agent's own task. The latest message then was the user's "commit for posterity" request.

Correct: when the latest user message asks for commits or file changes, do the work solo, or use Agent-tool subagents with explicit read-only rules.
Incorrect: launching a Workflow while the latest user message asks for an action.

## Undo steps for 14610b5

Run only if the user says yes. The steps return the project folder repository's branch and index to their state before the commit: no commits and 37 files staged. The steps do not touch files on disk.

```bash
cd "/Users/simon/Projects/Shorthand for WordPress" && git update-ref -d refs/heads/master
```

```bash
cd "/Users/simon/Projects/Shorthand for WordPress" && git rm --cached plans/story-placeholders-full-language.md research/superseded/2026-09-11-dynamic-content-handoff-element-placeholders.md research/superseded/2026-09-24-caching-the-story-render-element-placeholders.md
```

```bash
cd "/Users/simon/Projects/Shorthand for WordPress" && git update-index --cacheinfo 100644,da8a099d38813216fbac9be55b43e76acd6d7f9c,docs/story-template-resolution.md --cacheinfo 100644,94369b6c3b0bec271a29dfd2dd35c09f89908a19,research/caching-the-story-render-handoff.md --cacheinfo 100644,8fcfc6f080fa30e100be08e9aa8668f3b3690c4d,research/dynamic-content-handoff.md
```

The three blobs still exist in the repository's object store, checked on 2026-10-08.

What the undo leaves, because the documents moved into the plugin worktree on 2026-10-08:

- `research/caching-the-story-render-handoff.md` and `research/dynamic-content-handoff.md` show as staged and deleted on disk.
- The three files removed with `git rm --cached` are already gone from disk.
- The project `AGENTS.md` keeps its unstaged edit: the pointer to this file.

The project folder's `git status` and staged diffs from before the undo plan are saved in the build session's scratchpad as `root-status-before.txt` and `root-diffs-before.txt`.

## Rules for the next session

- Commit or push only when the user asks. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Pull request bodies end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- Never commit the `php/vendor` symlink or anything under `data/`. Correct: unlink `php/vendor` first, and stage files by path.
- Never change a `tse_story` post title in a script: S4W pushes the title to dylan. Correct: put test values in post meta, such as `e2e_subtitle`.
- Never read, print or move the Shorthand API token. Correct: call the API through `sh-api.php`, which keeps the token inside WordPress.
- Never forge dylan sessions or type the user's real passwords. The user signs in to dylan.
- No downloads or Docker image pulls without permission. Correct: `--pull never` and `--no-build`.
- Do not modify other worktrees' tracked files. Correct: change a container through a compose override passed with a second `-f`.
- Do not write exploit payloads in tests, docs, environments or chat replies. The permission classifier stopped two chat replies for this. Correct: a harmless marker such as `data-injected`.
- Code style: PHP 7.2 syntax (`.phpcs.xml` `testVersion` `7.2-`; rector `withDowngradeSets( php72: true )`), text domain `the-shorthand-editor`, hook prefix `theshed_`.
- Do not launch a Workflow while the latest user message asks for commits or file changes. Correct: work solo, or use Agent-tool subagents with explicit read-only rules.
- A subagent cannot grant permission, and its findings need checking before they are reported.

## Open decisions

- **Security:** option A or option B? See "Fix options".
- **Real story:** (a), (b) or (c)? See "Blocked: a real story".
- **Rebase and push:** rebase `test/story-placeholders` onto `origin/master`, resolve `PostType.php` as "Rebase onto origin/master" says, and push? Optionally reword `48a3a8c` first; see "Commits on the branch".
- **Project folder:** undo `14610b5`? See "Undo steps for 14610b5".

## Notes

| Item | Note |
|---|---|
| `_doing_it_wrong()` version | `1.0.10` is a guess; change it to the release that ships the resolver |
| `wp.meta.<key>` case | Case-sensitive: `get_metadata_raw()` checks `isset( $meta_cache[ $meta_key ] )` (`wp-includes/meta.php:659`). Specification lines 259 and 807 still say it depends on collation. Specification fix pending, not applied |
| `Templates::single_head()` | No unit test; the stand-in runs covered it |
| `echo_extract_and_enqueue_assets_kses()` | Unused since 1.0.0; left alone |
| `Shorthand.php:190` deprecation | Old; shows only in tests, when `get_token_team_id()` returns `null` |
| PHP 7.4 | Detection tests not run on 7.4; the specification lists it as a known gap |
| Specification "PHP 8.3.33" claim, line 880 | Verified: the detection tests passed in the wp-env CLI container on 2026-10-03, PCRE2 10.42 |
| `readme.txt` changelog | Not updated; changelog lines land with the version bump |
| MCP servers | logrocket needs sign-in in the claude.ai connector settings; MCP_DOCKER failed to connect |
| Build session transcript | `/Users/simon/.claude/projects/-Users-simon-Repos-Shorthand-shorthand-editor-wordpress/091b2e10-7a59-402f-a498-dadc95ff74b6.jsonl` |
