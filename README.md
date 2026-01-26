# Zerø Sense v3 — Technical Guide

Modern, modular WordPress plugin for Paellas en Casa. PSR-4, auto-discovered features, zero external UI dependencies. Built for humans and AI agents to understand and extend quickly.

— It makes no sense. It doesn’t need to.

## Why this document

This README is the single source of truth for:

- What the plugin does and doesn’t do
- Where things live in the codebase (exact paths/classes)
- How features are wired at runtime (hooks, guards, priorities)
- How to test safely and extend correctly

Use this when onboarding a developer or another AI agent.

Related docs:

- `SHORT.md` — Quick reference (2‑minute guide)
- `DEV_NOTES.md` — Architectural decisions, patterns and anti‑patterns

---

## System architecture

- **Entry point**: `zero-sense.php`
- **Core**: `src/ZeroSense/Core/`
  - `Plugin.php` — singleton bootstrap
  - `FeatureInterface.php` — contract all features implement
  - `FeatureManager.php` — auto-discovery and orchestration
  - `AdminDashboard.php` — admin UI (Settings → Zerø Sense)
- **Features**: `src/ZeroSense/Features/` (by category)
- **Assets**: `assets/` (admin CSS/JS and feature assets)
- **I18n**: `languages/`

Autoloading is PSR-4 via `composer.json`. Features are discovered, validated against `FeatureInterface`, condition-checked via `getConditions()`, sorted by `getPriority()`, and initialized.

---

## Glossary

- **Feature**: A class under `src/ZeroSense/Features/` implementing `ZeroSense\Core\FeatureInterface`.
- **Orchestrator**: An always‑on feature that instantiates multiple components (e.g., `CheckoutPageEnhancements`, `OrderPayPageEnhancements`).
- **Card**: The dashboard UI element representing a feature. Title = `getName()`. Subtitle = `getDescription()`.
- **Toggle**: Switch rendered when `isToggleable()` is true; state read by `isEnabled()` and usually backed by `getOptionName()`.
- **Settings fields**: Rendered when `hasConfiguration()` + `getConfigurationFields()` exist (handled by the dashboard).
- **Information blocks**: Returned by `getInformationBlocks()`; must include at least Code map, Hooks & filters, Testing notes.
- **Code map**: List pointing to files/classes/methods that implement the feature logic.
- **Hooks & filters**: Primary WordPress/WooCommerce hooks touched by the feature.
- **Testing notes**: Minimal, actionable steps to verify the feature.
- **HPOS**: High‑Performance Order Storage (WooCommerce). Some admin features support both classic CPT and HPOS.
- **WPML language**: The order’s language stored in meta; affects payment URLs and content.
- **Flowmattic trigger**: Workflow execution via `flowmattic_trigger_workflow` with structured payload.

---

## Dashboard behavior

- Cards are generated from `FeatureInterface` getters.
- Title is `getName()`. Subtitle (summary) is `getDescription()`.
- Toggles appear when `isToggleable()` is true; state uses the feature’s `getOptionName()` when present or an auto-generated key.
- Optional sections:
  - `hasConfiguration()` + `getConfigurationFields()` — renders Settings block
  - `hasInformation()` + `getInformationBlocks()` — renders Information block(s)
- Toggle changes re-initialize the specific feature on the fly (`AdminDashboard::handleAjaxToggle()`).

---

## Development conventions

- Namespaces/class files mirror directory structure.
- Keep hooks guarded: call condition checks early (`is_admin`, `is_checkout`, `class_exists:WooCommerce`, etc.).
- Prefer vanilla JS; use jQuery only where stability requires it (documented in the feature).
- Options, handles, and meta keys use the `zs_` prefix.
- CSS variables use the `--zs-*` prefix.

Example option boolean pattern (established across the project):

```php
public function isEnabled(): bool
{
    return (bool) get_option($this->getOptionName(), true);
}
```

---

## Feature index (authoritative)

Below is the complete set of features implementing `FeatureInterface` with their files, purpose, and key notes. Use the in-card “Code map, Hooks & filters, Testing notes” for deeper details.

### WordPress

- `src/ZeroSense/Features/WordPress/HideAdminBar.php`
  - Hides admin bar on frontend for non-admins.
- `src/ZeroSense/Features/WordPress/PrivacyPolicyAndCookies.php`
  - Extends privacy policy selector to include `legal-page` CPT; keeps AJAX and media working with Must‑Have Cookie SW; WPML/WooCommerce URL compatibility.
- `src/ZeroSense/Features/WordPress/ResponsiveImageSizes.php`
  - Registers curated, filterable responsive image sizes and labels in media modal.

