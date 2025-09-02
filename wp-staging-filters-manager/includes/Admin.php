<?php
namespace WPSFM;

class Admin {
    private $plugin;

    public function __construct(\WPSFM_Plugin $plugin) {
        $this->plugin = $plugin;
    }

    public function register_menu() {
        add_management_page(
            'WP Staging Filters Manager',
            'WP Staging Filters',
            'manage_options',
            'wpsfm',
            [$this->plugin, 'render_page']
        );
    }

    public function render_snippets_table($snippets) {
        ob_start();
        if (empty($snippets)) {
            echo '<p id="wpsfm-snippets-table" class="wpsfm-muted">No snippets added yet.</p>';
        } else {
            echo '<table id="wpsfm-snippets-table" class="widefat striped" style="max-width:900px;">';
            echo '<thead><tr><th>Title</th><th>ID</th><th>Actions</th></tr></thead><tbody>';
            foreach ($snippets as $sn) {
                echo '<tr>';
                echo '<td>' . esc_html($sn['title']) . '</td>';
                echo '<td><code>' . esc_html($sn['id']) . '</code></td>';
                echo '<td><a href="#" class="button-link delete wpsfm-del" data-id="' . esc_attr($sn['id']) . '">Delete</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        return ob_get_clean();
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        settings_errors('wpsfm');

        $parsed = get_option(\WPSFM_Plugin::OPTION_PARSED, []);
        if (!is_array($parsed)) $parsed = [];
        $snippets = $this->plugin->snippets()->get_all();

        $selected_path = isset($_POST['select_path']) ? sanitize_text_field(wp_unslash($_POST['select_path'])) : '';
        $selected_item = null;
        if ($selected_path) {
            foreach ($parsed as $it) {
                if ($it['path'] === $selected_path) { $selected_item = $it; break; }
            }
        }

        // The rest of this method mirrors the previous render_page UI, but calls helpers via $this->plugin
        // For brevity, call back into plugin to output the existing UI to avoid duplication.
        // In a full refactor, we would move all UI glue here. For now, delegate.
        $this->plugin->_legacy_render_page($parsed, $snippets, $selected_item, $selected_path);
    }
}
