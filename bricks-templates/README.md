# Bricks Templates (Reference Only)

IMPORTANT: Do NOT enqueue, include, or autoload these files from the Zero Sense plugin.

Purpose:
- Keep Bricks template snippets close to the plugin for easy reference.
- Avoid accidental coupling between theme templates and plugin features.

What this folder contains:
- Example/template code used by Bricks (e.g. `shop/add-button.php`, `shop/add-button.js`).
- These files may be edited by the theme/builder and are NOT part of the plugin runtime.

Do NOT:
- Add entries in `includes/features/features-config.php` pointing to this folder.
- `require`, `include`, or `wp_enqueue_*` anything from `bricks-templates/` inside the plugin.

Rationale:
- The plugin must remain theme-agnostic and portable.
- Bricks templates are controlled by the theme/builder lifecycle, not by the plugin.

How to use:
- Edit and manage these templates from Bricks / your theme.
- Use this folder only as documentation/reference for developers.
