---
title: Publishing a story
purpose: The end-to-end path from a Shorthand story archive to a rendered WordPress post.
updated: 2026-11-26
---

# Publishing a story

Publishing downloads a story archive from Shorthand in chunks, unpacks it, and
copies only the changed files into the uploads directory. It is driven by
WP-Cron and is always asynchronous.

Entry point: `Shorthand\Services\PostAPI::pull_story_begin()`, scheduled from
`Shorthand\Admin\Editor::wp_insert_post_data()`.

## When a pull is scheduled

`Shorthand\Admin\Editor::wp_insert_post_data()` runs on every save of a story
post. Only a save with editor intent can schedule a pull; see § Save intent.
A save with intent schedules a pull only in these cases, and only while the
auth state is `connected`:

| Save | Pull |
| --- | --- |
| Status moves into `publish` or `future` from any other status | Always |
| Already published, `story_update_error` post meta is set | Always, as a retry |
| Already published, `story_version` post meta is absent | Always |
| Already published, `story_update_state` post meta is set | Never while `Shorthand\Services\StoryPullWatch::check()` reports it running or resumed; always once the watch fails it |
| Already published, Shorthand reports a different `contentVersion` | Always |
| Already published, Shorthand reports the same `contentVersion` | Never |
| Already published, the version check itself fails | Never; the save stores a `story_update_notice`, "Could not check Shorthand for updates" |
| Status leaves `publish` and `future` | Never |

The content version is read from `GET /v2/stories/{id}/settings` by
`Shorthand\Services\Shorthand::get_story_version()`. That request generates no
archive, so it does not count against the workspace's concurrent build cap.

## Save intent

Only the edit screen, Quick Edit and bulk edit may start a pull, check
Shorthand for a new version, or push the title. Any other save of a story
post, by another plugin or by core outside those screens, never calls
Shorthand.

### How intent is marked

Each screen prints a nonce in the request field `shorthand_story_intent`:

| Screen | Request | Post match | Nonce action |
| --- | --- | --- | --- |
| Edit screen | `$_POST['action']` is `editpost` | `$_POST['post_ID']` | `shorthand_story_save_{id}` |
| Quick Edit | `$_POST['action']` is `inline-save` | `$_POST['post_ID']` | `shorthand_story_list_save` |
| Bulk edit | `$_REQUEST['bulk_edit']` is set, by GET | id in `$_REQUEST['post']` | `shorthand_story_list_save` |

- `Shorthand\Admin\Editor::edit_form_after_title()` prints the edit screen field.
- `Shorthand\Admin\StoryListColumn` adds a "Story" column to the stories list. It shows "Updating N%", "Update failed" or "Fetched". "Fetched" means a bundle was fetched, not that Shorthand has nothing newer. Core fires `quick_edit_custom_box` and `bulk_edit_custom_box` only for columns it does not own, so the column is where those hooks print the field. The field has no `id`, because core clones the template for each row.
- `Shorthand\Admin\StorySaveIntent::claim()` checks the request. Each post's intent is claimed once per request, so a nested `wp_update_post()` of the same post by another plugin finds it used.

### A save without intent

- No title push, and no held title. `Shorthand\Admin\AdminGateway::sync_post_title()` saves Shorthand's own title this way.
- No version check, and no pull.
- A status move into `publish` or `future` goes ahead when the post has `story_body`. Without it, the save keeps the prior status and stores the error "Open the story and click Publish to fetch it from Shorthand." While a pull is in flight, the save keeps the prior status and stores no error, so the pull's own outcome stays visible.
- `story_update_state`, `story_update_nonce` and `story_update_task` are never touched.

### Expired nonce

A nonce lives 12 to 24 hours. `Shorthand\Admin\Editor::refresh_nonces()` adds the field to core's `wp_refresh_nonces` `replace` list, at priority 11, so an open edit screen keeps a fresh nonce. An `editpost` save of a story into `publish` or `future` whose field is missing or fails stores a `story_update_notice`: "The page had expired, so the story was not fetched. Reload and click Update." It then counts as a save without intent.

### Notice

`story_update_notice` explains why the last save from the editor fetched nothing. Every save with intent clears it first. The editor receives it as `errors.notice` and shows it in the story status line.

### Autosave of a draft title

The heartbeat autosave of the author's own draft saves the title through `edit_post()`. `StorySaveIntent::is_autosave()` recognises it by the `update-post_{id}` nonce in `data[wp_autosave]`, and it counts as intent for the title only: the title is pushed, and no pull starts.