### Security

- `src/ZeroSense/Features/Security/SecurityHeaders.php`
  - Injects security headers (HSTS, CSP, Permissions‑Policy, Referrer‑Policy, etc.) with filterable CSP directives.

### Utilities

- `src/ZeroSense/Features/Utilities/CssVariables.php`
  - Loads CSS variables from `uploads/automatic-css/automatic-variables.css` for admin/login (auto-detection, cache-busting).
- `src/ZeroSense/Features/Utilities/OrderEventDateFormatter.php`
  - Always-on helper feature that exposes `zs_format_event_date_for_admin()` for consistent dd/mm/YYYY formatting across admin screens.

### Integrations

- `src/ZeroSense/Features/Integrations/Bricks/BricksIntegration.php`
  - Allows extra PHP functions and HTML tags in Bricks code elements.
- `src/ZeroSense/Features/Integrations/Bricks/BricksDynamicTags.php`
  - Exposes Woo/Meta Box order data as dynamic tags in Bricks (WPML-aware).
- `src/ZeroSense/Features/Integrations/WPML/OrderLanguage.php`
  - Manages order language (admin UI, sortable/filterable column, payment URLs per language).
- `src/ZeroSense/Features/Integrations/Flowmattic/Flowmattic.php`
  - One consolidated Flowmattic integration (status transitions, class actions, dashboard CRUD + Play, runtime hooks). Support classes:
    - `Integration.php` (runtime), `ApiExtension.php` (REST/webhooks enrichment), `OrderExtension.php` (WC_Order helpers), `assets/js/admin.js`, `assets/class-actions.js`.

### WooCommerce

- `src/ZeroSense/Features/WooCommerce/CartAjaxHandlers.php`
  - Robust AJAX for add/remove/update, fragments, cookies, and session safety.
- `src/ZeroSense/Features/WooCommerce/CartTimeout.php`
  - Clears cart after all tabs are closed for N minutes (configurable, legacy action compatibility).
- `src/ZeroSense/Features/WooCommerce/Checkout/CheckoutPageEnhancements.php`
  - Always‑on bundle for checkout: terms, payment interception, method classes (Flowmattic), text tweaks, fields, datepicker, gateways, marketing consent.
- `src/ZeroSense/Features/WooCommerce/OrderPay/OrderPayPageEnhancements.php`
  - Enhancements on order‑pay page: conditional UX, terms, consent, cancel button.
- `src/ZeroSense/Features/WooCommerce/OrderStatuses.php`
  - Custom statuses (budget‑requested, deposit‑paid, fully‑paid, not‑available) + admin list/report integration (classic + HPOS).
- `src/ZeroSense/Features/WooCommerce/Deposits/WooCommerceDeposits.php`
  - Toggleable deposits system (settings, first/second payment flows, statuses).
- `src/ZeroSense/Features/WooCommerce/EmptyCartLink.php`
  - Empty cart via `?zs_empty_cart=1` + redirect to cart.
- `src/ZeroSense/Features/WooCommerce/Gateways/Gateways.php`
  - Registers Redsys Card/Bizum/Deposits gateways if present.
- `src/ZeroSense/Features/WooCommerce/Gateways/PayLaterGateway.php`
  - Adds a simple “Pay Later” gateway (configure in Woo → Payments).
- `src/ZeroSense/Features/WooCommerce/OrderManagement/AdminOrderEventDate.php`
  - Sortable “Event Date” order column with filters (Future/Past/All), classic + HPOS.
- `src/ZeroSense/Features/WooCommerce/OrderManagement/AdminOrderPaymentLinks.php`
  - Adds a unified “Customer payment page” link in admin orders and appends admin language to payment URLs.

---

## Installation & onboarding

1. Install dependencies
   ```bash
   cd wp-content/plugins/zero-sense-3
   composer install
   ```
2. Activate the plugin in WP Admin → Plugins.
3. Go to Settings → Zerø Sense to toggle/configure features.
4. Read each card’s “Code map / Hooks & filters / Testing notes” before changing behavior.

---

## Adding a new feature

1. Create a class under `src/ZeroSense/Features/<Category>/` implementing `ZeroSense\Core\FeatureInterface`.
2. Provide `getName()`, `getDescription()`, `getCategory()`, `getPriority()`, `getConditions()`, `isToggleable()`, `isEnabled()`, `init()`.
3. If configurable, implement `hasConfiguration()` and `getConfigurationFields()`.
4. Add `hasInformation()` + `getInformationBlocks()` with at least Code map, Hooks & filters, Testing notes.
5. The dashboard will auto‑discover and render the card.

