<?php
/*
Plugin Name: WP Staging Filters Manager
Description: Import WP Staging filters/actions from docs, edit, and manage them as a single MU-plugin of snippets.
Version: 0.2.0
Author: Alaa Salama
*/

if (!defined('ABSPATH')) {
    exit;
}

// Simple bootstrap for includes
require_once __DIR__ . '/includes/Importer.php';
require_once __DIR__ . '/includes/Snippets.php';
require_once __DIR__ . '/includes/Admin.php';

use WPSFM\Importer;
use WPSFM\Snippets;
use WPSFM\Admin;

class WPSFM_Plugin {
    const OPTION_PARSED = 'wpsfm_parsed_docs';
    const OPTION_SNIPPETS = 'wpsfm_snippets';
    const MU_FILE = 'wp-staging-custom-snippets.php';
    const DOCS_URL = 'https://wp-staging.com/docs/actions-and-filters/';
    const NONCE_ACTION = 'wpsfm_nonce_action';
    private $importer;
    private $snippets;
    private $admin;

    public function __construct() {
        $this->importer = new Importer();
        $this->snippets = new Snippets();
        $this->admin = new Admin($this);
        add_action('admin_menu', [$this->admin, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        // AJAX endpoints
        add_action('wp_ajax_wpsfm_refresh_docs', [$this, 'ajax_refresh_docs']);
        add_action('wp_ajax_wpsfm_get_snippet', [$this, 'ajax_get_snippet']);
        add_action('wp_ajax_wpsfm_add_snippet', [$this, 'ajax_add_snippet']);
        add_action('wp_ajax_wpsfm_delete_snippet', [$this, 'ajax_delete_snippet']);
    }

    public function register_menu() {
        add_management_page(
            'WP Staging Filters Manager',
            'WP Staging Filters',
            'manage_options',
            'wpsfm',
            [$this, 'render_page']
        );
    }

    private function get_mu_file_path() { return $this->snippets->get_mu_file_path(); }

    // Parser removed; Importer encapsulates parsing.

    private function display_label($path) {
        $prefix = 'Actions and Filters – Customize WP Staging > ';
        if (strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }
        return $path;
    }

    private function id_from_path($path) {
        return sanitize_title($this->display_label($path));
    }

    private function normalize_id($id) {
        $id = sanitize_title($id);
        $longPrefix = 'actions-and-filters-customize-wp-staging-';
        if (strpos($id, $longPrefix) === 0) {
            $id = substr($id, strlen($longPrefix));
        }
        return $id;
    }

    private function import_docs(&$message = null, &$error = null) {
        return $this->importer->import_docs($message, $error);
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) return;

        // Import/refresh
        if (isset($_POST['wpsfm_action']) && $_POST['wpsfm_action'] === 'refresh_docs') {
            check_admin_referer(self::NONCE_ACTION);
            $msg = $err = null;
            $ok = $this->import_docs($msg, $err);
            if ($ok) {
                add_settings_error('wpsfm', 'wpsfm_ref_ok', $msg, 'updated');
            } else {
                add_settings_error('wpsfm', 'wpsfm_ref_err', $err, 'error');
            }
            return;
        }

        // Load a snippet into editor (handled on render side via POSTed id)

        // Import from pasted HTML
        if (isset($_POST['wpsfm_action']) && $_POST['wpsfm_action'] === 'import_pasted_html') {
            check_admin_referer(self::NONCE_ACTION);
            $html = isset($_POST['wpsfm_pasted_html']) ? (string) wp_unslash($_POST['wpsfm_pasted_html']) : '';
            if (trim($html) === '') {
                add_settings_error('wpsfm', 'wpsfm_paste_empty', 'Please paste HTML content from the docs page.', 'error');
                return;
            }
            $items = $this->importer->parse_docs_html($html);
            if (empty($items)) {
                add_settings_error('wpsfm', 'wpsfm_paste_nothing', 'Could not find any snippets in the pasted HTML.', 'error');
                return;
            }
            update_option(self::OPTION_PARSED, $items);
            add_settings_error('wpsfm', 'wpsfm_paste_ok', sprintf('Imported %d snippets from pasted HTML.', count($items)), 'updated');
            return;
        }

        // Diagnostics removed for simplicity

        // Add/update snippet to MU plugin
        if (isset($_POST['wpsfm_action']) && $_POST['wpsfm_action'] === 'add_snippet') {
            check_admin_referer(self::NONCE_ACTION);
            $title = isset($_POST['snippet_title']) ? sanitize_text_field(wp_unslash($_POST['snippet_title'])) : '';
            $code = isset($_POST['snippet_code']) ? (string) wp_unslash($_POST['snippet_code']) : '';
            $id = isset($_POST['snippet_id']) && $_POST['snippet_id'] !== '' ? sanitize_title(wp_unslash($_POST['snippet_id'])) : sanitize_title($title . '-' . microtime(true));

            if (empty($title)) $title = 'Snippet ' . $id;
            if (empty(trim($code))) {
                add_settings_error('wpsfm', 'wpsfm_add_empty', 'Snippet code cannot be empty.', 'error');
                return;
            }

            $snippets = $this->snippets->add_or_update($id, $title, $code);
            $err = null;
            if ($this->snippets->rebuild_mu_file($err)) {
                add_settings_error('wpsfm', 'wpsfm_add_ok', $updated ? 'Snippet updated.' : 'Snippet added.', 'updated');
            } else {
                add_settings_error('wpsfm', 'wpsfm_add_err', $err ?: 'Failed to rebuild mu-plugin file.', 'error');
            }
            return;
        }

        // Delete snippet
        if (isset($_GET['wpsfm_action']) && $_GET['wpsfm_action'] === 'delete_snippet' && isset($_GET['_wpnonce'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'], self::NONCE_ACTION)) return;
            $id = isset($_GET['id']) ? sanitize_title(wp_unslash($_GET['id'])) : '';
            if ($id === '') return;
            $current = $this->snippets->get_all();
            $before = count($current);
            $snippets = $this->snippets->delete($id);
            $err = null;
            if ($this->snippets->rebuild_mu_file($err)) {
                $msg = (count($snippets) < $before) ? 'Snippet deleted.' : 'Snippet not found.';
                add_settings_error('wpsfm', 'wpsfm_del_ok', $msg, 'updated');
            } else {
                add_settings_error('wpsfm', 'wpsfm_del_err', $err ?: 'Failed to rebuild mu-plugin file.', 'error');
            }
            return;
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        settings_errors('wpsfm');

        $parsed = get_option(self::OPTION_PARSED, []);
        if (!is_array($parsed)) $parsed = [];
        $snippets = $this->snippets->get_all();

        $selected_path = isset($_POST['select_path']) ? sanitize_text_field(wp_unslash($_POST['select_path'])) : '';
        $selected_item = null;
        if ($selected_path) {
            foreach ($parsed as $it) {
                if ($it['path'] === $selected_path) { $selected_item = $it; break; }
            }
        }

        echo '<div class="wrap" id="wpsfm-app">';
        echo '<h1 style="margin-bottom:12px;">WP Staging Filters Manager</h1>';
        echo '<style>#wpsfm-app .wpsfm-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:14px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)}#wpsfm-app .wpsfm-field{margin:10px 0}#wpsfm-app .wpsfm-row{display:grid;grid-template-columns:minmax(300px,1fr) auto;gap:8px;align-items:end;max-width:900px}#wpsfm-app select,#wpsfm-app input[type="search"],#wpsfm-app input[type="text"],#wpsfm-app textarea{max-width:900px;width:100%}#wpsfm-app textarea.wpsfm-code{font-family:Menlo,Monaco,Consolas,\"Liberation Mono\",monospace;line-height:1.45;background:#f6f8fa;border:1px solid #ccd0d4;border-radius:6px;padding:12px;tab-size:4;white-space:pre;overflow:auto}#wpsfm-app .CodeMirror{max-width:900px;width:100%;border:1px solid #ccd0d4;border-radius:6px}#wpsfm-app .wpsfm-muted{color:#666}#wpsfm-app .wpsfm-section-title{margin:18px 0 8px}#wpsfm-app .wpsfm-actions button{margin-top:0}#wpsfm-app .wpsfm-badge{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:999px;background:#e7f7ea;border:1px solid #b7ebc6;color:#237a3b;font-size:12px;line-height:18px;opacity:0;transform:translateY(-2px);transition:opacity .25s ease}#wpsfm-app .wpsfm-badge.show{opacity:1;transform:translateY(0)}#wpsfm-app .wpsfm-picker-wrap{max-width:900px}#wpsfm-app .wpsfm-suggestions{position:absolute;z-index:1000;left:0;right:0;top:100%;background:#fff;border:1px solid #dcdcde;border-top:none;max-height:320px;overflow:auto;display:none;border-bottom-left-radius:6px;border-bottom-right-radius:6px;box-shadow:0 4px 8px rgba(0,0,0,.06)}#wpsfm-app .wpsfm-suggestions.show{display:block}#wpsfm-app .wpsfm-suggestions .item{padding:8px 10px;cursor:pointer;white-space:normal}#wpsfm-app .wpsfm-suggestions .item:hover,#wpsfm-app .wpsfm-suggestions .item.active{background:#f0f6ff}</style>';

        echo '<p>This tool imports the filters/actions from <a href="' . esc_url(self::DOCS_URL) . '" target="_blank" rel="noreferrer">WP Staging Docs</a> and lets you add or delete code snippets into a single MU plugin.</p>';

        echo '<h2 class="title wpsfm-section-title">Docs Import</h2>';
        echo '<form method="post" class="wpsfm-card" style="margin-bottom:1em;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="wpsfm_action" value="refresh_docs" />';
        echo '<button type="button" id="wpsfm-refresh" class="button button-primary">Refresh from Docs</button> <span id="wpsfm-refresh-spin" class="spinner" style="float:none;margin-top:0;"></span><span id="wpsfm-refresh-ok" class="wpsfm-badge" aria-live="polite" aria-atomic="true" style="vertical-align:middle;">Imported</span>';
        if (!empty($parsed)) {
            echo '<span>Imported snippets: ' . intval(count($parsed)) . '</span>';
        } else {
            echo '<span>No snippets imported yet. Click Refresh.</span>';
        }
        echo '</form>';

        echo '<hr />';

        // Manual import from pasted HTML
        echo '<details style="margin:10px 0 18px;">';
        echo '<summary><strong>Manual import via pasted HTML</strong> (use if remote fetch fails)</summary>';
        echo '<form method="post" style="margin-top:10px;">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="wpsfm_action" value="import_pasted_html" />';
        echo '<p>Open ' . esc_html(self::DOCS_URL) . ' in your browser, copy the full page HTML (View Source), and paste below:</p>';
        echo '<textarea name="wpsfm_pasted_html" rows="8" style="width:100%;font-family:monospace;"></textarea>';
        echo '<p><button class="button">Import from Pasted HTML</button></p>';
        echo '</form>';
        echo '</details>';

        // Diagnostics removed

        echo '<h2 class="title wpsfm-section-title">Add or Edit Snippet</h2>';
        echo '<form method="post" class="wpsfm-card">';
        wp_nonce_field(self::NONCE_ACTION);

        // Selector of imported items
        echo '<div class="wpsfm-row wpsfm-field">'
           . '<div class="wpsfm-picker-wrap" style="position:relative;">'
           . '<label for="wpsfm-picker"><strong>Search & Choose from Docs</strong></label><br />'
           . '<input type="text" id="wpsfm-picker" placeholder="Search and choose..." autocomplete="off" />'
           . '<input type="hidden" name="select_path" id="select_path" value="' . esc_attr($selected_path) . '" />'
           . '<div id="wpsfm-suggestions" class="wpsfm-suggestions" role="listbox" aria-label="Docs suggestions"></div>'
           . '</div>'
           . '<div class="wpsfm-actions"><button type="button" class="button" id="wpsfm-load">Load Snippet</button></div>'
           . '</div>';

        // Editor fields
        $prefill_title = $selected_item ? $selected_item['title'] : '';
        $prefill_code = $selected_item ? $selected_item['code'] : '';
        $prefill_id = $selected_item ? $this->id_from_path($selected_item['path']) : '';

        echo '<div class="wpsfm-field"><label for="snippet_title"><strong>Title</strong></label><br />';
        echo '<input type="text" id="snippet_title" name="snippet_title" value="' . esc_attr($prefill_title) . '" class="regular-text" placeholder="My snippet title" /></p>';

        echo '<div class="wpsfm-field"><label for="snippet_code"><strong>Code</strong></label><br />';
        echo '<textarea id="snippet_code" class="wpsfm-code" name="snippet_code" rows="14" wrap="off" style="width:100%;">' . esc_textarea($prefill_code) . '</textarea></div>';

        $nonce = wp_create_nonce(self::NONCE_ACTION);
        echo '<input type="hidden" id="wpsfm-nonce" value="' . esc_attr($nonce) . '" />';
        echo '<input type="hidden" name="snippet_id" id="snippet_id" value="' . esc_attr($prefill_id) . '" />';
        echo '<p><button type="button" class="button button-primary" id="wpsfm-add">Add / Update Snippet</button><span id="wpsfm-add-ok" class="wpsfm-badge" aria-live="polite" aria-atomic="true">Saved</span></p>';

        echo '</form>';

        echo '<hr />';

        echo '<h2 class="title wpsfm-section-title">Current MU Snippets</h2>';
        $mu_file = $this->get_mu_file_path();
        echo '<p class="wpsfm-muted">MU file: <code>' . esc_html($mu_file) . '</code></p>';
        echo '<div id="wpsfm-snippets-wrap">';
        echo $this->render_snippets_table($snippets);
        echo '</div>';

        echo '<p class="wpsfm-muted" style="margin-top:1em;">Tip: You can also paste your own custom code in the editor above and add it without selecting from docs.</p>';

        // Dataset for picker
        $docs_data = [];
        foreach ($parsed as $it) { $docs_data[] = [ 'value' => $it['path'], 'text' => $this->display_label($it['path']) ]; }
        echo '<script type="application/json" id="wpsfm-data">' . wp_json_encode($docs_data) . '</script>';

        // Inline script for picker + actions
        ob_start(); ?>
<script>
(function(){
  'use strict';
  var $ = document.querySelector.bind(document);
  var picker = $('#wpsfm-picker');
  var selectVal = $('#select_path');
  var sugg = $('#wpsfm-suggestions');
  var nonceEl = $('#wpsfm-nonce');
  var refreshBtn = $('#wpsfm-refresh');
  var refreshSpin = $('#wpsfm-refresh-spin');
  var refreshOk = $('#wpsfm-refresh-ok');
  var addOk = $('#wpsfm-add-ok');
  if(!picker || !selectVal){ return; }

  var data = [];
  try { var t = document.getElementById('wpsfm-data'); if (t) { data = JSON.parse(t.textContent||'[]'); } } catch(e){ console.error('WPSFM data parse', e); }
  var total = data.length;

  function renderSuggestions(list){
    if (!sugg) return;
    if (!list || !list.length) { sugg.innerHTML = ''; sugg.classList.remove('show'); return; }
    var html = '';
    for (var i=0;i<Math.min(list.length, 200); i++) {
      var it = list[i];
      html += '<div class="item" role="option" data-value="'+encodeURIComponent(it.value)+'">'+ it.text.replace(/</g,'&lt;') +'</div>';
    }
    sugg.innerHTML = html;
    sugg.classList.add('show');
  }

  function filter(){
    var q = (picker.value || '').toLowerCase().trim();
    var out = [];
    if (!q || q.length < 2) { sugg.classList.remove('show'); sugg.innerHTML=''; return; }
    else {
      for (var i=0; i<data.length; i++) {
        var t = (data[i].text||'').toLowerCase();
        if (t.indexOf(q) !== -1) out.push(data[i]);
      }
    }
    renderSuggestions(out);
  }

  picker.addEventListener('input', filter);
  picker.addEventListener('focus', function(){
    // Show full list on focus (first 10 visible via max-height + scroll)
    renderSuggestions(data);
  });
  picker.addEventListener('keydown', function(e){
    if (!sugg || !sugg.classList.contains('show')) return;
    var items = [].slice.call(sugg.querySelectorAll('.item'));
    var idx = items.findIndex(function(el){ return el.classList.contains('active'); });
    if (e.key === 'ArrowDown') { e.preventDefault(); if (idx < items.length-1) { if (idx>=0) items[idx].classList.remove('active'); items[++idx].classList.add('active'); items[idx].scrollIntoView({block:'nearest'}); } }
    if (e.key === 'ArrowUp') { e.preventDefault(); if (idx > 0) { items[idx].classList.remove('active'); items[--idx].classList.add('active'); items[idx].scrollIntoView({block:'nearest'}); } }
    if (e.key === 'Enter') { e.preventDefault(); if (idx>=0) { items[idx].click(); } else if (items.length) { items[0].click(); } }
    if (e.key === 'Escape') { sugg.classList.remove('show'); }
  });
  document.addEventListener('click', function(ev){ if (!sugg) return; if (!sugg.contains(ev.target) && ev.target !== picker) { sugg.classList.remove('show'); } });
  if (sugg) {
    sugg.addEventListener('click', function(ev){ var el = ev.target.closest('.item'); if (!el) return; var val = decodeURIComponent(el.getAttribute('data-value')||''); var text = el.textContent; selectVal.value = val; picker.value = text; sugg.classList.remove('show'); });
  }

  function ajax(action, body){
    body = body || {};
    body.action = action;
    body._ajax_nonce = nonceEl ? nonceEl.value : '';
    var enc = Object.keys(body).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(body[k]); }).join('&');
    return fetch(ajaxurl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: enc }).then(function(r){return r.json();});
  }

  function showBadge(el){
    if(!el) return; el.classList.add('show');
    setTimeout(function(){ el.classList.remove('show'); }, 1600);
  }

  var loadBtn = $('#wpsfm-load');
  if (loadBtn) {
    loadBtn.addEventListener('click', function(e){
      e.preventDefault();
      var p = selectVal.value; if(!p){ return; }
      ajax('wpsfm_get_snippet', {path:p}).then(function(res){
        if (res.success && res.data) {
          $('#snippet_title').value = res.data.title || '';
          var code = res.data.code || '';
          if (window.wpsfmEditor && window.wpsfmEditor.codemirror) {
            window.wpsfmEditor.codemirror.setValue(code);
            window.wpsfmEditor.codemirror.refresh();
          } else {
            $('#snippet_code').value = code;
          }
          $('#snippet_id').value = res.data.id || '';
        } else { alert(res.data || 'Failed to load snippet'); }
      }).catch(function(err){ console.error(err); alert('Request failed'); });
    });
  }

  var addBtn = $('#wpsfm-add');
  if (addBtn) {
    addBtn.addEventListener('click', function(e){
      e.preventDefault();
      var title = $('#snippet_title').value || '';
      var code = (window.wpsfmEditor && window.wpsfmEditor.codemirror) ? window.wpsfmEditor.codemirror.getValue() : ($('#snippet_code').value || '');
      var id = $('#snippet_id').value || '';
      if (!code.trim()) { alert('Code cannot be empty.'); return; }
      ajax('wpsfm_add_snippet', {title:title, code:code, id:id}).then(function(res){
        if (res.success) {
          if (res.data && res.data.id) { $('#snippet_id').value = res.data.id; }
          if (res.data && res.data.snippets_html) {
            var wrap = document.getElementById('wpsfm-snippets-wrap');
            if (wrap) { wrap.innerHTML = res.data.snippets_html; bindDelete(); }
          }
          showBadge(addOk);
        } else { alert(res.data || 'Save failed'); }
      }).catch(function(err){ console.error(err); alert('Request failed'); });
    });
  }

  function bindDelete(){
    document.querySelectorAll('.wpsfm-del').forEach(function(a){
      a.addEventListener('click', function(ev){
        ev.preventDefault(); var id=a.getAttribute('data-id'); if(!id){return;}
        if(!confirm('Delete this snippet?')){return;}
        ajax('wpsfm_delete_snippet', {id:id}).then(function(res){
          if (res.success) {
            if (res.data && res.data.snippets_html) {
              var w = document.getElementById('wpsfm-snippets-wrap');
              if (w) { w.innerHTML = res.data.snippets_html; bindDelete(); }
            }
          } else { alert(res.data || 'Delete failed'); }
        }).catch(function(err){ console.error(err); alert('Request failed'); });
      });
    });
  }
  bindDelete();

  if (refreshBtn) {
    refreshBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      refreshBtn.disabled = true; if (refreshSpin) refreshSpin.classList.add('is-active');
      ajax('wpsfm_refresh_docs').then(function(res){
        if (res.success) {
          data = res.data.items || [];
          total = data.length;
          // reset picker and show full list after import
          selectVal.value = '';
          picker.value = '';
          renderSuggestions(data);
          if (sugg) { sugg.classList.add('show'); }
          showBadge(refreshOk);
        } else { alert(res.data || 'Import failed'); }
      }).catch(function(err){ console.error(err); alert('Request failed'); })
      .finally(function(){ refreshBtn.disabled = false; if (refreshSpin) refreshSpin.classList.remove('is-active'); });
    });
  }
})();
</script>
<?php
        $script = ob_get_clean();
        echo $script;