## Decision: a save without a connection blocks one thing only

When the auth state is not `connected`, a save writes every WordPress property
as normal: title, slug, author, excerpt, featured image, taxonomies, custom
fields, publish date, and status. No `story_update_error` is written and
`post_status` is not rewritten, with one exception.

The exception is a status moving into `publish` or `future` on a post whose
`story_body` post meta is empty. There is no bundle to serve, and none can be
fetched, so the save records an `auth` error reading "Cannot fetch the story
content from Shorthand" and restores the prior status through
`Shorthand\Services\PostAPI::get_restore_status()`.

A draft whose `story_body` is present publishes while disconnected, without a
pull. Its `story_version` stays absent, so the next connected save pulls a
fresh bundle.

Unpublishing never needs a connection.

## Decision: the title always saves, and Shorthand catches up

The WordPress title is the one property Shorthand also holds. A title change
in a save with intent is handed to `Shorthand\Services\StoryTitleSync::push()`:

- Connected and accepted: sent to `POST /v2/stories/{id}/settings`.
- Disconnected, or refused: held in `story_title_pending` post meta and shown
  in the editor toolbar as a known divergence.

A held title is pushed again on the next save of that post while connected,
and by `Shorthand\Services\StoryTitleSync::sweep()` when the auth state
changes to `connected`, which the `shorthand_auth_state_changed` action
announces. The sweep pushes the post's current title, not the held one.

## Directories

Four terms, each naming one thing, used nowhere else in this codebase with a
different meaning.

- **Pull directory** — the downloaded archive chunks for one pull. In uploads,
  at `shorthand/{post_id}/{story_id}_{nonce}/`, holding `file-0.part` …
  `file-{N}.part`. Deleted when the pull ends, whether it succeeds or fails.

- **Staging directory** — the assembled archive and the unpacked tree for one
  pull. On the local disk returned by `get_temp_dir()`, at
  `sh_pull_{nonce}_{random}/`, holding `archive.zip` and `unpacked/`. Lives for
  one request, and is never readable by a later one.

- **Bundle directory** — the published story files. In uploads, at
  `shorthand/{post_id}/{story_id}/`, one per story, overwritten in place on
  republish, kept until the post is deleted. Media sits at the paths the
  archive names; the two documents sit under `docs/{nonce}/`.

- **Manifest** — the name, size, and CRC32 of every file in the bundle
  directory, stored in the `story_manifest` post meta key. Shape:
  `docs/models/story-post-meta.md`.

## Sequence

1. `pull_story_begin()` requests a download URL and creates the pull directory.
   A `429` from Shorthand means the workspace is at its concurrent build cap;
   the publish fails at once, and the author chooses when to retry.
2. Successive WP-Cron events download parts of up to 25 MiB into the pull
   directory, several per event. See § Download steps.
3. The event after the last part concatenates the chunks into the staging
   directory, streaming each chunk so memory use does not grow with part size.
4. The archive is unpacked into the staging directory.
5. The unpacked tree is copied into the bundle directory, skipping files whose
   name, size, and CRC32 match the stored manifest. `article.html` and
   `head.html` are copied to `docs/{nonce}/`.
6. Files present in the manifest but absent from the archive are deleted.
7. `story_head` and `story_body` post meta are written, with asset URLs
   rewritten.
8. The story's plain text is mirrored into `post_content` and `post_excerpt`,
   so core search and listing views have something to read.
9. `Shorthand\Services\StoryCover::sync()` fetches the story settings and,
   where the featured image rule allows, imports the cover into the media
   library and sets it as the featured image. See the cover section below.
10. `story_manifest` post meta is written.
11. The staging directory and the pull directory are deleted.

Steps 5 and 6 run through `Shorthand\Services\FileSystemService`, never through
a direct `ZipArchive::extractTo()` into uploads. See
`docs/services/file-system.md`.

## Download steps

One WP-Cron event, `shorthand_pull_story_cron`, is one step. A step downloads
parts until the archive is complete or its time budget is spent, then
schedules the next step. The step after the last part assembles and unpacks
the archive, with a fresh budget. A 394 MB archive takes 16 parts, about 3 to
4 download steps and one assembly step on a fast link.