Template snippet:

```php
class MyFeature implements FeatureInterface {
  public function getName(): string { return __('My Feature', 'zero-sense'); }
  public function getDescription(): string { return __('Short summary subtitle.', 'zero-sense'); }
  public function getCategory(): string { return 'WordPress'; }
  public function isToggleable(): bool { return true; }
  public function isEnabled(): bool { return (bool) get_option('zs_my_feature', true); }
  public function getPriority(): int { return 10; }
  public function getConditions(): array { return []; }
  public function init(): void { if (!$this->isEnabled()) return; /* register hooks */ }
}
```

---

## Performance & stability

- Prefer early guards to avoid unnecessary work.
- Keep runtime JS minimal; use global locks on critical AJAX flows where concurrency issues arise (cart operations) and provide visual feedback (`zs-global-loading`).
- Measure before optimizing; leverage Query Monitor and targeted logs.

---

## Security headers (production)

`SecurityHeaders` injects a sensible default set. Adjust via:

- `zero_sense_security_headers` to override final headers
- `zero_sense_security_csp_directives` to modify CSP sources

Include only the domains you actually need (CDNs, analytics, payment endpoints).

---

## Testing checklist

- **Activation**: no PHP notices; dashboard loads.
- **Toggles**: state persists; features re‑init immediately.
- **Feature QA**: follow each card’s Testing notes.
- **WooCommerce**: cart, checkout, order‑pay, custom statuses, deposits flows.
- **Integrations**: Bricks tags in builder; Flowmattic Play button; WPML order language.

---

## Troubleshooting

- Missing card? Ensure the class implements `FeatureInterface` and the namespace matches the file path.
- Toggle not persisting? Ensure `isEnabled()` returns a proper boolean cast and `getOptionName()` is stable.
- CSP blocking scripts? Extend allowlists via `zero_sense_security_csp_directives`.
- Woo AJAX oddities? Verify session/cookies (see `CartAjaxHandlers` and `CartTimeout`).

---

## Contributing (internal)

- Follow the conventions in this README.
- Keep every feature’s card updated with Code map, Hooks & filters, Testing notes.
- Prefer small, focused features over monoliths. If you create multiple related micro‑features, consider a single always‑on orchestrator (as with Checkout and Order Pay bundles).

---

## License

GPL v2 or later.

# Zerø Sense v3.0

**Modular WordPress plugin with modern PSR-4 architecture**  
*It makes no sense. It doesn’t need to.*

## ✨ Key Features

- **Auto-Discovery**: Features are discovered and registered automatically.
- **PSR-4 Architecture**: Clean code with modern autoloading.
- **Smart Dashboard**: Auto-generated interface with AJAX toggles.
- **Configuration System**: Features can expose customizable options.
- **Minimalist Design**: Lightweight, focused admin UI.
- **High Performance**: Efficient loading with minimal overhead.

## 🏗️ System Architecture

### 📁 Directory Structure
```
zero-sense-3/
├── zero-sense.php              # Primary bootstrap
├── composer.json               # PSR-4 autoloading definition
├── src/ZeroSense/
│   ├── Core/                   # Core classes
│   │   ├── Plugin.php          # Main singleton
│   │   ├── FeatureInterface.php # Feature contract
│   │   ├── FeatureManager.php  # Auto-discovery
│   │   └── AdminDashboard.php  # Generated dashboard
│   └── Features/               # Features organized by category
│       ├── WordPress/          # WordPress core features
│       ├── WooCommerce/        # E-commerce features
│       ├── Security/           # Security features
│       ├── Utilities/          # General utilities
│       ├── Integrations/       # External integrations
│       └── Test/               # Sample/test features
├── assets/                     # CSS/JS and shared resources
│   ├── css/admin.css           # Zerø Sense admin styling
│   └── js/admin.js             # Dashboard behavior
└── languages/                  # Translations
```

### 🎯 Feature Categories
- **WordPress**: Core WP functionality enhancements.
- **WooCommerce**: Checkout, cart, and order tools.
- **Security**: Headers and hardening utilities.
- **Utilities**: Helper modules and cross-cutting concerns.
- **Integrations**: Bricks Builder, Flowmattic, WPML.
- **Test**: Experimental or demo features.

## 🚀 Installation & Usage

### 📦 Installation
1. **Install dependencies**
   ```bash
   cd wp-content/plugins/zero-sense-3/
   composer install
   ```
2. **Activate the plugin**
   - Go to WordPress Admin → Plugins.
   - Locate “Zerø Sense v3.0”.
   - Click “Activate”.
