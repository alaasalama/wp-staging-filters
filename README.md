WP Staging Filters Manager
==========================

Import WP Staging filters/actions from the official docs, load/edit the snippet, and manage them as a single MU‑plugin file — with a clean, AJAX UI and an OOP architecture.

Docs
- WP Staging Actions & Filters: https://wp-staging.com/docs/actions-and-filters/

Features
- Import from WP Staging Docs (drops the top heading entry)
- Live “Search & Choose from Docs” field with suggestions
- Load snippet to editor, tweak, and save
- Code editor with PHP syntax highlighting (WordPress CodeMirror)
- Manage snippets in `wp-content/mu-plugins/wp-staging-custom-snippets.php`
- Add/Update/Delete via AJAX (no page reloads)
- Manual “Paste HTML” import fallback

Admin Location
- Tools → WP Staging Filters

OOP Structure
- `includes/Importer.php` — fetch + parse docs HTML
- `includes/Snippets.php` — store snippets + write MU‑plugin safely (strips `<?php` and `?>`)
- `wp-staging-filters-manager.php` — plugin bootstrap, AJAX handlers, and UI (rendering can be split further if desired)

Install
1. Copy the `wp-staging-filters-manager` folder into `wp-content/plugins/`.
2. Activate “WP Staging Filters Manager”.
3. Go to Tools → WP Staging Filters.

Notes
- If a snippet includes closing PHP tags (`?>`), the MU plugin build removes them to avoid parse errors.
- IDs are normalized to drop the long docs prefix.

License
- GPL-2.0-or-later — see LICENSE or https://www.gnu.org/licenses/gpl-2.0.html