| Setting | Default | Filter |
| --- | --- | --- |
| Largest part | 25 MiB, `StoryUpdateTask::CHUNK_SIZE_MB` | `shorthand_pull_chunk_size`, in bytes; a value below 1 uses the default |
| First part, speed unknown | 5 MiB, `StoryUpdateTask::PROBE_SIZE` | None |
| Smallest part | 1 MiB, `StoryUpdateTask::MIN_SIZE` | None |
| Target time per part | 10 s, `StoryUpdateTask::TARGET_SECONDS` | None |
| Step budget | 15 s, `PostAPI::STEP_BUDGET` | `shorthand_pull_step_budget`, in seconds; capped at half of `max_execution_time` when PHP sets one |
| Part timeout | 30 s, `PostAPI::CHUNK_TIMEOUT` | None |

- A step always downloads at least one part. A budget of 0 gives one part
  per step, as in 1.0.11.
- The budget is a ceiling. After each part's file is closed, the step starts
  the next part only if it expects that part to end inside the budget, at the
  measured speed. With the speed unknown, it starts the next part while it is
  under the budget. On a remote uploads directory, closing the file uploads
  it, so the upload counts too.
- A part that is slower than expected, or that times out and is fetched again,
  can still take a step past its budget.
- With the 15 s budget, a step stays under a host that ends `wp-cron.php` at
  20 s of wall time.
- After each good part, the step stores the advanced task in
  `story_update_task`, with `attempts` 0. A step the host kills loses at most
  one part, and the watch resumes from the last part.

### Part size

Each part is sized to take `TARGET_SECONDS` at the measured download speed.
`StoryUpdateTask::size_next_window()` applies the rules, in order:

1. Speed unknown: `PROBE_SIZE`.
2. Otherwise speed × `TARGET_SECONDS`, at least `MIN_SIZE`.
3. At most the `shorthand_pull_chunk_size` cap. The cap wins over the probe
   and the floor.
4. Never past the end of the archive.

The speed is bytes of good parts divided by seconds spent in their requests,
over the step so far. It reads low, which is the safe direction: it includes
connection setup, and on a remote uploads directory it includes the upload
when the part's file closes.

The speed lives in two places:

- `speed` in the stored step, `story_update_task`, for the rest of the
  download. See `docs/models/story-post-meta.md`.
- The `shorthand_pull_speed` option, written once per step that measured a
  part. The first step of the next download starts from it when it is under
  7 days old. Otherwise the download probes. See `docs/models/options.md`.

| Site speed | Parts for 394 MB | First part | Settled part |
| --- | --- | --- | --- |
| 58 Mbit/s | 16 | 5 MiB | 25 MiB, the cap |
| 7 Mbit/s | about 48 | 5 MiB | about 8 MiB |
| 2 Mbit/s | about 160 | 5 MiB | about 2.4 MiB |

### Part checks and retries

Each part is checked before it counts:

- The `Content-Range` start must equal the requested start.
- The chunk file's size, read through
  `Shorthand\Services\FileSystemService::get_file_size()`, must equal the
  requested length, or what is left of the archive for the last range.

A part that fails either check is fetched once more in the same step.

A part whose request times out is also fetched once more, smaller. A timeout
is a `cURL error 28`, or an `http_request_failed` error whose message holds
"timed out".

1. The speed it reached is the partial file's size divided by
   `CHUNK_TIMEOUT`. A missing, empty or unreadable partial file halves the
   old speed instead.
2. The part is resized from that speed, and to at most half the part that
   timed out. `MIN_SIZE` and the cap still apply.
3. The step's speed totals restart, so earlier fast parts do not grow the
   next part straight back.

Other transport errors, such as a refused connection, are not retried.

A part makes at most two requests: a bad check and a timeout share the one
second try. A second failure of any kind deletes the chunk file and fails the
pull. The error is `chunk` for a bad check, or the transport error.

## Decision: unpack into a local staging directory, then copy

`ZipArchive::extractTo()` uses native syscalls and ignores PHP stream wrappers,
so it cannot write into a remote uploads directory at all. Extracting locally
first also makes the copy step a plain file-to-file copy on every host.

The `shorthand_disable_staging` option reverts to extracting straight into the
bundle directory, for sites where the extra local copy costs more than it
saves. `Shorthand\Services\Options::can_disable_staging()` withdraws the choice
where uploads are remote, and `is_staging_enabled()` then reports `true`
whatever the stored option says.

## Decision: the manifest is a copy diff, not a deletion ledger

Step 5 compares the archive manifest against the stored manifest per file:

| Case | Action |
| --- | --- |
| Name, size, and CRC32 all match | Skip |
| CRC32 differs | Overwrite |
| Absent from the stored manifest | Write |
| Absent from the archive | Delete |