3. **Configure features**
   - Navigate to Settings → Zerø Sense.
   - Toggle features on/off as needed.
   - Configure additional options where available.

## 🧭 Quick Onboarding

1. Clone the repository and run `composer install` inside `zero-sense-3/`.
2. Review `zero-sense.php` for global constants and bootstrap (`zero_sense_init()` loads `ZeroSense\Core\Plugin`).
3. Inspect `src/ZeroSense/Core/Plugin.php` and `FeatureManager.php` to understand the main flow and auto-discovery.
4. Create or enable a feature under `src/ZeroSense/Features/<Category>/` ensuring it implements `FeatureInterface`.
5. Activate the feature from the Zerø Sense dashboard and follow the QA checklist in `MIGRATION_PLAN.md`. For Flowmattic, add transitions and class actions through the dashboard—no hardcoded IDs exist.

### 🎛️ Dashboard Usage
- **Category tabs**: Navigate between WordPress, WooCommerce, etc.
- **AJAX toggles**: Enable or disable features without refreshing.
- **Advanced settings**: Features with ⚙️ expose extra options.
- **Real-time feedback**: Success and error messaging without reloads.
- **English messaging**: Dashboard notices stay in English for consistency.

## 👨‍💻 Development

### 📚 Documentation Policy (Mandatory)
- **README**: Update this `README.md` whenever any feature’s behavior changes.
- **Backend Info/Settings**: After editing a feature, adjust the information presented in its dashboard card (`getInformationBlocks()` and `getConfigurationFields()` in `src/ZeroSense/Features/**/`).
- **Source comments**: Write all inline/block comments in English across PHP, JS, CSS, and other source files.
- **Strict rule**: No change is considered complete until both documentation surfaces are updated.

### ⚡ Performance Best Practices

- Keep hooks scoped: run logic only when the context requires it (`is_checkout()`, `is_admin()`, etc.).
- Measure before optimizing: rely on Query Monitor or targeted logging to locate bottlenecks.
- Avoid heavy dependencies: the dashboard is built with vanilla JS and lightweight CSS to remain fast.
- Prioritize stability: during critical AJAX flows (for example, cart actions) lock the UI globally to prevent race conditions.

### 🔧 Critical Fix: Toggle Persistence

- **Issue**: Some toggles were not persisting their state correctly.
- **Root cause**: The AJAX system expects boolean values (`true`/`false`), but some features returned strings (`'yes'`) or raw `get_option()` values.
- **Implemented fix**:
  ```php
  // ❌ Incorrect
  return get_option('my_option', 'yes') === 'yes';
  return get_option('my_option', false); // No cast

  // ✅ Correct
  return (bool) get_option('my_option', true);
  ```
- **Updated features**: `CssVariables`, `BackgroundColorChanger`, `FooterTextCustomizer`, `AdminNoticeDisplayer`, `CheckoutEnhancements`.
- **Result**: All toggles persist their state reliably.

### 🗂️ Asset Organization

- **Shared root**: All resources live under `assets/` (for example, `assets/css/reset.css`, `assets/css/admin.css`, `assets/js/admin.js`).
- **Context-specific folders**: Create optional directories such as `assets/css/frontend/` or `assets/js/frontend/` when a feature needs its own files. Avoid per-feature folders unless necessary.
- **Central dashboard loading**: `ZeroSense\Core\AdminDashboard::enqueueAdminAssets()` registers global assets for `settings_page_zero-sense-*` screens.
- **Feature-level loading**: Individual feature classes enqueue CSS/JS inside `init()` after checking `isEnabled()`.
- **Naming conventions**: Use `zero-sense-*` handles, `--zs-*` CSS variables, and `ZERO_SENSE_VERSION` for asset versioning.

### 🔤 Naming Conventions

- **Namespaces**: Follow PSR-4 (`ZeroSense\Category\Feature`), ensuring directories mirror namespaces.
- **Classes**: Descriptive `PascalCase` names (`OrderPayEnhancements`).
- **Interfaces**: `PascalCase` with an `Interface` suffix (`FeatureInterface`).
- **Methods/functions**: `camelCase` verbs (`initFeatureHooks`).
- **Properties**: Concise `camelCase` (`featureManager`).
- **PHP files**: Match the class name (`OrderPayEnhancements.php`).
- **Options/meta**: Prefix with `zs_` using snake_case (`zs_order_pay_lock`). Implement `getOptionName()` when a custom name is needed.
- **Bootstrap globals**: Prefix functions with `zero_sense_` (`zero_sense_init()`, `zero_sense_activate()`), limited to files outside PSR-4 namespaces.
- **CSS variables**: Prefix with `--zs-` (`--zs-accent-color`).
- **JS constants**: Use `SCREAMING_SNAKE_CASE`; functions and variables stay in `camelCase`.

