<?php
/**
 * Plugin Name: WP OSCAL Catalog Importer
 * Description: Import an OSCAL catalog (YAML or JSON) and generate Gutenberg-based pages per control, with statement nesting, parameter substitutions, configurable header fields, extras labels, enhancements section, a TOC shortcode, and a selectable theme template.
 * Version: 1.0.0
 * Author: Nick McLarty <nick@mclarty.me>
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('OSCAL_Catalog_Importer')):

final class OSCAL_Catalog_Importer {
    /* --------------------------- Constants --------------------------- */
    // NOTE: user changed CPT to 'security_control'
    const CPT                     = 'security_control';
    const MENU_SLUG               = 'oscal-catalog-importer';
    const NONCE_ACTION            = 'oscal_catalog_import';
    const NONCE_FIELD             = 'oscal_catalog_import_nonce';
    const NONCE_DISPLAY           = 'oscal_display_save';
    // Custom capability required to access importer/admin actions
    const CAPABILITY              = 'oscal_manage';
    const UPLOAD_FIELD            = 'oscal_yaml';
    const VERSION                 = '1.5.0';

    // Settings: ordered rows for header fields [{ name: string (props.name), label: string (display) }, ...]
    const OPTION_DISPLAY_PROP_MAP = 'oscal_props_display_map';
    // Settings: ordered rows for Extras summary overrides [{ name: string (part.name), label: string (summary text) }, ...]
    const OPTION_EXTRAS_LABEL_MAP = 'oscal_extras_summary_map';

    /* --------------------------- Bootstrap --------------------------- */
    public function __construct() {
        add_action('init',               [$this, 'register_post_type']);
        // Ensure roles/caps exist (idempotent)
        add_action('init',               [$this, 'ensure_caps']);
        add_action('admin_menu',         [$this, 'register_admin_menu']);
        add_action('admin_post_oscal_do_import',   [$this, 'handle_import']);
        add_action('admin_post_oscal_save_display',[$this, 'handle_save_display']);
        add_action('admin_enqueue_scripts',[$this, 'enqueue_admin_css']);
        add_action('admin_notices',      [$this, 'admin_notices']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_css']);
        add_action('enqueue_block_assets', [$this, 'enqueue_common_css']);
        add_filter('upload_mimes',       [$this, 'allow_yaml_json_mimes']);

        // Disable comments/pings for this CPT at runtime (front + admin)
        add_filter('comments_open',      [$this, 'disable_cpt_comments'], 10, 2);
        add_filter('pings_open',         [$this, 'disable_cpt_comments'], 10, 2);
        add_filter('comments_array',     [$this, 'hide_cpt_comments'], 10, 2);

        // Shortcode: [oscal_toc]
        add_shortcode('oscal_toc',       [$this, 'shortcode_oscal_toc']);

        // Theme template registration/usage for CPT
        add_filter('theme_templates',    [$this, 'register_theme_template'], 10, 4);
        add_filter('template_include',   [$this, 'maybe_use_oscal_template']);
    }

    /**
     * Create/refresh the "OSCAL Catalog Editor" role and grant capabilities.
     * Runs on activation and every init (safe to re-run).
     */
    public static function activate_plugin() : void {
        // Create role if missing
        if (!get_role('oscal_catalog_editor')) {
            add_role('oscal_catalog_editor', 'OSCAL Catalog Editor', [
                'read' => true,
            ]);
        }
        // Grant custom capability to the role
        $role = get_role('oscal_catalog_editor');
        if ($role && !$role->has_cap(self::CAPABILITY)) {
            $role->add_cap(self::CAPABILITY);
        }
        // Administrators should always have access
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAPABILITY)) {
            $admin->add_cap(self::CAPABILITY);
        }
        // (Optional) refresh rewrite rules for CPT
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
    /** Idempotent runtime check to keep caps/role in place even if plugin files updated without re-activation. */
    public function ensure_caps() : void {
        // Make sure role exists
        if (!get_role('oscal_catalog_editor')) {
            add_role('oscal_catalog_editor', 'OSCAL Catalog Editor', [
                'read' => true,
            ]);
        }
        // Ensure the custom cap on role and administrators
        $role = get_role('oscal_catalog_editor');
        if ($role && !$role->has_cap(self::CAPABILITY)) {
            $role->add_cap(self::CAPABILITY);
        }
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAPABILITY)) {
            $admin->add_cap(self::CAPABILITY);
        }
    }

    /* --------------------------- CPT --------------------------- */
    public function register_post_type() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'          => 'OSCAL Controls',
                'singular_name' => 'OSCAL Control',
            ],
            'public'       => true,
            'has_archive'  => 'security-control',
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-yes',
            // Do not expose comments in UI
            'supports'     => ['title', 'editor', 'revisions', 'custom-fields'],
            'rewrite'      => ['slug' => 'security-control'],
            'show_in_rest' => true,
            // Minimal template to avoid Gutenberg "template mismatch" warnings.
            'template_lock'=> 'insert',
            'template'     => [
                [ 'core/group', [ 'className' => 'oscal-card' ] ],
            ],
        ]);
        // Ensure comments/trackbacks support is removed
        remove_post_type_support(self::CPT, 'comments');
        remove_post_type_support(self::CPT, 'trackbacks');
    }

    /* --------------------------- Admin UI --------------------------- */
    public function register_admin_menu() {
        add_menu_page(
            'OSCAL Catalog Importer',
            'OSCAL Importer',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-media-code',
            58
        );
    }

    public function render_admin_page() {
        if (!current_user_can(self::CAPABILITY)) wp_die('Unauthorized.');
        $rows       = $this->get_display_prop_rows();   // Header fields
        $extrasRows = $this->get_extras_label_rows();   // Extras labels
        ?>
        <div class="wrap">
            <h1>OSCAL Catalog Importer</h1>
            <p class="oscal-muted">Upload an OSCAL catalog in <strong>YAML</strong> or <strong>JSON</strong> format. On import, all previously generated OSCAL control pages will be deleted and replaced with the fresh import.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
                <input type="hidden" name="action" value="oscal_do_import" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::UPLOAD_FIELD); ?>">OSCAL file (.yaml/.yml or .json)</label></th>
                        <td>
                            <input type="file" name="<?php echo esc_attr(self::UPLOAD_FIELD); ?>" id="<?php echo esc_attr(self::UPLOAD_FIELD); ?>" accept=".yaml,.yml,.json" required />
                            <p class="description">Expected root: <code>catalog</code> with a <code>groups</code> array; each group contains <code>controls</code> (or <code>control</code>).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Import Catalog'); ?>
            </form>

            <div class="oscal-box">
                <h2>Display Options</h2>
                <p class="oscal-muted">Configure the header fields and extras labels. These settings apply to all imported controls.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(self::NONCE_DISPLAY, self::NONCE_DISPLAY . '_nonce'); ?>
                    <input type="hidden" name="action" value="oscal_save_display" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Header fields</th>
                            <td><?php echo $this->render_prop_rows_editor('oscal_prop_rows', $rows); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Extras labels</th>
                            <td><?php echo $this->render_prop_rows_editor('oscal_extras_rows', $extrasRows); ?></td>
                        </tr>
                    </table>
                    <?php submit_button('Save Display Options', 'secondary'); ?>
                </form>
            </div>

            <div class="oscal-box">
                <h2>Formatting Notes</h2>
                <dl class="oscal-kv">
                    <dt>Post Type</dt><dd><code><?php echo esc_html(self::CPT); ?></code> (public; slug <code>security-control</code>)</dd>
                    <dt>Title</dt><dd><code>[zero-padded label] [control.title]</code> (e.g., <code>AC-24(01) Transmit Access Authorization Information</code>)</dd>
                    <dt>Slug</dt><dd>Zero-padded label; enhancements use hyphen notation (e.g., <code>ac-24-01</code>)</dd>
                    <dt>Template</dt><dd>Selectable “OSCAL Control Layout” (theme override supported)</dd>
                    <dt>Blocks</dt><dd>Header card (only configured header fields), nested Control Statement (bullets hidden), Description, collapsible Guidance, collapsible Extras, Enhancements list (base controls)</dd>
                    <dt>Params</dt><dd>Inline substitutions:
                        <ul>
                            <li><em><strong>[Selection (how-many): A; B]</strong></em></li>
                            <li><em><strong>[Assignment: text]</strong></em></li>
                            <li><strong>Values only</strong> when ODP specifies <code>values[]</code></li>
                        </ul>
                    </dd>
                </dl>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_css($hook) {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) return;
        $css = '
        .oscal-box{background:#fff;border:1px solid #ccd0d4;border-left-width:4px;border-left-color:#2271b1;padding:16px;margin-top:16px}
        .oscal-muted{color:#50575e}
        .oscal-kv dt{font-weight:600;margin-top:8px}
        .oscal-kv dd{margin:0 0 8px 0}
        .oscal-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0}
        .oscal-section{background:#fff;border:1px solid #edf2f7;border-radius:8px;padding:16px;margin:16px 0}
        .oscal-details{margin-top:8px}
        .oscal-prop-line{margin:2px 0;color:#334155}
        .oscal-prop-editor table{border-collapse:collapse}
        .oscal-prop-editor th,.oscal-prop-editor td{padding:4px 6px}
        .oscal-prop-editor input[type=text]{min-width:240px}
        .oscal-prop-editor .button{margin-left:4px}
        /* Hide bullets for nested Control Statement lists (admin preview) */
        .oscal-stmt-list,
        .oscal-stmt-list ul{list-style:none !important;margin:0;padding-left:1.25rem}
        .oscal-stmt-list li{margin:4px 0;list-style:none !important}
        .oscal-stmt-list li::marker{content:\'\';}
        .oscal-stmt-list li::before{content:none !important}
        .oscal-stmt-label{font-weight:600;margin-right:4px}
        ';
        wp_register_style('oscal-admin', false);
        wp_enqueue_style('oscal-admin');
        wp_add_inline_style('oscal-admin', $css);
    }

    public function enqueue_front_css() {
        $bodySel = '.single-' . self::CPT;
        $css = "
        .oscal-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0}
        .oscal-section{background:#fff;border:1px solid #edf2f7;border-radius:8px;padding:16px;margin:16px 0}
        .oscal-details{margin-top:8px}
        .oscal-prop-line{margin:2px 0;color:#334155}
        /* Constrain the title/header area as well (outside .entry-content) */
        {$bodySel} .oscal-control-header,
        {$bodySel} .entry-header{
            max-width:var(--wp--style--global--content-size, 840px);
            margin-left:auto;margin-right:auto;
            padding-left:1rem;padding-right:1rem;
        }
        {$bodySel} .entry-title,
        {$bodySel} .wp-block-post-title{
            display:block;
            max-width:var(--wp--style--global--content-size, 840px);
            margin-left:auto;margin-right:auto;
            padding-left:1rem;padding-right:1rem;
        }
        /* Constrain width on single CPT pages (fallback for themes without align support) */
        {$bodySel} .entry-content{max-width:var(--wp--style--global--content-size, 840px);margin-left:auto;margin-right:auto;padding-left:1rem;padding-right:1rem}
        {$bodySel} .alignwide{max-width:var(--wp--style--global--wide-size, 1120px);margin-left:auto;margin-right:auto}
        /* TOC styling */
        .oscal-toc{margin:16px 0}
        .oscal-toc h2{margin:12px 0 8px}
        .oscal-toc .oscal-toc-group{margin-top:16px}
        .oscal-toc-list{margin:0 0 0 1.25rem}
        .oscal-toc-list li{margin:4px 0}
        .oscal-toc-enh{margin-left:1.25rem}
        .oscal-toc a{text-decoration:none}
        /* Hide bullets for nested Control Statement lists */
        .oscal-stmt-list,
        .oscal-stmt-list ul{list-style:none !important;margin:0;padding-left:1.25rem}
        .oscal-stmt-list li{margin:4px 0;list-style:none !important}
        .oscal-stmt-list li::marker{content:'';}
        .oscal-stmt-list li::before{content:none !important}
        .oscal-stmt-label{font-weight:600;margin-right:4px}
        .oscal-enh-list{margin:0 0 0 1.25rem}
        ";
        wp_register_style('oscal-front', false);
        wp_enqueue_style('oscal-front');
        wp_add_inline_style('oscal-front', $css);
    }

    /** Styles for both editor and front-end to defeat theme bullets / width. */
    public function enqueue_common_css() {
        $bodySel = '.single-' . self::CPT;
        $css = "
        .oscal-stmt-list,
        .oscal-stmt-list ul{list-style:none !important;margin:0;padding-left:1.25rem}
        .oscal-stmt-list li{margin:4px 0;list-style:none !important}
        .oscal-stmt-list li::marker{content:'';}
        .oscal-stmt-list li::before{content:none !important}
        /* Editor/front width constraints for titles too */
        {$bodySel} .oscal-control-header,
        {$bodySel} .entry-header{
            max-width:var(--wp--style--global--content-size, 840px);
            margin-left:auto;margin-right:auto;
            padding-left:1rem;padding-right:1rem;
        }
        {$bodySel} .entry-title,
        {$bodySel} .wp-block-post-title{
            display:block;
            max-width:var(--wp--style--global--content-size, 840px);
            margin-left:auto;margin-right:auto;
            padding-left:1rem;padding-right:1rem;
        }
        {$bodySel} .alignwide{max-width:var(--wp--style--global--wide-size, 1120px);margin-left:auto;margin-right:auto}
        ";
        wp_register_style('oscal-common', false);
        wp_enqueue_style('oscal-common');
        wp_add_inline_style('oscal-common', $css);
    }

    public function allow_yaml_json_mimes($mimes) {
        // Allow common YAML/JSON types. WordPress only needs one mapping per extension.
        $mimes['yaml'] = 'application/x-yaml';
        $mimes['yml']  = 'application/x-yaml';
        $mimes['json'] = 'application/json';
        return $mimes;
    }

    /* --------------------------- Admin Notices (transients) --------------------------- */
    public function admin_notices() {
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) return;
        $key = 'oscal_notice_' . get_current_user_id();
        $note = get_transient($key);
        if (is_array($note) && !empty($note['msg'])) {
            delete_transient($key);
            $class = !empty($note['err']) ? 'notice notice-error' : 'notice notice-success is-dismissible';
            printf('<div class="%1$s"><p>%2$s</p></div>', $class, esc_html($note['msg']));
        }
    }
    private function set_notice(string $message, bool $is_error) : void {
        set_transient('oscal_notice_' . get_current_user_id(), ['msg' => wp_strip_all_tags($message), 'err' => $is_error ? 1 : 0], 60);
    }
    private function truncate_msg(string $s, int $max = 400) : string {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s, 'UTF-8') > $max ? (mb_substr($s, 0, $max - 1, 'UTF-8') . '…') : $s;
        }
        return strlen($s) > $max ? (substr($s, 0, $max - 1) . '…') : $s;
    }
    private function redirect_with_error($message) {
        $this->set_notice($this->truncate_msg($message), true);
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')));
        exit;
    }
    private function redirect_with_success($message) {
        $this->set_notice($this->truncate_msg($message), false);
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php')));
        exit;
    }

    /* --------------------------- Import --------------------------- */
    public function handle_import() {
        if (!current_user_can(self::CAPABILITY)) wp_die('Unauthorized.');
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        // Display options are managed in the Display Options form below, not during import.

        if (empty($_FILES[self::UPLOAD_FIELD]) || !isset($_FILES[self::UPLOAD_FIELD]['tmp_name'])) {
            $this->redirect_with_error('No file received. Increase PHP post_max_size / upload_max_filesize if needed.');
        }

        $file = $_FILES[self::UPLOAD_FIELD];
        if (!empty($file['error'])) {
            $this->redirect_with_error('Upload failed with error code: ' . (int)$file['error']);
        }

        $path = $file['tmp_name'];
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        $max  = (int) wp_max_upload_size();
        if ($size <= 0 || ($max > 0 && $size > $max)) {
            $this->redirect_with_error('File too large. Max allowed: ' . size_format($max));
        }
        // Prefer extension (case-insensitive), then ask WP, then heuristic.
        $ext_guess = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ['yaml' => 'application/x-yaml', 'yml' => 'application/x-yaml', 'json' => 'application/json'];
        $ft        = wp_check_filetype_and_ext($path, $file['name'], $allowed);
        $ext       = strtolower((string)($ft['ext'] ?? ''));
        if ($ext === '' && in_array($ext_guess, ['yaml','yml','json'], true)) {
            $ext = $ext_guess;
        }
        if ($ext === '') {
            // Lightweight heuristic: YAML often starts with '---' or contains a top-level 'catalog:' key
            $head = @file_get_contents($path, false, null, 0, 512);
            if (is_string($head) && (preg_match('/^\s*---/m', $head) || preg_match('/^\s*catalog\s*:/m', $head))) {
                $ext = 'yaml';
            }
        }
        if (!in_array($ext, ['yaml','yml','json'], true)) {
            $this->redirect_with_error('Unsupported file type (' . ($ext_guess ?: 'unknown') . '). Upload YAML (.yaml/.yml) or JSON.');
        }

        $data = $this->load_catalog_from_upload($path, $ext);
        if (!is_array($data)) {
            $this->redirect_with_error('Unable to parse the uploaded file as YAML/JSON catalog.');
        }

        $catalog = $this->array_get($data, 'catalog', []);
        $groups  = $this->array_get($catalog, 'groups', $this->array_get($catalog, 'group', []));
        if (!is_array($groups) || empty($groups)) {
            $this->redirect_with_error('Catalog missing groups[]. Expected catalog.groups array.');
        }

        // Purge previous posts to replace with fresh import (also legacy type)
        $this->purge_existing_posts();

        $created = 0;
        foreach ($groups as $group) {
            $controls = $this->array_get($group, 'controls', $this->array_get($group, 'control', []));
            if (!is_array($controls)) continue;
            foreach ($controls as $control) {
                $created += $this->process_control_recursive($control, $group, null);
            }
        }

        $this->redirect_with_success(sprintf('Import complete. %d control page(s) created.', $created));
    }

    private array $autoload_paths_tried = [];
    private function load_catalog_from_upload(string $path, string $ext) {
        if ($ext === 'json') {
            $json = file_get_contents($path);
            $arr  = json_decode($json, true);
            if (is_array($arr)) return $arr;
            return null;
        }
        // YAML: prefer symfony/yaml if present
        $this->bootstrap_autoloaders();
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            try {
                // Parse with PARSE_DATETIME to preserve dates as DateTime objects
                return \Symfony\Component\Yaml\Yaml::parseFile(
                    $path,
                    \Symfony\Component\Yaml\Yaml::PARSE_DATETIME
                );
            } catch (\Throwable $e) {
                $this->redirect_with_error('YAML parse error: ' . $this->truncate_msg($e->getMessage()));
            }
        }
        // Fallback to PECL yaml if available
        if (function_exists('yaml_parse')) {
            $cont = file_get_contents($path);
            $arr = @yaml_parse($cont);
            if (is_array($arr)) return $arr;
        }
        if (!empty($this->autoload_paths_tried)) {
            error_log('OSCAL Importer: YAML support missing. Tried autoloaders at: ' . implode(', ', $this->autoload_paths_tried));
        }
        $this->redirect_with_error('YAML support not available. Install symfony/yaml or enable PECL yaml, or upload JSON instead.');
        return null;
    }

    /** Composer autoloader bootstrap: try common locations. */
    private function bootstrap_autoloaders() : void {
        if (class_exists('\Symfony\Component\Yaml\Yaml')) return; // already available
        $candidates = [
            plugin_dir_path(__FILE__) . 'vendor/autoload.php',
            (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/vendor/autoload.php' : null),
            (defined('ABSPATH') ? ABSPATH . 'vendor/autoload.php' : null),
            (defined('ABSPATH') ? dirname(ABSPATH) . '/vendor/autoload.php' : null),
            (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR . '/vendor/autoload.php' : null),
        ];
        $candidates = array_values(array_filter($candidates));
        $candidates = apply_filters('oscal_autoload_paths', $candidates);
        foreach ($candidates as $autoload) {
            $this->autoload_paths_tried[] = $autoload;
            if (is_readable($autoload)) {
                require_once $autoload;
                if (class_exists('\Symfony\Component\Yaml\Yaml')) break;
            }
        }
    }

    private function purge_existing_posts() {
        // Also remove any legacy posts created when CPT was "oscal_control"
        $legacy = ['oscal_control'];
        $types  = array_unique(array_merge([self::CPT], $legacy));
        $posts = get_posts([
            'post_type'      => $types,
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        foreach ($posts as $pid) {
            wp_delete_post($pid, true);
        }
    }

    private function process_control_recursive(array $control, array $group, ?array $parent_control) : int {
        $count = 0;
        $post_id = $this->create_control_post($control, $group, $parent_control);
        if ($post_id) $count++;

        // Enhancements under this control
        $children = $this->array_get($control, 'controls', $this->array_get($control, 'control', []));
        if (is_array($children) && !empty($children)) {
            foreach ($children as $child) {
                $count += $this->process_control_recursive($child, $group, $control);
            }
            // If this is a BASE control (no parent), append the Enhancements section now that children exist
            if ($post_id && !$parent_control) {
                $base_id  = $this->sval($this->array_get($control, 'id', ''));
                if ($base_id !== '') {
                    $this->append_enhancements_section_to_post($post_id, $base_id);
                }
            }
        }
        return $count;
    }

    private function create_control_post(array $control, array $group, array $parent_control = null) {
        $zp     = $this->get_zero_padded_label($control);
        $cid    = $this->sval($this->array_get($control, 'id', ''));
        $titleS = $zp ?: $cid;
        $title  = trim(($titleS ? $titleS . ' ' : '') . $this->sval($this->array_get($control, 'title', '')));
        if ($title === '') $title = $titleS ?: 'Unnamed Control';

        $content = $this->render_control_content($control, $group, $parent_control);

        $postarr = [
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            // Slug must be ONLY the zero-padded label (normalized); fallback to id if needed
            'post_name'    => $this->slugify_label($zp ?: $cid),
            'meta_input'   => [
                '_wp_page_template' => 'oscal-control.php',
            ],
        ];

        // Standard insert; let WordPress sanitize slugs normally (dots → hyphens)
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return 0;

        if ($cid) update_post_meta($post_id, '_oscal_control_id', $cid);
        if ($zp)  update_post_meta($post_id, '_oscal_label_zero_padded', $zp);
        if (!empty($group['id']))    update_post_meta($post_id, '_oscal_group_id', $this->sval($group['id']));
        if (!empty($group['title'])) update_post_meta($post_id, '_oscal_group_title', $this->sval($group['title']));
        if ($parent_control) {
            $pcid = $this->sval($this->array_get($parent_control, 'id', ''));
            if ($pcid) update_post_meta($post_id, '_oscal_parent_control_id', $pcid);
        }

        return $post_id;
    }

    /* --------------------------- Content Rendering --------------------------- */
    private function render_control_content(array $control, array $group, array $parent_control = null) : string {
        // Gather values
        $gtitle    = $this->sval($this->array_get($group, 'title', ''));
        $labelZP   = $this->get_zero_padded_label($control) ?: $this->sval($this->array_get($control, 'id', ''));
        $pcid      = $parent_control ? $this->sval($this->array_get($parent_control, 'id', '')) : '';

        // Build parameter substitution map for this control (id + alt-identifier).
        $paramSubs = $this->build_param_substitutions($control);

        // Statement: collect single prose + full nested items tree
        [$stmt_single, $stmt_items_tree] = $this->collect_statement_tree($control);
        if ($stmt_single !== '') {
            $stmt_single = $this->substitute_param_placeholders($stmt_single, $paramSubs);
        }
        $stmt_list_html = $this->render_statement_items($stmt_items_tree, $paramSubs);

        // Description (discussion/description)
        $description = $this->extract_part_text($control, 'discussion');
        if ($description === '') {
            $description = $this->extract_part_text($control, 'description');
        }
        if ($description !== '') {
            $description = $this->substitute_param_placeholders($description, $paramSubs);
        }

        // Guidance
        $guidance  = $this->extract_part_text($control, 'guidance');
        if ($guidance !== '') {
            $guidance = $this->substitute_param_placeholders($guidance, $paramSubs);
        }

        $parts     = $this->array_get($control, 'parts',  $this->array_get($control, 'part',  []));

        $stmt_h    = $stmt_single ? $this->safe_inline_html($stmt_single) : '';
        $desc_h    = $description ? $this->safe_inline_html($description) : '';
        $guid_h    = $guidance  ? $this->safe_inline_html($guidance)  : '';

        $content  = "";

        // Header card (only configured header fields)
        $header_inner = '';
        // Inject configured header fields: Display Label: value(s)
        $rows = $this->get_display_prop_rows();
        foreach ($rows as $row) {
            $nm = $row['name'] ?? '';
            $lb = $row['label'] ?? '';
            if ($nm === '' || $lb === '') continue;
            $val = $this->prop_values_joined($control, $nm);
            if ($val === '') continue;
            $header_inner .= "<!-- wp:paragraph {\"className\":\"oscal-prop-line\"} -->\n<p class=\"oscal-prop-line\"><strong>" . esc_html($lb) . ":</strong> " . esc_html($val) . "</p>\n<!-- /wp:paragraph -->\n";
        }
        if ($header_inner !== '') {
            $content .= "<!-- wp:group {\"className\":\"oscal-card\",\"align\":\"wide\"} -->\n<div class=\"wp-block-group alignwide oscal-card\">";
            $content .= $header_inner;
            $content .= "</div><!-- /wp:group -->\n";
        }

        // Statement
        if ($stmt_h !== '' || $stmt_list_html !== '') {
            $content .= "<!-- wp:group {\"className\":\"oscal-section\",\"align\":\"wide\"} -->\n<div class=\"wp-block-group alignwide oscal-section\">";
            $content .= "<!-- wp:heading {\"level\":2} --><h2>Control Statement</h2><!-- /wp:heading -->\n";
            if ($stmt_h !== '') {
                $content .= "<!-- wp:paragraph --><p>{$stmt_h}</p><!-- /wp:paragraph -->\n";
            }
            if ($stmt_list_html !== '') {
                $content .= "<!-- wp:list {\"className\":\"oscal-stmt-list\"} -->\n{$stmt_list_html}\n<!-- /wp:list -->\n";
            }
            $content .= "</div><!-- /wp:group -->\n";
        }

        // Description
        if ($desc_h !== '') {
            $content .= "<!-- wp:group {\"className\":\"oscal-section\",\"align\":\"wide\"} -->\n<div class=\"wp-block-group alignwide oscal-section\">";
            $content .= "<!-- wp:heading {\"level\":2} --><h2>Description</h2><!-- /wp:heading -->\n";
            $content .= "<!-- wp:paragraph --><p>{$desc_h}</p><!-- /wp:paragraph -->\n";
            $content .= "</div><!-- /wp:group -->\n";
        }

        // Guidance (collapsible)
        if ($guid_h !== '') {
            $content .= "<!-- wp:group {\"className\":\"oscal-section oscal-extra\",\"align\":\"wide\"} -->\n<div class=\"wp-block-group alignwide oscal-section oscal-extra\">";
            $content .= "<!-- wp:details {\"className\":\"oscal-details\"} -->\n";
            $content .= "<details class=\"wp-block-details oscal-details\"><summary>Guidance</summary>\n";
            $content .= "<!-- wp:paragraph --><p>{$guid_h}</p><!-- /wp:paragraph -->\n";
            $content .= "</details>\n";
            $content .= "<!-- /wp:details -->\n";
            $content .= "</div><!-- /wp:group -->\n";
        }

        // Additional parts as collapsible details — ONLY show parts that have a matching admin mapping
        $extraBlocks = '';
        if (is_array($parts)) {
            // Cache extras label rows locally for efficiency
            $extrasOverride = $this->get_extras_label_rows();
            foreach ($parts as $part) {
                $name = isset($part['name']) ? (string)$part['name'] : '';
                if (in_array(strtolower($name), ['statement','guidance','discussion','description'], true)) continue;
                // Only display when there is an explicit admin mapping for this part.name
                $override = $this->get_extras_summary_label_cached($name, $extrasOverride);
                if ($override === '') continue; // no mapping → skip this extra
                $label = $override; // use mapped summary text
                $text  = isset($part['prose']) ? (string)$part['prose'] :
                         (isset($part['text'])  ? (string)$part['text']  : '');
                if ($text !== '') {
                    $text = $this->substitute_param_placeholders($text, $paramSubs);
                    $text = $this->safe_inline_html($text);
                    $extraBlocks .=
                        "<!-- wp:details {\"className\":\"oscal-details\"} -->\n" .
                        "<details class=\"wp-block-details oscal-details\"><summary>" . esc_html($label) . "</summary>\n" .
                        "<!-- wp:paragraph --><p>" . $text . "</p><!-- /wp:paragraph -->\n" .
                        "</details>\n" .
                        "<!-- /wp:details -->\n";
                }
            }
        }
        if ($extraBlocks !== '') {
            $content .= "<!-- wp:group {\"className\":\"oscal-section oscal-extra\",\"align\":\"wide\"} -->\n<div class=\"wp-block-group alignwide oscal-section oscal-extra\">";
            $content .= $extraBlocks;
            $content .= "</div><!-- /wp:group -->\n";
        }

        return $content;
    }

    /* --------------------------- Statement helpers --------------------------- */
    private function extract_part_text(array $control, string $target_name) : string {
        $parts = $this->array_get($control, 'parts', $this->array_get($control, 'part', []));
        if (!is_array($parts)) return '';
        foreach ($parts as $p) {
            $name = isset($p['name']) ? (string)$p['name'] : '';
            if (strcasecmp($name, $target_name) === 0) {
                if (isset($p['prose']) && $p['prose'] !== '') return (string)$p['prose'];
                if (isset($p['text'])  && $p['text']  !== '') return (string)$p['text'];
            }
        }
        return '';
    }

    /**
     * Collect the control statement with full nested tree:
     * Returns [single_prose, items_tree[]]
     * items_tree node: ['label' => string, 'text' => string, 'children' => array]
     */
    private function collect_statement_tree(array $control) : array {
        $parts = $this->array_get($control, 'parts', $this->array_get($control, 'part', []));
        if (!is_array($parts)) return ['', []];
        foreach ($parts as $p) {
            $name = isset($p['name']) ? (string)$p['name'] : '';
            if (strcasecmp($name, 'statement') !== 0) continue;
            $prose = '';
            if (!empty($p['prose'])) $prose = (string)$p['prose'];
            elseif (!empty($p['text'])) $prose = (string)$p['text'];
            $items = $this->collect_statement_items($p);
            return [$prose, $items];
        }
        return ['', []];
    }

    /** Recursively collect nested statement parts as items tree. */
    private function collect_statement_items(array $node) : array {
        $out = [];
        $children = $this->array_get($node, 'parts', $this->array_get($node, 'part', []));
        if (!is_array($children)) return $out;
        foreach ($children as $ch) {
            $label = '';
            if (!empty($ch['props']) && is_array($ch['props'])) {
                foreach ($ch['props'] as $pr) {
                    if (($pr['name'] ?? '') === 'label' && isset($pr['value'])) {
                        $label = (string)$pr['value'];
                        break;
                    }
                }
            }
            $text = '';
            if (isset($ch['prose']) && $ch['prose'] !== '') $text = (string)$ch['prose'];
            elseif (isset($ch['text'])  && $ch['text']  !== '') $text = (string)$ch['text'];
            $kids = $this->collect_statement_items($ch);
            $out[] = ['label' => $label, 'text' => $text, 'children' => $kids];
        }
        return $out;
    }

    /**
     * Render the nested items tree as HTML UL/LI with parameter substitutions.
     */
    private function render_statement_items(array $items, array $paramSubs) : string {
        if (empty($items)) return '';
        $html = '<ul class="wp-block-list oscal-stmt-list">';
        foreach ($items as $it) {
            $labelEsc = $it['label'] !== '' ? '<span class="oscal-stmt-label">' . esc_html($it['label']) . '</span> ' : '';
            $text     = (string)($it['text'] ?? '');
            if ($text !== '') {
                $text = $this->substitute_param_placeholders($text, $paramSubs);
                $text = $this->safe_inline_html($text);
            }
            $childHtml = $this->render_statement_items($it['children'] ?? [], $paramSubs);
            $html .= '<li>' . $labelEsc . $text . ($childHtml !== '' ? $childHtml : '') . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /* --------------------------- Parameter substitutions --------------------------- */
    /**
     * Build a lookup of parameter identifiers → display descriptor.
     * Keys include the param "id" and any props[name=alt-identifier].
     * Value shape: ['mode' => 'selection'|'assignment'|'values', 'text' => string]
     */
    private function build_param_substitutions(array $control) : array {
        $map = [];
        $params = $this->array_get($control, 'params', $this->array_get($control, 'param', []));
        if (!is_array($params)) return $map;
        foreach ((array)$params as $p) {
            $display = $this->format_param_display($p);
            if ($display === null || ($display['text'] ?? '') === '') continue;
            $ids = [];
            if (!empty($p['id'])) $ids[] = strtolower((string)$p['id']);
            // Include alt-identifier(s)
            $props = $this->array_get($p, 'props', []);
            if (is_array($props)) {
                foreach ($props as $pr) {
                    if (($pr['name'] ?? '') === 'alt-identifier' && isset($pr['value'])) {
                        $ids[] = strtolower((string)$pr['value']);
                    }
                }
            }
            foreach ($ids as $id) {
                $map[$id] = $display; // store descriptor
            }
        }
        return $map;
    }

    /**
     * Create a display descriptor for a parameter:
     * - If it has a select/choice list → mode=selection, text="[Selection (how-many): c1; c2; ...]"
     * - Else if it has values[] → mode=values, text="v1; v2; ..."
     * - Else → mode=assignment, text="[Assignment: <constraints.description or label>]"
     * Notes:
     *   - Do NOT use guidelines['prose'].
     *   - Choices and values are delimited by semicolons.
     */
    private function format_param_display(array $p) : ?array {
        // Selection (choices)
        $sel = $this->array_get($p, 'select', []);
        $choices = [];
        if (is_array($sel)) {
            $choiceArr = $this->array_get($sel, 'choice', []);
            if (!is_array($choiceArr)) $choiceArr = [$choiceArr];
            foreach ($choiceArr as $ch) {
                if (is_string($ch)) {
                    $choices[] = trim($ch);
                } elseif (is_array($ch)) {
                    if (isset($ch['label']))      $choices[] = trim((string)$ch['label']);
                    elseif (isset($ch['prose']))  $choices[] = trim((string)$ch['prose']);
                    elseif (isset($ch['value']))  $choices[] = trim((string)$ch['value']);
                }
            }
            $choices = array_filter($choices, function($x){ return $x !== ''; });
            if (!empty($choices)) {
                $howMany = '';
                if (isset($sel['how-many']) && is_string($sel['how-many']) && trim($sel['how-many']) !== '') {
                    $howMany = ' (' . trim($sel['how-many']) . ')';
                }
                // Build the Selection wrapper text and pre-style it bold+italic.
                $txt = '[Selection' . $howMany . ': ' . implode('; ', $choices) . ']';
                return ['mode' => 'selection', 'text' => '<em><strong>' . $txt . '</strong></em>'];
            }
        }

        // Values[] present → bold values without wrapper
        if (isset($p['values']) && is_array($p['values'])) {
            $vals = [];
            foreach ($p['values'] as $v) {
                $s = $this->normalize_scalar_display($v, (string)($p['id'] ?? ''));
                if ($s !== '') $vals[] = $s;
            }
            if (!empty($vals)) {
                return ['mode' => 'values', 'text' => implode('; ', $vals)];
            }
        }

        // Assignment text: ONLY constraints[].description or the label.
        $desc = '';
        $constraints = $this->array_get($p, 'constraints', []);
        if (is_array($constraints)) {
            $ds = [];
            foreach ($constraints as $c) {
                if (isset($c['description'])) {
                    $d = trim((string)$c['description']);
                    if ($d !== '') $ds[] = $d;
                }
            }
            if (!empty($ds)) {
                $desc = implode('; ', $ds);
            }
        }
        if ($desc === '' && !empty($p['label'])) {
            $desc = trim((string)$p['label']);
        }
        if ($desc === '' && !empty($p['props']) && is_array($p['props'])) {
            foreach ($p['props'] as $pr) {
                if (($pr['name'] ?? '') === 'label' && isset($pr['value'])) {
                    $desc = trim((string)$pr['value']);
                    if ($desc !== '') break;
                }
            }
        }
        if ($desc === '' && !empty($p['id'])) {
            $desc = (string)$p['id'];
        }
        return ['mode' => 'assignment', 'text' => '[Assignment: ' . $desc . ']'];
    }

    /**
     * Replace parameter placeholders within a text block using the lookup, RECURSIVELY.
     * Supports "{{ insert: param, <id> }}" and "{{ param, <id> }}", case-insensitive.
     * - selection/assignment → bold+italic [..]
     * - values[] → bold only
     *
     * We allow recursion so nested ODPs inside choices/descriptions are resolved (depth-capped).
     */
    private function substitute_param_placeholders(string $text, array $map, int $depth = 0) : string {
        if ($text === '' || empty($map)) return $text;
        if ($depth > 4) { // hard cap to avoid cycles
            return $this->safe_inline_html($text);
        }
        $pattern = '/\{\{\s*(?:insert:\s*)?param\s*,\s*([^\s\}]+)\s*\}\}/i';
        $out = preg_replace_callback($pattern, function($m) use ($map, $depth) {
            $key  = strtolower($m[1]);
            $disp = $map[$key] ?? null;
            if (!$disp) {
                // Unknown: show a safe fallback token
                return $this->safe_inline_html('<em><strong>[Parameter: ' . $m[1] . ']</strong></em>');
            }
            // Resolve any nested placeholders inside the display text BEFORE styling.
            $innerRaw = (string)$disp['text'];
            $resolved = $this->substitute_param_placeholders($innerRaw, $map, $depth + 1);
            $mode     = (string)$disp['mode'];
            if ($mode === 'values') {
                // Bold only
                return $this->safe_inline_html('<strong>' . $resolved . '</strong>');
            }
            // Selection/Assignment → bold+italic.
            // If already styled (because Selection text was pre-styled), don't double-wrap.
            if (preg_match('/^\s*<em>\s*<strong>.*<\/strong>\s*<\/em>\s*$/i', $resolved)) {
                return $this->safe_inline_html($resolved);
            }
            return $this->safe_inline_html('<em><strong>' . $resolved . '</strong></em>');
        }, $text);
        return is_string($out) ? $out : $text;
    }

    /** Permit only <strong> and <em> in inline text; escape everything else. */
    private function safe_inline_html(string $s) : string {
        return wp_kses($s, ['strong' => [], 'em' => []]);
    }

    /* --------------------------- Label/Slug helpers --------------------------- */
    /**
     * Extract zero-padded label from control.props:
     * props[] where name=label and class=zero-padded
     * Fallbacks: sp800-53a label, then any label, else ''.
     */
    private function get_zero_padded_label(array $control) : string {
        $props = $this->array_get($control, 'props', []);
        if (is_array($props)) {
            foreach ($props as $p) {
                if (($p['name'] ?? '') === 'label' && ($p['class'] ?? '') === 'zero-padded' && isset($p['value'])) {
                    return $this->sval($p['value']);
                }
            }
            foreach ($props as $p) {
                if (($p['name'] ?? '') === 'label' && ($p['class'] ?? '') === 'sp800-53a' && isset($p['value'])) {
                    return $this->sval($p['value']);
                }
            }
            foreach ($props as $p) {
                if (($p['name'] ?? '') === 'label' && isset($p['value'])) {
                    return $this->sval($p['value']);
                }
            }
        }
        return '';
    }

    /**
     * Normalize zero-padded label to slug.
     * - Controls: SI-06 → si-06
     * - Enhancements: AC-24(01) → ac-24-01 (dots avoided to prevent 404s)
     */
    private function slugify_label(string $label) : string {
        $s = strtolower($label);
        // Convert enhancements: (01) → -01 (avoid dots entirely)
        $s = preg_replace('/\((\d+)\)/', '-$1', $s);
        // Keep hyphens; strip everything else non-alnum
        $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        return $s ?: sanitize_title($label);
    }

    /* --------------------------- Utility --------------------------- */
    private function array_get($arr, $key, $default = null) {
        if (is_array($arr) && array_key_exists($key, $arr)) return $arr[$key];
        return $default;
    }
    private function sval($v) : string {
        if (is_string($v)) return $v;
        if (is_numeric($v)) return (string)$v;
        return '';
    }

    /* --------------------------- Settings: Header prop rows --------------------------- */
    private function render_prop_rows_editor(string $fieldBase, array $rows) : string {
        if (empty($rows)) $rows = [['name'=>'','label'=>'']];
        ob_start(); ?>
        <div class="oscal-prop-editor">
            <table>
                <thead><tr><th>Name (props.name or part.name)</th><th>Display Label</th><th></th></tr></thead>
                <tbody id="<?php echo esc_attr($fieldBase); ?>_tbody">
                <?php foreach ($rows as $i => $r):
                    $n = isset($r['name']) ? (string)$r['name'] : '';
                    $l = isset($r['label'])? (string)$r['label']: ''; ?>
                    <tr>
                        <td><input type="text" name="<?php echo esc_attr($fieldBase); ?>[name][]" value="<?php echo esc_attr($n); ?>" placeholder="implementation-level"></td>
                        <td><input type="text" name="<?php echo esc_attr($fieldBase); ?>[label][]" value="<?php echo esc_attr($l); ?>" placeholder="Implementation Level"></td>
                        <td><button type="button" class="button oscal-prop-remove">Remove</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button button-secondary" id="<?php echo esc_attr($fieldBase); ?>_add">Add row</button></p>
        </div>
        <script>
        (function(){
          const tbody = document.getElementById('<?php echo esc_js($fieldBase); ?>_tbody');
          const addBtn = document.getElementById('<?php echo esc_js($fieldBase); ?>_add');
          if (addBtn && tbody) {
            addBtn.addEventListener('click', function(){
              const tr = document.createElement('tr');
              tr.innerHTML = `<td><input type="text" name="<?php echo esc_js($fieldBase); ?>[name][]" value="" placeholder="implementation-level"></td>
                              <td><input type="text" name="<?php echo esc_js($fieldBase); ?>[label][]" value="" placeholder="Implementation Level"></td>
                              <td><button type="button" class="button oscal-prop-remove">Remove</button></td>`;
              tbody.appendChild(tr);
            });
            tbody.addEventListener('click', function(e){
              if (e.target && e.target.classList.contains('oscal-prop-remove')) {
                const tr = e.target.closest('tr'); if (tr) tr.remove();
              }
            });
          }
        })();
        </script>
        <?php
        return trim(ob_get_clean());
    }

    private function save_display_prop_rows($raw) : void {
        $rows = [];
        $names  = isset($raw['name'])  && is_array($raw['name'])  ? $raw['name']  : [];
        $labels = isset($raw['label']) && is_array($raw['label']) ? $raw['label'] : [];
        $len = max(count($names), count($labels));
        for ($i = 0; $i < $len; $i++) {
            $n = isset($names[$i])  ? strtolower(trim((string)$names[$i]))  : '';
            $l = isset($labels[$i]) ? trim((string)$labels[$i]) : '';
            // Sanitize: names allow a-z0-9._-, labels plain text
            $n = preg_replace('/[^a-z0-9._-]/', '', $n);
            $l = wp_strip_all_tags($l);
            if ($n !== '' && $l !== '') {
                $rows[] = ['name' => $n, 'label' => $l];
            }
        }
        update_option(self::OPTION_DISPLAY_PROP_MAP, $rows, false);
    }

    private function get_display_prop_rows() : array {
        $rows = get_option(self::OPTION_DISPLAY_PROP_MAP, []);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $n = isset($r['name'])  ? strtolower(preg_replace('/[^a-z0-9._-]/', '', (string)$r['name'])) : '';
            $l = isset($r['label']) ? wp_strip_all_tags((string)$r['label']) : '';
            if ($n !== '' && $l !== '') $out[] = ['name'=>$n, 'label'=>$l];
        }
        return $out;
    }

    private function prop_values_joined(array $control, string $wantName) : string {
        $props = $this->array_get($control, 'props', []);
        if (!is_array($props)) return '';
        $vals = [];
        foreach ($props as $p) {
            $pname = strtolower((string)($p['name'] ?? ''));
            if ($pname !== $wantName) continue;
            if (!array_key_exists('value', $p)) continue;
            $vals[] = $this->normalize_scalar_display($p['value'], $wantName);
        }
        return implode('; ', $vals);
    }

    /* ---------------------- Extras labels (part.name → summary) ---------------------- */
    private function save_extras_label_rows($raw) : void {
        $rows = [];
        $names  = isset($raw['name'])  && is_array($raw['name'])  ? $raw['name']  : [];
        $labels = isset($raw['label']) && is_array($raw['label']) ? $raw['label'] : [];
        $len = max(count($names), count($labels));
        for ($i = 0; $i < $len; $i++) {
            $n = isset($names[$i])  ? strtolower(trim((string)$names[$i]))  : '';
            $l = isset($labels[$i]) ? trim((string)$labels[$i]) : '';
            $n = preg_replace('/[^a-z0-9._-]/', '', $n);
            $l = wp_strip_all_tags($l);
            if ($n !== '' && $l !== '') {
                $rows[] = ['name' => $n, 'label' => $l];
            }
        }
        update_option(self::OPTION_EXTRAS_LABEL_MAP, $rows, false);
    }
    private function get_extras_label_rows() : array {
        $rows = get_option(self::OPTION_EXTRAS_LABEL_MAP, []);
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $r) {
            $n = isset($r['name'])  ? strtolower(preg_replace('/[^a-z0-9._-]/', '', (string)$r['name'])) : '';
            $l = isset($r['label']) ? wp_strip_all_tags((string)$r['label']) : '';
            if ($n !== '' && $l !== '') $out[] = ['name'=>$n, 'label'=>$l];
        }
        return $out;
    }
    private function get_extras_summary_label(string $partName) : string {
        if ($partName === '') return '';
        $want = strtolower(preg_replace('/[^a-z0-9._-]/', '', $partName));
        if ($want === '') return '';
        foreach ($this->get_extras_label_rows() as $row) {
            if (($row['name'] ?? '') === $want) {
                return (string)$row['label'];
            }
        }
        return '';
    }
    // Cached variant for tight loops
    private function get_extras_summary_label_cached(string $partName, array $rows) : string {
        if ($partName === '') return '';
        $want = strtolower(preg_replace('/[^a-z0-9._-]/', '', $partName));
        if ($want === '') return '';
        foreach ($rows as $row) {
            if (($row['name'] ?? '') === $want) {
                return (string)$row['label'];
            }
        }
        return '';
    }

    /* --------------------------- Date/time display normalization --------------------------- */
    private function normalize_scalar_display($value, string $contextName = '') : string {
        // DateTime provided by Symfony when PARSE_DATETIME is enabled
        if ($value instanceof \DateTimeInterface) {
            $h = (int)$value->format('H');
            $i = (int)$value->format('i');
            $s = (int)$value->format('s');
            return ($h === 0 && $i === 0 && $s === 0)
                ? $value->format('Y-m-d')
                : $value->format('c');
        }
        // Guarded epoch-to-string conversion for PECL yaml or other sources
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $n = (int)$value;
            if ($this->looks_like_epoch($n, $contextName)) {
                try {
                    $dt = (new \DateTimeImmutable('@' . $n))->setTimezone(new \DateTimeZone('UTC'));
                    // Date-only if exactly midnight UTC
                    return ($dt->format('H:i:s') === '00:00:00') ? $dt->format('Y-m-d') : $dt->format('c');
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        return trim((string)$value);
    }
    private function looks_like_epoch(int $n, string $contextName) : bool {
        // 1970-01-01 .. 2100-01-01
        if ($n < 0 || $n > 4102444800) return false;
        // Heuristic: only treat as date if the prop name suggests it.
        return (bool)preg_match('/date|time|timestamp|modified|updated|issued|created|published/i', $contextName);
    }

    /* --------------------------- Enhancements section (for base controls) --------------------------- */
    private function append_enhancements_section_to_post(int $post_id, string $base_control_id) : void {
        $html = $this->build_enhancements_section_html($base_control_id);
        if ($html === '') return;
        $post = get_post($post_id);
        if (!$post) return;
        $new = (string)$post->post_content . $html; // append after extras
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $new,
        ]);
    }
    private function build_enhancements_section_html(string $base_control_id) : string {
        // Fetch enhancement posts that point to this base control via meta _oscal_parent_control_id
        $children = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_key'       => '_oscal_label_zero_padded',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'   => '_oscal_parent_control_id',
                    'value' => $base_control_id,
                ],
            ],
        ]);
        if (empty($children)) return '';

        // Build list items (avoid duplicate label in title)
        $items = '';
        foreach ($children as $pid) {
            $label = get_post_meta($pid, '_oscal_label_zero_padded', true);
            $cid   = get_post_meta($pid, '_oscal_control_id', true);
            if ($label === '') $label = $cid;
            $title = get_the_title($pid);
            $url   = get_permalink($pid);
            $disp  = $this->strip_leading_label($title, (string)$label, (string)$cid);
            $items .= '<li><a href="' . esc_url($url) . '"><strong>' . esc_html($label) . '</strong>' . ($disp !== '' ? ' — ' . esc_html($disp) : '') . '</a></li>';
        }
        if ($items === '') return '';

        $out  = "\n" . "<!-- wp:group {\"className\":\"oscal-section\",\"align\":\"wide\"} -->\n";
        $out .= "<div class=\"wp-block-group alignwide oscal-section\">";
        $out .= "<!-- wp:heading {\"level\":2} --><h2>Enhancements</h2><!-- /wp:heading -->\n";
        $out .= "<!-- wp:list {\"className\":\"oscal-enh-list\"} -->\n";
        $out .= "<ul class=\"wp-block-list oscal-enh-list\">{$items}</ul>\n";
        $out .= "<!-- /wp:list -->\n";
        $out .= "</div><!-- /wp:group -->\n";
        return $out;
    }

    /** Remove a leading control label/ID from a title, if present (case-insensitive). */
    private function strip_leading_label(string $title, string $label, string $cid = '') : string {
        $t = ltrim($title);
        $patterns = [];
        if ($label !== '') {
            $patterns[] = '/^' . preg_quote($label, '/') . '\s*[-—:]*\s*/i';
        }
        if ($cid !== '' && strcasecmp($cid, $label) !== 0) {
            $patterns[] = '/^' . preg_quote($cid, '/') . '\s*[-—:]*\s*/i';
        }
        foreach ($patterns as $re) {
            $t2 = preg_replace($re, '', $t, 1, $count);
            if (!is_null($t2) && $count > 0) {
                $t = $t2;
                break;
            }
        }
        return trim($t);
    }

    /* --------------------------- Shortcode: [oscal_toc] --------------------------- */
    /**
     * Render a Table of Contents for CPT posts.
     * Usage: [oscal_toc group_by="family|none" enhancements="nest|flat|hide" title="All Controls" view="list"]
     */
    public function shortcode_oscal_toc($atts, $content = null, $tag = '') : string {
        $atts = shortcode_atts([
            'group_by'     => 'family',   // family|none
            'enhancements' => 'nest',     // nest|flat|hide
            'title'        => '',
            'view'         => 'list',     // list (reserved: table)
        ], $atts, $tag);
        $groupBy  = strtolower((string)$atts['group_by']);
        $ehMode   = strtolower((string)$atts['enhancements']);
        $titleOut = trim((string)$atts['title']);

        $args = [
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_oscal_label_zero_padded',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'ignore_sticky_posts' => true,
        ];
        $args = apply_filters('oscal_toc_query_args', $args, $atts);
        $ids  = get_posts($args);
        if (empty($ids)) {
            return '<div class="oscal-toc oscal-empty">No OSCAL controls found.</div>';
        }

        // Collect records
        $recs = [];
        foreach ($ids as $pid) {
            $label = get_post_meta($pid, '_oscal_label_zero_padded', true);
            if ($label === '') $label = get_post_meta($pid, '_oscal_control_id', true);
            $cid   = get_post_meta($pid, '_oscal_control_id', true);
            $pcid  = get_post_meta($pid, '_oscal_parent_control_id', true);
            $grp   = get_post_meta($pid, '_oscal_group_title', true);
            $title = get_the_title($pid);
            $url   = get_permalink($pid);
            $recs[] = [
                'pid'   => (int)$pid,
                'cid'   => (string)$cid,
                'pcid'  => (string)$pcid,
                'label' => (string)$label,
                'group' => (string)$grp,
                'title' => (string)$title,
                'url'   => (string)$url,
            ];
        }

        // Build base/enhancement structure by group
        $groups = []; // group => [ 'bases' => cid => rec, 'children' => cid => [recs...] ]
        $none   = __('Other', 'wp-oscal');
        foreach ($recs as $r) {
            $g = ($groupBy === 'family') ? ($r['group'] ?: $none) : '_all';
            if (!isset($groups[$g])) $groups[$g] = ['bases' => [], 'children' => []];
            if ($r['pcid'] === '') {
                $groups[$g]['bases'][$r['cid']] = $r;
            }
        }
        // Attach enhancements
        foreach ($recs as $r) {
            if ($r['pcid'] === '') continue;
            $g = ($groupBy === 'family') ? ($r['group'] ?: $none) : '_all';
            if (!isset($groups[$g])) $groups[$g] = ['bases' => [], 'children' => []];
            $groups[$g]['children'][$r['pcid']][] = $r;
        }

        // Sort groups by name (except _all sentinel)
        $gkeys = array_keys($groups);
        if ($groupBy === 'family') {
            natcasesort($gkeys);
            $gkeys = array_values($gkeys);
        } else {
            $gkeys = ['_all'];
        }

        // Render
        ob_start();
        echo '<div class="oscal-toc">';
        if ($titleOut !== '') {
            echo '<h2>' . esc_html($titleOut) . '</h2>';
        }
        foreach ($gkeys as $g) {
            $block = $groups[$g];
            $bases = $block['bases'];
            // Ensure base controls are in label order (safety)
            uasort($bases, function($a, $b){
                return strnatcasecmp($a['label'], $b['label']);
            });
            // Group heading (skip for _all)
            if ($groupBy === 'family') {
                echo '<div class="oscal-toc-group">';
                echo '<h2>' . esc_html($g) . '</h2>';
            }
            echo '<ul class="oscal-toc-list">';
            $flat = ($ehMode === 'flat');
            foreach ($bases as $cid => $r) {
                $disp = $this->strip_leading_label($r['title'], (string)$r['label'], (string)$r['cid']);
                echo '<li><a href="' . esc_url($r['url']) . '"><strong>' . esc_html($r['label']) . '</strong>' . ($disp !== '' ? ' — ' . esc_html($disp) : '') . '</a>';
                if ($ehMode === 'nest' && !empty($block['children'][$cid])) {
                    usort($block['children'][$cid], function($a, $b){
                        return strnatcasecmp($a['label'], $b['label']);
                    });
                    echo '<ul class="oscal-toc-enh">';
                    foreach ($block['children'][$cid] as $ch) {
                        $dispCh = $this->strip_leading_label($ch['title'], (string)$ch['label'], (string)$ch['cid']);
                        echo '<li><a href="' . esc_url($ch['url']) . '"><strong>' . esc_html($ch['label']) . '</strong>' . ($dispCh !== '' ? ' — ' . esc_html($dispCh) : '') . '</a></li>';
                    }
                    echo '</ul>';
                } elseif ($flat && !empty($block['children'][$cid])) {
                    foreach ($block['children'][$cid] as $ch) {
                        $dispCh = $this->strip_leading_label($ch['title'], (string)$ch['label'], (string)$ch['cid']);
                        echo '</li><li><a href="' . esc_url($ch['url']) . '"><strong>' . esc_html($ch['label']) . '</strong>' . ($dispCh !== '' ? ' — ' . esc_html($dispCh) : '') . '</a>';
                    }
                }
                echo '</li>';
            }
            echo '</ul>';
            if ($groupBy === 'family') {
                echo '</div>';
            }
        }
        echo '</div>';
        return (string)ob_get_clean();
    }

    /* --------------------------- Save-only handler for Display Options --------------------------- */
    public function handle_save_display() {
        if (!current_user_can(self::CAPABILITY)) wp_die('Unauthorized.');
        check_admin_referer(self::NONCE_DISPLAY, self::NONCE_DISPLAY . '_nonce');
        $this->save_display_prop_rows($_POST['oscal_prop_rows'] ?? []);
        $this->save_extras_label_rows($_POST['oscal_extras_rows'] ?? []);
        $this->redirect_with_success('Display options saved.');
    }

    /* --------------------------- Comments: force disabled for CPT --------------------------- */
    /** Return false for comments/pings on CPT. */
    public function disable_cpt_comments($open, $post_id) {
        $ptype = get_post_type($post_id);
        if ($ptype === self::CPT) {
            return false;
        }
        return $open;
    }
    /** Hide any existing comments array on CPT (nothing to render). */
    public function hide_cpt_comments($comments, $post_id) {
        $ptype = get_post_type($post_id);
        if ($ptype === self::CPT) {
            return [];
        }
        return $comments;
    }

    /* --------------------------- Theme template registration --------------------------- */
    // Register a selectable template in the editor for CPT
    public function register_theme_template(array $templates, $theme, $post, $post_type) : array {
        if ($post_type === self::CPT) {
            $templates['oscal-control.php'] = 'OSCAL Control Layout';
        }
        return $templates;
    }

    // Load the selected template; allow theme override via locate_template()
    public function maybe_use_oscal_template(string $template) : string {
        if (!is_singular(self::CPT)) return $template;

        $post_id   = get_queried_object_id();
        $selected  = $post_id ? get_page_template_slug($post_id) : '';
        if ($selected !== 'oscal-control.php') {
            return $template; // theme default handling
        }

        // Prefer a theme/child-theme override if present
        $theme_tpl = locate_template(['oscal-control.php', 'templates/oscal-control.php']);
        if (!empty($theme_tpl)) {
            return $theme_tpl;
        }

        // Plugin fallback (optional file)
        $plugin_tpl = plugin_dir_path(__FILE__) . 'templates/oscal-control.php';
        if (file_exists($plugin_tpl)) {
            return $plugin_tpl;
        }

        return $template;
    }
}

new OSCAL_Catalog_Importer();

// Add role/capabilities on activation
register_activation_hook(__FILE__, ['OSCAL_Catalog_Importer', 'activate_plugin']);

endif; // class_exists
