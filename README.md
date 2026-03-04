# WordPress plugin - "The Shorthand Editor"

A WordPress plugin by [Shorthand](https://shorthand.com).

## For WordPress Users

If you're looking for installation instructions, FAQs, and
usage documentation, please see the
[WordPress plugin readme.txt](php/src/readme.txt) file,
which follows the WordPress plugin directory format.

This plugin is not yet intended to be a replacement for the
existing Shorthand Connect plugin, and upgrades to a Shorthand
workspace will be required for continuity between the two plugins.

## Motivation and Goals

The new Shorthand plugin for WordPress is called "The Shorthand Editor".
It forms a new method of _distribution_ for Shorthand, as a drop-in,
full-page editor replacement for WordPress sites.

The plugin will be available for download from the WordPress plugin
directory. During installation, a WordPress admin will be required to
sign in to Shorthand. Authoring and editing will be free; publication will
require story credit or billing.

At this stage it is not a replacement for the existing WordPress plugin,
"Shorthand Connect", which comprises bespoke development for a number of
customers.

## Distribution

The plugin itself may be found on the
[Shorthand website](https://shorthand.com/products/shorthand-for-wordpress)
as a ZIP download, for manual installation.

## Installation and setup

Once installed by a WordPress administrator, the admin should connect with
Shorthand.

1. Visit the plugins page
2. Locate the plugin, "The Shorthand Editor"
3. Ensure the plugin has been activated (and do so if not)
4. Click the `Connect to Shorthand` link
5. When redirected to Shorthand, log in, if not already
6. Select or create a Shorthand workspace that will hold the WordPress team
7. Select an existing WordPress team, or create a new one
8. Navigate back to WordPress (using the previous credentials)

From this point, a user with editing permissions in WordPress will be able to
see the `Stories` list in the WP admin dashboard, containing:

- All Stories
- Add Story
- Categories
- Tags

The first item shows all WP Stories; the second allows the user to create a
new Shorthand Story, associated with a WordPress post.

The final items refer to the usual taxonomies afforded WordPress items.

## Stories in WordPress

Choosing `Add Story` redirects the user to a session in Shorthand without
needing to log in. They will be presented with the template selection
screen, where they may elect to use the AI Companion to create a new
story, or to create one on their own.

When editing a Story in WordPress, the editor will contain a large preview panel,
similar to the Preview functionality in Shorthand. Above the preview will be
a panel that

- shows errors (with tooltips) from during publishing
- allows the user to navigate to Shorthand via `Edit With Shorthand`
- indicates the story status, e.g. unpublished changes

The user can preview the story via the usual WP preview button.

Choosing `Edit With Shorthand` will redirect the user to a session in Shorthand,
still not needing to log in.

The following tools will have been removed from the editor for stories in a team
associated with a WordPress team:

- collaborators
- comments
- publishing
- preview
- story settings
- user profile menu
- breadcrumbs (the story title is shown in this position on the bar)

Additionally, the return to dashboard button is disabled.

### Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

## License

This project is licensed under the GNU General Public License
v3.0. See [LICENSE](LICENSE) for the full license text.

## Trademark

"The Shorthand Editor", "Shorthand", and associated logos are
trademarks of Shorthand Pty Ltd. See [TRADEMARK.md](TRADEMARK.md)
for usage restrictions. The GPLv3 does not grant rights to use
these trademarks.