| Element | Prefix | Example | Location |
| --- | --- | --- | --- |
| Global constants | `ZERO_SENSE_` | `ZERO_SENSE_VERSION` | `zero-sense.php` |
| Bootstrap functions | `zero_sense_` | `zero_sense_activate()` | `zero-sense.php` |
| Options/meta/handles | `zs_` | `zs_wordpress_hide_admin_bar` | Features, hooks |
| CSS variables | `--zs-` | `--zs-color-primary` | `assets/css/` |
| JS constants | `ZS_*` | `ZS_DASHBOARD_STATE` | `assets/js/` |

### 🔧 Creating a New Feature

1. Create a PHP class under `src/ZeroSense/Features/<Category>/`.
2. Implement `FeatureInterface`.
3. Auto-discovery will pick it up and render the dashboard card automatically.

> For a step-by-step tutorial review the annotated example in `src/ZeroSense/Features/Test/DummyFeature.php`.

### 🧠 How Auto-Discovery Works

1. `ZeroSense\Core\FeatureManager` iterates through the directories listed in `$featureDirectories`.
2. Each PHP file is loaded and validated to ensure the class implements `ZeroSense\Core\FeatureInterface`.
3. Before initialization, `getConditions()` are evaluated (for example, `is_admin`, `class_exists:WooCommerce`).
4. Approved features are sorted by `getPriority()` and registered in the dashboard via `ZeroSense\Core\AdminDashboard`.
5. When a toggle changes, `AdminDashboard::handleAjaxToggle()` re-runs `init()` so updates take effect immediately.

Refer to the comments in `FeatureInterface.php` for details on each method.

### 📝 Feature Example

```php
<?php
namespace ZeroSense\Features\WordPress;

use ZeroSense\Core\FeatureInterface;

class MyFeature implements FeatureInterface
{
    public function getName(): string
    {
        return __('My Feature', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Description of what this feature does.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WordPress';
    }

    public function isToggleable(): bool
    {
        return true; // Feature can be enabled/disabled
    }

    public function isEnabled(): bool
    {
        return get_option('zs_my_feature', false);
    }

    public function getPriority(): int
    {
        return 10; // Load order
    }

    public function getConditions(): array
    {
        return ['is_admin']; // Conditions to load
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Initialize feature hooks here
        add_action('init', [$this, 'myFeatureInit']);
    }

    public function myFeatureInit(): void
    {
        // Feature logic
    }
}
```

### ⚙️ Features with Configuration

```php
// Add these methods for configurable features:
public function hasConfiguration(): bool
{
    return true;
}

public function getConfigurationFields(): array
{
    return [
        [
            'name' => 'my_option',
            'label' => 'My Field',
            'type' => 'text',
            'description' => 'Field description',
            'placeholder' => 'Example...',
            'value' => get_option('my_option', '')
        ]
    ];
}
```

## 📊 Project Status

### ✅ Completed (v3.0)
- **PSR-4 architecture**: 100% implemented.
- **Auto-discovery**: Fully operational.
- **Dashboard**: Complete interface with Zerø Sense branding.
- **Toggle system**: AJAX toggles with automatic re-initialization.
- **Configuration system**: Features provide customizable options.
- **Test features**: Three example features working as references.

### 📈 Current Stats
- **Features created**: 6/20 (30%).
- **Categories implemented**: 4/9 (44%).
- **Architecture**: 100% PSR-4.
- **Dashboard**: 100% functional.
- **Auto-discovery**: 100% operational.
- **Toggle system**: 100% functional.
- **Configuration system**: 100% functional.

### 🎯 Next Steps
- Migrate features from the original plugin.
- Expand WooCommerce-specific features.
- Optimize performance under heavy traffic.
- Perform comprehensive testing.

## 📝 Migrating from v2.0

This is a complete rewrite. See `MIGRATION_PLAN.md` for migration steps.

## 🧪 Testing

Manual testing checkpoints:
1. **Plugin activation**: No PHP warnings or notices.
2. **Dashboard load**: Interface renders correctly.
3. **Feature toggles**: AJAX updates behave as expected.
4. **Feature functionality**: Each feature works as described.
5. **Configuration**: Settings save and apply immediately.

## 📞 Support

Plugin built specifically for **Paellas en Casa**.

## 📄 License

GPL v2 or later.
