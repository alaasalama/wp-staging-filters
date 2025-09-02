<?php
namespace WPSFM;

class Snippets {
    const MU_FILE = 'wp-staging-custom-snippets.php';

    public function get_all() {
        $snippets = get_option(\WPSFM_Plugin::OPTION_SNIPPETS, []);
        return is_array($snippets) ? $snippets : [];
    }

    public function add_or_update($id, $title, $code) {
        $snippets = $this->get_all();
        $updated = false;
        foreach ($snippets as &$sn) {
            if ($sn['id'] === $id) { $sn['title'] = $title; $sn['code'] = $code; $updated = true; break; }
        }
        if (!$updated) { $snippets[] = ['id'=>$id,'title'=>$title,'code'=>$code]; }
        update_option(\WPSFM_Plugin::OPTION_SNIPPETS, $snippets);
        return $snippets;
    }

    public function delete($id) {
        $snippets = $this->get_all();
        $snippets = array_values(array_filter($snippets, function($s) use ($id){ return $s['id'] !== $id; }));
        update_option(\WPSFM_Plugin::OPTION_SNIPPETS, $snippets);
        return $snippets;
    }

    private function get_mu_dir() {
        return trailingslashit(WP_CONTENT_DIR) . 'mu-plugins';
    }

    public function get_mu_file_path() {
        return $this->get_mu_dir() . '/' . self::MU_FILE;
    }

    private function ensure_mu_file_exists(&$error = null) {
        $mu_dir = $this->get_mu_dir();
        if (!file_exists($mu_dir)) {
            if (!function_exists('wp_mkdir_p')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!wp_mkdir_p($mu_dir)) {
                $error = 'Failed to create mu-plugins directory: ' . esc_html($mu_dir);
                return false;
            }
        }
        $file = $this->get_mu_file_path();
        if (!file_exists($file)) {
            $header = "<?php\n/*\nPlugin Name: WP Staging Custom Snippets\nDescription: Snippets managed by WP Staging Filters Manager.\nAuthor: Site Admin\n*/\n\n";
            $res = @file_put_contents($file, $header);
            if ($res === false) {
                $error = 'Failed to create mu-plugin file: ' . esc_html($file);
                return false;
            }
        }
        return true;
    }

    public function rebuild_mu_file(&$error = null) {
        if (!$this->ensure_mu_file_exists($error)) {
            return false;
        }
        $snippets = $this->get_all();
        $header = "<?php\n/*\nPlugin Name: WP Staging Custom Snippets\nDescription: Snippets managed by WP Staging Filters Manager.\nAuthor: Site Admin\n*/\n\n";
        $body = '';
        foreach ($snippets as $snip) {
            if (empty($snip['id']) || !isset($snip['code'])) continue;
            $title = isset($snip['title']) ? $snip['title'] : ('Snippet ' . $snip['id']);
            $code = (string)$snip['code'];
            // Remove opening and closing PHP tags to keep file valid
            $code = preg_replace('/^\s*<\?php\s*/', '', $code);
            $code = str_replace('?>', '', $code);
            $code = rtrim($code) . "\n";
            $body .= "/**\n * Snippet: " . $title . "\n * ID: " . $snip['id'] . "\n */\n";
            $body .= $code . "\n";
        }
        $content = $header . $body;
        $res = @file_put_contents($this->get_mu_file_path(), $content);
        if ($res === false) {
            $error = 'Failed to write mu-plugin file: ' . esc_html($this->get_mu_file_path());
            return false;
        }
        return true;
    }
}