CRC32 with size detects change between two exports of one story. It is not a
security boundary.

An absent manifest means copy every file. That is the state after upgrading
from a plugin version that did not write one, and it needs no migration.

`Shorthand\Services\BundleManifest::from_archive()` builds the archive side
from `ZipArchive::statIndex()`, which returns name, size, and CRC32 without
extracting.

## Decision: write the manifest after the copy, never before

Step 9 follows step 5. A stale manifest causes over-copying, which is safe. A
manifest written early would claim files were copied when they were not, and
the next publish would skip them permanently.

## Decision: version the two documents by pull nonce

The bundle path is a function of `(post_id, story_id)`, both fixed for the life
of the post, so a republish writes the same paths again. A remote uploads host
refuses a path after 2000 modifications. With the copy diff in place, only
these paths accrue any:

| Path | Accrues a modification |
| --- | --- |
| `assets/*`, `static/*` | On real edits only |
| `theme-{hash}.min.css` | Never; the file name carries a hash of its content |
| `docs/{nonce}/article.html`, `docs/{nonce}/head.html` | Never; the nonce is unique per publish |

`{nonce}` is the pull nonce, already stored in `story_update_nonce`. It is
always present and never repeats, unlike the content version, which is nullable
and repeats on a forced re-sync. It is interpolated into a path, so it is
validated with `Shorthand\Services\StoryId::is_valid()`; a nonce that fails
leaves the documents at the root of the bundle, where they sat before they were
versioned.

The previous publish's documents are removed by the copy diff, at a cost of two
deletes.

Moving the documents makes the manifest key differ from the archive name for
those two entries. `docs/models/story-post-meta.md` describes how that is
recorded.

Nothing in this plugin reads the documents back from disk — their content is
stored in `story_head` and `story_body`. They are written because their path is
the third argument of the `theshed_post_process_body` and
`theshed_post_process_head` filters.

A republish of an unedited story performs two writes and two deletes, both of
them documents, whatever the size of the story. See
`Shorthand\Tests\Services\PostAPIUnpackTest`.

## Cover image

Step 9 runs `Shorthand\Services\StoryCover::sync( int $post_id, string $story_id )`
inside `Shorthand\Services\PostAPI::extract_story_content()`. It never fails
the publish: a settings call, download, or sideload that fails leaves the
featured image as it was, and the next pull tries again. A save that schedules
no pull, as listed in "When a pull is scheduled", does not retry the cover.

The rule: the plugin writes the featured image only when it set the current
one, or when there is none. The five states and the two meta keys behind them
are in `docs/models/story-post-meta.md`.

The import, when it runs:

1. Refuse a cover whose `mime` is not `image/*`, or whose `size` is over the
   `theshed_cover_max_bytes` filter value, 20 MB by default.
2. Require `wp-admin/includes/file.php`, `media.php`, and `image.php`, which
   WP-Cron does not load.
3. `download_url()` to a temporary file, then `media_handle_sideload()` into
   the media library, attached to the post. A failed sideload deletes the
   temporary file.
4. Set `_wp_attachment_image_alt` from `meta.description`, when present.
5. `set_post_thumbnail()`, then write `story_cover` and `story_cover_attachment`.
6. `wp_delete_attachment( $previous, true )` when the plugin's earlier
   attachment was still the featured image. This runs after the thumbnail
   moves, because deleting an attachment clears every `_thumbnail_id` that
   still points at it.

The featured image lands after the post is already live, on the WP-Cron tick
that finishes the pull. A listing rendered in between shows no featured image.

### Local development

`download_url()` uses `wp_safe_remote_get()`, which refuses the Docker
environment's API on three counts: `host.docker.internal` resolves to a
private address, port 9443 is not on WordPress's safe port list, and the
ministack certificate is self-signed. Story content downloads use
`wp_remote_get()` and are not checked.

`Shorthand\Plugin\DevHttp::register()`, called from `Shorthand\Plugin::init()`,
adds the `http_request_host_is_external`, `http_allowed_safe_ports`, and
`https_ssl_verify` filters for the host and port in `THESHED_API_URL`. It
registers nothing unless `THESHED_NO_SSL_VERIFY` is true, which only
`docker-compose.yml` sets. Keep `download_url()`: the cover URL comes from an
API response, and the safe transport is what stops it reaching internal
addresses in production.

### Editor panel

