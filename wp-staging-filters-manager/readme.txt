=== WP Staging Filters Manager ===
Contributors: alaasalama
Tags: wp-staging, filters, actions, snippets, mu-plugin
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import WP Staging filters/actions from the official docs, edit them, and manage all snippets as a single MU-plugin file. Clean, AJAX UI and OOP codebase.

== Description ==

WP Staging Filters Manager lets you browse the WP Staging Actions & Filters docs, load a snippet for final tweaks, and save it into a single MU-plugin file so it is always loaded.

- Docs: https://wp-staging.com/docs/actions-and-filters/
- Lives under Tools → WP Staging Filters

Features:
- Import from the docs (top heading entry is skipped)
- One “Search & Choose” field with live suggestions
- Load → edit in a PHP-aware editor → Add/Update (AJAX)
- All managed snippets are written to `wp-content/mu-plugins/wp-staging-custom-snippets.php`
- Manual “Paste HTML” import fallback

OOP structure:
- `includes/Importer.php` – fetch + parse docs page
- `includes/Snippets.php` – store snippets + rebuild MU-plugin safely (removes `<?php`/`?>` from snippet code)
- `wp-staging-filters-manager.php` – plugin bootstrap + AJAX

== Installation ==

1. Upload the `wp-staging-filters-manager` folder to `wp-content/plugins/`.
2. Activate “WP Staging Filters Manager”.
3. Go to Tools → WP Staging Filters.
4. Click “Refresh from Docs”, then search, load, tweak, and Add/Update snippets.

== Frequently Asked Questions ==

= Where are snippets written? =
To `wp-content/mu-plugins/wp-staging-custom-snippets.php` (auto-created if missing).

= Can I paste custom code without selecting from docs? =
Yes. Type/paste in the editor and click Add/Update.

= I saw a PHP parse error in the MU plugin file. =
This version strips `<?php` and `?>` from snippets to avoid that. Refresh from Docs or re-save the snippet to rebuild a valid file.

== Changelog ==

= 0.2.0 =
- AJAX UI for import, load, add/update, and delete
- Picker with live suggestions
- OOP refactor (Importer + Snippets services)
- Safe MU build (removes `<?php`/`?>` from snippet code)

