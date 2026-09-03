# Lior Jewellery — Site Toolkit

One plugin, one module per feature. This is where all custom lior-jewellery.com
functionality lives from now on, instead of a new standalone plugin per feature.

## How it works

- `lior-toolkit.php` is the only file WordPress needs to know about. It just
  auto-loads everything in `includes/modules/`.
- Each feature is its own file in `includes/modules/`, named after what it
  does. A module is a normal PHP file with hooks/functions in it — no class
  or registration boilerplate required.

## Adding a new feature

1. Create a new file in `includes/modules/`, e.g. `includes/modules/auto-print.php`.
2. Start it with `defined( 'ABSPATH' ) || exit;` (same as the existing module).
3. Write the hooks/functions for that feature in that file.
4. Prefix function names with `lior_` to avoid collisions with WordPress core,
   WooCommerce, or other plugins.
5. Ship it by cutting a new Release (see "Releasing a new version" below) — no
   activation step needed for a new module file, it's picked up automatically.

## Turning a module off without deleting it

Rename the file so it doesn't end in `.php`, e.g.:
`admin-order-email-link.php` → `admin-order-email-link.php.off`

## Installing on the site (first time)

1. Download the plugin zip: on the GitHub repo, **Code → Download ZIP**, or
   grab the zip from the latest **Release**.
2. WP Admin → Plugins → Add New → Upload Plugin → pick the zip → Install →
   Activate.
3. The folder must end up named `lior-toolkit` in `wp-content/plugins/`. If a
   GitHub download unzips as `lior-toolkit-master` or `lior-toolkit-1.1.0`,
   rename it to `lior-toolkit` before/after upload.

## Updating the site (after install)

Updates come from GitHub **Releases**, shown like any other plugin update:

- Publish a new Release (see below) → within ~12h, or immediately after
  clicking **Check again** on WP Admin → Dashboard → Updates, the site shows
  "Lior Jewellery — Site Toolkit … update available".
- Click **Update now**. Nothing installs itself — it's always a manual click.

This is wired up by the bundled `plugin-update-checker` library in
`includes/lib/`. The repo it points at is set in `lior-toolkit.php`
(`Update URI` header + the `buildUpdateChecker` call).

## Releasing a new version

1. Bump the version in **two** places in `lior-toolkit.php`: the
   `Version:` header and the `LIOR_TOOLKIT_VERSION` constant. Use semver
   (e.g. `1.2.0`).
2. Commit and merge to `master`.
3. Tag and publish a GitHub Release whose tag matches the version, with or
   without a leading `v`:

   ```sh
   git tag v1.2.0
   git push origin master --tags
   gh release create v1.2.0 --title "v1.2.0" --notes "What changed"
   ```

   The update checker compares the release tag (minus any `v`) against the
   installed `Version:` header, so the tag and the header must agree.

## Manual deploy (fallback)

Still possible if GitHub is unreachable: zip this folder (named
`lior-toolkit`) and upload via WP Admin → Plugins → Add New → Upload Plugin
(replacing the previous version), or copy it over SFTP into
`wp-content/plugins/lior-toolkit/`.

## Current modules

- `admin-order-email-link.php` — adds a "view/edit this order" admin-panel
  link to the admin-facing WooCommerce emails (New order, Cancelled order,
  Failed order).
- `auto-print-orders.php` — auto-prints each new paid order (status →
  processing) on the office Mac. The module exposes a small key-authed REST
  queue; a polling agent on the Mac (`mac-agent/`, not loaded by WordPress)
  fetches queued orders, renders each to an A4 PDF and sends it to the
  printer, then marks it printed. Config + API key: WP Admin → Tools → Lior
  Auto-Print. Mac setup: `mac-agent/README.md`.