In the classic editor, `Shorthand\Admin\Editor::admin_post_thumbnail_html()`
rebuilds the Featured image box with two tabs. "Shorthand cover", shown by
default, carries the cover image, a message saying what the next publish will
do, and a "Use story cover now" button. "Featured image" carries core's own
markup: the current featured image, the Set and Remove links, and the
`_thumbnail_id` form field. The first tab lays its image out as core lays out
the featured image, a paragraph holding an image with width and height
attributes, so when the featured image is the story cover, switching tabs
does not move the picture.

The box renders from `story_cover`. The client module
`src/post-shorthand-story/coverPanel.ts` then calls
`wp_ajax_shorthand_get_story_cover` once, sending the form's `_thumbnail_id`
as `thumbnail`, and repaints the first tab with the cover Shorthand reports
now. Core replaces the whole box when the author sets or removes a featured
image, passing the unsaved choice to the filter; the client then shows the
"Featured image" tab and refreshes again.

"Use story cover now" posts `wp_ajax_shorthand_import_story_cover`, which
checks the nonce and `Shorthand\Services\Permissions::can_pull_story()`, then
runs `Shorthand\Services\StoryCover::sync()` with `$replace = true`. The
import is saved at once, including `_thumbnail_id`. The client then calls
`wp.media.featuredImage.set()` with the new attachment so core redraws its
tab and the form field matches; a later save of the post cannot revert it.

Every panel payload carries `thumbnail`, the saved featured image ID. When
the story state poll in `src/post-shorthand-story/hooks/useStoryState.tsx`
sees a pull it was watching end, it fires the `shorthand:pull-ended` event
on `document`. The cover module then refreshes without the `thumbnail`
parameter, so the state is judged against what the pull saved, and calls
`wp.media.featuredImage.set()` when the saved ID differs from the form's.
The chosen tab stays as it was. The event fires on a failed pull too; the
refresh then shows the unchanged state.

## Pull tracking

A pull directory cannot be listed, so the `story_pulls` post meta key records
what each pull left behind: `{path, files}` per request nonce.

- `pull_story_begin()` sweeps every entry that is not its own nonce, unlinking
  `file-0.part` … `file-{files-1}.part` by name, then records its own.
- `pull_story_chunk()` raises the recorded count after each chunk lands. The
  count never goes down: a step resumed from the stored task downloads again
  over chunks that are already there.
- A step downloads several chunks, so the recorded count can be ahead of the
  `files` in the stored step. `pull_story_cleanup()` and `abandon_pull()` use
  the larger of the two.
- `pull_story_cleanup()` unlinks the chunks and drops the entry, on both a
  successful and a failed pull.

A superseded pull returns early without cleaning up. Its entry survives until
the next `pull_story_begin()` sweeps it.

## Pull watch

A pull step can die without a trace. After MySQL "Commands out of sync", every
write in that request fails: progress, the next step's schedule, and any error
the step tries to store. Only a later request can notice.
`Shorthand\Services\StoryPullWatch::check( int $post_id )` is that check. It
returns `idle`, `running`, `resumed` or `dead`.

Two records make it possible:

- Every progress write carries `updated_at`, the heartbeat. Steps write one at
  0% on each `202` while Shorthand builds the archive, after each chunk, before
  the join, before the unpack, and before the copy into uploads.
- `Shorthand\Services\Cron` stores each step in `story_update_task` before
  scheduling it, and each step stores it again after each part.

The rules, in order:

1. No progress, or an error is set: `idle`. A failed pull's progress is deleted.
2. Progress without `updated_at`, written by 1.0.11: stamp it, `running`.
3. Heartbeat younger than `STALE_AFTER` (300 s): `running`.
4. The stored step is still queued and the heartbeat is younger than
   `GIVE_UP_AFTER` (1800 s): `running`.
5. A stored step with `attempts` 0: schedule it again, `resumed`. A step that
   is still queued, past `GIVE_UP_AFTER`, counts as resumed too: its event
   exists. It then has one more `GIVE_UP_AFTER` to run.
6. Otherwise `Shorthand\Services\PostAPI::abandon_pull()` stores a `stalled`
   error, restores the prior status when a step was stored, deletes the chunks
   and rotates the nonce: `dead`. The `pretty` message says "Click Publish to
   try again." when the post goes back to a status other than `publish` or
   `future`, and "Click Update to try again." otherwise.

Rules 5 and 6 run only under the post's step lock (`StoryPullWatch::LOCK_LIMIT`,
60 s). A running step holds the lock, and so does another check that is acting
on the pull, so a check that cannot take it returns `running`. Under the lock
the check clears the post's meta cache and the `cron` option cache, reads the
pull again, and acts only if rules 1 to 4 still do not apply. Progress that
cannot be read is deleted, so the sweep stops finding the post.

