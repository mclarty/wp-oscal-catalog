# WP OSCAL Catalog Importer — User Guide

A WordPress plugin that imports an **OSCAL Catalog** (YAML or JSON) and generates one **Security Control** page per control with:

- Zero-padded control labels and enhancement handling  
- Nested **Control Statement** rendering (labels preserved; bullets visually suppressed)  
- Parameter (ODP) substitution with bold/italic formatting (including nested placeholders)  
- Collapsible **Guidance** and **Extras** sections (Extras shown only when mapped in admin)  
- An **Enhancements** list on base controls  
- A `[oscal_toc]` shortcode for a site-wide table of contents  
- A selectable page template (`oscal-control.php`) you can override in your theme

---

## Contents

- [Requirements](#requirements)  
- [Installation](#installation)  
- [YAML Dependency](#yaml-dependency)  
- [Docker / PHP Limits](#docker--php-limits)  
- [What the Plugin Creates](#what-the-plugin-creates)  
- [Admin Configuration](#admin-configuration)  
- [Importing an OSCAL Catalog](#importing-an-oscal-catalog)  
- [Shortcode: Table of Contents](#shortcode-table-of-contents)  
- [Parameter Substitution (ODPs)](#parameter-substitution-odps)  
- [Page Structure](#page-structure)  
- [Theme & Layout](#theme--layout)  
- [Permissions & Roles](#permissions--roles)  
- [Troubleshooting](#troubleshooting)  
- [Re-import Behavior](#re-import-behavior)  
- [Uninstall](#uninstall)  
- [Changelog](#changelog)

---

## Requirements

- **WordPress** 6.2 or later  
- **PHP** 8.0 or later  
- One of:
  - Composer package `symfony/yaml`, **or**
  - PECL `yaml` extension  
- Ability to upload files via the admin

> **Access Control:** Users must have the **`oscal_manage`** capability. The plugin creates a role **OSCAL Catalog Editor** with this capability on activation, and also grants it to **Administrators**.

---

## Installation

### Option A — Upload a ZIP (typical)
1. Zip the plugin folder (e.g., `wp-oscal-catalog/` → `wp-oscal-catalog.zip`).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, and **Install Now**.
3. Click **Activate** (this creates the **OSCAL Catalog Editor** role and grants caps).

### Option B — Manual copy (development)
1. Copy the plugin folder into `wp-content/plugins/wp-oscal-catalog/`.
2. In WordPress: **Plugins → Installed Plugins**, locate **WP OSCAL Catalog Importer**, and **Activate**.

> The role/capability is asserted on every page load, but if you don’t see the menu right after updating files, simply deactivate and reactivate the plugin once.

---

## YAML Dependency

If you plan to import YAML, install either:

### Composer (recommended)
```bash
cd wp-content
composer require symfony/yaml
```
The plugin looks for common autoloaders such as:
- `wp-content/vendor/autoload.php`
- `wp-content/plugins/wp-oscal-catalog/vendor/autoload.php`
- `ABSPATH/vendor/autoload.php` (and parent)

### PECL yaml
Install and enable the `yaml` extension for your PHP environment.

> If neither is present, you can still import **JSON** catalogs.

---

## PHP Limits

Create an `uploads.ini` file in your `/etc/php/conf.d` directory with the following minimum settings:

```ini
file_uploads = On
memory_limit = 512M
upload_max_filesize = 16M
post_max_size = 16M
max_file_uploads = 50
max_execution_time = 300
```

---

## What the Plugin Creates

- **Custom Post Type:** `security_control`  
  - Public, archive enabled, permalink base: `/security-control/...`  
  - Comments **disabled**  
- **Generated page content** (Gutenberg blocks):
  - Header card (your configured header fields only)
  - Control Statement (single prose + fully nested statement parts)
  - Description (if present)
  - Guidance (collapsible)
  - Extras (collapsible; **only those mapped in admin**)
  - Enhancements (base controls only; linked list)
- **Template:** assigns `oscal-control.php` (selectable; theme override supported)
- **Shortcode:** `[oscal_toc]` for a table of contents

---

## Admin Configuration

Go to **OSCAL Importer** in the WordPress admin sidebar. You must have the **`oscal_manage`** capability (see [Permissions & Roles](#permissions--roles)).

### Display Options

- **Header fields (props name → display label)**  
  Add rows where **Name** equals a control’s `props[].name` (case-insensitive).  
  On each control page, the matching `props[].value`(s) display under the header card with your **Display Label**.

  **Examples**
  - Name: `implementation-level` → Label: `Implementation Level`
  - Name: `status` → Label: `Status`

- **Extras labels (part name → summary)**  
  Add rows where **Name** equals `parts[].name` for any non-standard part you want to show in the “Extras” section.  
  The **Summary** text becomes the collapsible `<summary>` label.  
  **Only parts listed here are displayed.**

  **Examples**
  - Name: `references` → Summary: `References`
  - Name: `controls` → Summary: `Related Controls`

Click **Save Display Options** to persist these settings.

---

## Importing an OSCAL Catalog

1. Prepare your catalog file:
   - YAML: `.yaml` / `.yml` (requires `symfony/yaml` or PECL `yaml`)
   - JSON: `.json`
   - Expected structure:
     - Root object: `catalog`
     - `catalog.groups[]` → each group has `controls` (or `control`) arrays

2. Go to **OSCAL Importer → Import Catalog**.
3. Choose the file and click **Import Catalog**.

**During import:**
- Existing `security_control` posts (and legacy `oscal_control`, if present) are **deleted**.
- New posts are created for every control and enhancement.
- **Slugs** are generated from the **zero-padded label**; enhancements use **hyphen** notation (e.g., `ac-24-01`) to avoid server issues with dots.

---

## Shortcode: Table of Contents

Place the shortcode on any page/post:

```
[oscal_toc]
```

**Attributes**

| Attribute      | Values                     | Default   | Purpose                                                   |
|----------------|----------------------------|-----------|-----------------------------------------------------------|
| `group_by`     | `family` \| `none`         | `family`  | Group controls by family/group title                      |
| `enhancements` | `nest` \| `flat` \| `hide` | `nest`    | Nest under base, list as peers, or hide enhancements      |
| `title`        | (string)                   | *(empty)* | Optional heading displayed above the list                 |
| `view`         | `list`                     | `list`    | Reserved for future views                                 |

**Examples**
```text
[oscal_toc group_by="none"]
[oscal_toc group_by="none" enhancements="flat" title="All OSCAL Controls"]
[oscal_toc enhancements="hide"]
```

---

## Parameter Substitution (ODPs)

The importer resolves parameter placeholders in control prose and nested statement items:

- Recognizes `{{ insert: param, <id> }}` and `{{ param, <id> }}` (case-insensitive)  
- **Selection** parameters render **bold + italic**:  
  `**_[Selection (one-or-more): option A; option B]_**`
- **Assignment** parameters render **bold + italic**, using **constraints.description** (or **label**)  
- **values[]** parameters render **bold only**  
- Nested placeholders inside selections/assignments are resolved (with a safe recursion limit)

---

## Page Structure

- **Title:** `"[zero-padded label] [control.title]"`  
  e.g., `AC-24(01) Transmit Access Authorization Information`
- **Header card:** shows **only the fields** you configured under **Header fields**
- **Control Statement:** single prose and full nested items (part labels shown; bullets hidden via CSS)
- **Description:** from `parts[name=discussion|description]` if present
- **Guidance:** from `parts[name=guidance]`, collapsible
- **Extras:** any `parts[]` whose `name` matches your **Extras labels** configuration  
- **Enhancements:** base control pages append a linked list of enhancement pages, sorted by label

---

## Theme & Layout

- All major content groups render with `align:"wide"`; width is constrained by CSS for both front-end and editor.  
- Many themes place the post title outside `.entry-content`; the plugin constrains that as well.  
- Comments and pings are **disabled** for the `security_control` post type.

> Template: Each generated page is assigned `oscal-control.php`. You may override it by placing a file with the same name in your theme or child theme.

---

## Permissions & Roles

- **Capability used:** `oscal_manage`  
- **Role created on activation:** **OSCAL Catalog Editor** (slug: `oscal_catalog_editor`)  
  - Capabilities: `read`, `oscal_manage`  
- **Administrators** are also granted `oscal_manage` automatically.

### Granting Access

- **Assign the role** in **Users → Add New** (or edit an existing user and change the role).  
- Alternatively, add `oscal_manage` to any custom role via a role editor plugin or WP-CLI.

**WP-CLI examples**
```bash
# Grant role to a user
wp user add-role 123 oscal_catalog_editor

# Add the capability to Editors (optional)
wp cap add editor oscal_manage
```

> **Multisite:** The role and capability are created **per site**. Network-activate the plugin or activate it on each site that needs it.

---

## Troubleshooting

**“Unsupported file type. Upload YAML (.yaml/.yml) or JSON.”**  
- Ensure the file extension is `.yaml`, `.yml`, or `.json` (case-insensitive).  
- YAML MIME detection can vary; the plugin prefers the extension and uses a light heuristic.

**“No file received.”**  
- Increase `upload_max_filesize` and `post_max_size` (see Docker/PHP limits).  
- Check reverse proxy limits if applicable.

**YAML parse error**  
- The on-screen message is truncated for readability; check your PHP error log for details.  
- Validate the file with `yamllint` if needed.

**404 after import**  
- Go to **Settings → Permalinks** and click **Save** to flush rewrite rules.

**Gutenberg “template mismatch” warning**  
- The plugin sets a minimal template to avoid this. If warnings persist, ensure the control uses `oscal-control.php` or adjust your theme template lock settings.

**Don’t see the “OSCAL Importer” menu?**  
- Ensure your user has the `oscal_manage` capability (assign the **OSCAL Catalog Editor** role or grant the cap to your role).  
- If role/cap seems missing after manual file updates, deactivate and reactivate the plugin once to run the activation hook.

---

## Re-import Behavior

- Every import **purges** existing `security_control` (and legacy `oscal_control`) posts and rebuilds the catalog.  
- **Display Options** (Header fields & Extras labels) are persistent and apply to all generated pages.

---

## Uninstall

1. Deactivate the plugin via **Plugins**.  
2. Optionally delete generated `security_control` posts before or after deactivation.  
3. Remove any theme overrides (`oscal-control.php`) you added.