        // Initialize WordPress Code Editor (CodeMirror) for PHP offline
        $code_settings = wp_enqueue_code_editor( [ 'type' => 'text/x-php' ] );
        if ( $code_settings && is_array( $code_settings ) ) {
            wp_enqueue_script( 'code-editor' );
            wp_enqueue_style( 'code-editor' );
            echo '<script>(function(){var settings=' . wp_json_encode( $code_settings ) . ';window.addEventListener("load",function(){if(window.wp&&wp.codeEditor){window.wpsfmEditor = wp.codeEditor.initialize("snippet_code",settings);}});})();</script>';
        }

        echo '</div>';
    }

    // ========== AJAX handlers ==========
    private function render_snippets_table($snippets) {
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

    public function ajax_refresh_docs() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        check_ajax_referer(self::NONCE_ACTION);
        $msg = $err = null;
        $ok = $this->import_docs($msg, $err);
        if (!$ok) wp_send_json_error($err ?: 'Import failed');
        $parsed = get_option(self::OPTION_PARSED, []);
        $items = [];
        foreach ($parsed as $it) { $items[] = ['value'=>$it['path'], 'text'=>$this->display_label($it['path'])]; }
        wp_send_json_success(['count'=>count($items), 'items'=>$items]);
    }

    public function ajax_get_snippet() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        check_ajax_referer(self::NONCE_ACTION);
        $path = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
        $parsed = get_option(self::OPTION_PARSED, []);
        foreach ($parsed as $it) {
            if ($it['path'] === $path) {
                $it['id'] = $this->id_from_path($it['path']);
                wp_send_json_success($it);
            }
        }
        wp_send_json_error('Not found');
    }

    public function ajax_add_snippet() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        check_ajax_referer(self::NONCE_ACTION);
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $code = isset($_POST['code']) ? (string) wp_unslash($_POST['code']) : '';
        $id = isset($_POST['id']) ? $this->normalize_id(wp_unslash($_POST['id'])) : '';
        if (empty($title) && !empty($id)) $title = 'Snippet ' . $id;
        if (trim($code) === '') wp_send_json_error('Code cannot be empty');
        if ($id === '') $id = $this->normalize_id($title . '-' . microtime(true));
        $snippets_before = $this->snippets->get_all();
        $snippets = $this->snippets->add_or_update($id, $title, $code);
        $err = null; if (!$this->snippets->rebuild_mu_file($err)) wp_send_json_error($err ?: 'Failed to write mu-plugin');
        $html = $this->render_snippets_table($snippets);
        wp_send_json_success(['id'=>$id,'snippets_html'=>$html]);
    }

    public function ajax_delete_snippet() {
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        check_ajax_referer(self::NONCE_ACTION);
        $id = isset($_POST['id']) ? sanitize_title(wp_unslash($_POST['id'])) : '';
        if ($id === '') wp_send_json_error('Missing id');
        $snippets = $this->snippets->delete($id);
        $err = null; if (!$this->snippets->rebuild_mu_file($err)) wp_send_json_error($err ?: 'Failed to write mu-plugin');
        $html = $this->render_snippets_table($snippets);
        wp_send_json_success(['snippets_html'=>$html]);
    }
}

new WPSFM_Plugin();