Three readers run the check:

- The editor's state poll, `wp_ajax_shorthand_get_story_state`, and the
  editor page load, for users who pass `can_pull_story`. The page load check
  runs on `load-post.php`, before `post.php` reads the post, so the publish
  box shows the status a failed pull put back.
- `Shorthand\Admin\Editor::needs_pull()`, on every save with intent of a published post.
- The recurring `shorthand_pull_watch` event, every 5 minutes, which checks up
  to 20 posts with `story_update_state` and unschedules itself when none is
  left. `Cron::schedule_pull_story()` schedules it when it is missing.

`Cron::queue_step()` schedules every step and every resume. It clears the
`alloptions` and `cron` cache entries first, because core saves the whole event
list from the request's cached copy: a step that ran for a minute would drop
events other requests saved since, and put back events another `wp-cron.php`
run already ran. A failed schedule is read back once and retried once. An
event that is already queued counts as scheduled. The first step of a pull and
the watch event also read the event list fresh before they are scheduled.

## Step lock

Only one step of a post's pull runs at a time. Two cron runners can pick the
same event, and the watch can resume a step that was only slow.
`Shorthand\Services\Cron::pull_story_cron()` runs each step in this order:

1. Take the lock, `Shorthand\Services\StoryPullLock::acquire()`. The lock is
   the option row `shorthand_pull_lock_{post_id}`, inserted with
   `INSERT IGNORE`. Its value is its expiry time:
   `PostAPI::get_step_limit()` seconds from now, 195 s by default. That is the
   step budget plus one range fetched twice, each try waiting up to the 30 s
   request timeout and 60 s to close the chunk file.
2. Clear the post's meta cache, then ask `PostAPI::is_current_step()`. A step
   that is not current exits.
3. Run the step, then store and schedule the next one, or fail the pull.
4. Release the lock in a `finally` block. The release deletes the row only if
   it still holds this step's expiry.

The step hands `PostAPI::pull_story_cron()` a guard. The guard clears the
post's meta cache, asks `is_current_step()`, then renews the lock with
`StoryPullLock::renew()`, a compare-and-set on the expiry. It runs:

- after each part request returns, before a retry, a file delete or any write;
- in the assembly step after each heartbeat, before each file the copy into
  uploads writes, before stale files are deleted and before the content and
  version are stored;
- in `Cron` before the next step is scheduled or the pull is failed.

A step whose guard fails stops at once and writes nothing more: a newer pull
started, or another run of the step took the lock over after this one was
paused past its expiry. A slow assembly step keeps its lock, because every
file it copies renews it.

A step that cannot take the lock does not run. If it is still current, it
schedules its own event again 30 s later. It does not store the task: that
would overwrite a newer step the holder stored and reset the watch's
`attempts`.

An expired lock is taken over with an `UPDATE` that matches the expired value,
so only one of two waiting steps gets it. A lock left by a killed step
therefore holds for at most 195 s. Every lock query is direct SQL, because the
options cache would not see another request's insert or delete.

`is_current_step()` is false when:

- the nonce moved on to a newer pull;
- the stored task has the same nonce and a later `start`, so this event ran
  twice or late;
- no task is stored and the pull has no progress, or has an error. The pull
  has ended. A late copy of its last step would find the chunks gone and fail
  a finished pull.

A pull started by 1.0.11 stored no task. Its steps still run while it has
progress and no error.

## Removing a bundle

Deleting a post runs `Shorthand\Services\PostAPI::delete_story_bundle()`, which
tries `delete_tree()` and falls back to `delete_manifest()` where the host
refuses it. The `{post_id}` parent directory is left in place: it cannot be
listed, so it cannot be known to be empty.

## Rejected designs

Neither of these is present in the code.

- **Versioned bundle directories.** A fresh directory per publish makes every
  file new, which defeats the copy diff at step 5. Splitting the layout so that
  documents are versioned and `assets/` is shared would work, because
  `Shorthand\Services\StoryContentTransformer::rewrite_story_bundle_paths()`
  already rewrites the two prefixes separately. Unversioned bundling is the
  existing behaviour, and was kept.

- **Per-file atomic replacement.** Copying each changed file to a temporary
  name and renaming it would remove the truncated-file failure mode, at the
  cost of a second cleanup path for abandoned temporary files.
