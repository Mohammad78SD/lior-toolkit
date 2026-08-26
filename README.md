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
5. Deploy the updated plugin folder to the server (see below) — no activation
   step needed for a new module file, it's picked up automatically.

## Turning a module off without deleting it

Rename the file so it doesn't end in `.php`, e.g.:
`admin-order-email-link.php` → `admin-order-email-link.php.off`

## Deploying to the site

This folder is a git repo (`git log` for history). To ship a change to
lior-jewellery.com: zip this folder and upload via WP Admin → Plugins → Add
New → Upload Plugin (replacing the previous version), or copy it over
SFTP into `wp-content/plugins/lior-toolkit/`.

## Current modules

- `admin-order-email-link.php` — adds a "view/edit this order" admin-panel
  link to the admin-facing WooCommerce emails (New order, Cancelled order,
  Failed order).
