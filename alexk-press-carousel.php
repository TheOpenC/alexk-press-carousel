<?php
/**
 * Plugin Name: Alex K - Press Carousel v1
 * Description: Press article carousel. Bulk add / remove from grid and list view. Text-optimised responsive image conversion. Forward-only shuffle with press link. 1.0.0
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * ------------------------------------------------------------
 * Progress HUD system (truth store + runner + ajax endpoints)
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/includes/hud-helpers.php';
require_once __DIR__ . '/includes/hud-store.php';
require_once __DIR__ . '/includes/hud-runner.php';
require_once __DIR__ . '/includes/hud-ajax.php';


/* =========================================================
 * CONFIG
 * ======================================================= */

function alexk_press_meta_key(): string        { return 'alexk_include_in_press'; }
function alexk_press_url_meta_key(): string    { return 'alexk_press_url'; }
function alexk_press_group_meta_key(): string  { return 'alexk_press_group'; }
function alexk_press_order_meta_key(): string  { return 'alexk_press_group_order'; }
function alexk_press_widths(): array           { return [320, 480, 768, 950, 1024, 1400]; }

/**
 * Keep WP's own thumbnails minimal.
 */
add_filter('intermediate_image_sizes_advanced', function($sizes) {
  $keep = ['thumbnail', 'medium'];
  return array_intersect_key($sizes, array_flip($keep));
});

/**
 * Prevent WP from creating the "-scaled" big-image variant.
 */
add_filter('big_image_size_threshold', '__return_false');


/* =========================================================
 * PATH HELPERS
 * ======================================================= */

function alexk_press_cancel_marker_path(int $attachment_id, string $file_path = ''): ?string {
  if ($file_path === '') $file_path = get_attached_file($attachment_id);
  if (!$file_path) return null;

  $dir  = wp_normalize_path(dirname($file_path));
  $base = basename($file_path);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  return $dir . '/.' . $stem . '_' . $attachment_id . '.alexk_press_cancel';
}

function alexk_press_output_dir_for_attachment(int $attachment_id, string $file_path = ''): ?string {
  if ($file_path === '') $file_path = get_attached_file($attachment_id);
  if (!$file_path) return null;

  $dir  = wp_normalize_path(dirname($file_path));
  $base = basename($file_path);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  return $dir . '/' . $stem . '_press_' . $attachment_id;
}

function alexk_press_rmdir_recursive(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($ri as $file) {
    $path = $file->getPathname();
    if ($file->isDir()) @rmdir($path);
    else @unlink($path);
  }
  @rmdir($dir);
}

function alexk_press_path_to_upload_url(string $abs_path): string {
  $uploads = wp_get_upload_dir();
  $basedir = wp_normalize_path($uploads['basedir'] ?? '');
  $baseurl = $uploads['baseurl'] ?? '';
  $abs_path = wp_normalize_path($abs_path);

  if ($basedir && strpos($abs_path, $basedir) === 0) {
    $rel = ltrim(substr($abs_path, strlen($basedir)), '/');
    return trailingslashit($baseurl) . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
  }
  return '';
}


/* =========================================================
 * ADMIN UI: Attachment fields (checkbox + press URL)
 * ======================================================= */

// AJAX: return all existing group slugs with their shared metadata
add_action('wp_ajax_alexk_press_get_groups', function() {
  if (!current_user_can('upload_files')) wp_send_json_error([], 403);
  global $wpdb;

  $group_key = alexk_press_group_meta_key();
  $url_key   = alexk_press_url_meta_key();

  // Get all unique group slugs
  $slugs = $wpdb->get_col($wpdb->prepare(
    "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
     WHERE meta_key = %s AND meta_value != '' ORDER BY meta_value ASC",
    $group_key
  ));

  // For each group, find the first item with shared metadata to inherit
  $groups = [];
  foreach (($slugs ?: []) as $slug) {
    $post_ids = $wpdb->get_col($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
      $group_key, $slug
    ));
    $pid = $post_ids[0] ?? 0;
    $groups[$slug] = [
      'press_url'   => $pid ? (string)get_post_meta($pid, $url_key, true) : '',
      'alt'         => $pid ? (string)get_post_meta($pid, '_wp_attachment_image_alt', true) : '',
      'description' => $pid ? (string)get_post($pid)?->post_content : '',
      'caption'     => $pid ? (string)get_post($pid)?->post_excerpt : '',
    ];
  }

  wp_send_json_success($groups);
});

add_filter('attachment_fields_to_edit', function($form_fields, $post) {
  $meta_key   = alexk_press_meta_key();
  $url_key    = alexk_press_url_meta_key();
  $group_key  = alexk_press_group_meta_key();
  $order_key  = alexk_press_order_meta_key();

  $in_press  = get_post_meta($post->ID, $meta_key, true) === '1';
  $checked   = $in_press ? 'checked' : '';
  $url_val   = esc_attr(get_post_meta($post->ID, $url_key, true));
  $group_val = esc_attr(get_post_meta($post->ID, $group_key, true));
  $order_val = esc_attr(get_post_meta($post->ID, $order_key, true));

  $has_group     = $group_val !== '';
  $fields_active = $in_press ? '' : 'disabled';
  $group_active  = ($in_press && $has_group) ? '' : 'disabled';

  $form_fields[$meta_key] = [
    'label' => 'Include in press carousel',
    'input' => 'html',
    'html'  => '
      <label class="alexk-press-rightside-label">
        <input type="checkbox" class="alexk-press-main-checkbox"
               name="attachments[' . $post->ID . '][' . $meta_key . ']"
               data-attachment-id="' . $post->ID . '"
               data-filename="' . esc_attr(basename(get_attached_file($post->ID) ?: '')) . '"
               value="1" ' . $checked . ' />
        Include in press carousel
      </label>
      <div class="alexk-press-checkbox-details">
        When checked, this item is included in the press carousel and text-optimised responsive images are generated.
      </div>',
  ];

  $form_fields[$url_key] = [
    'label' => 'Press article URL',
    'input' => 'html',
    'html'  => '
      <input type="url"
             class="alexk-press-conditional-field"
             name="attachments[' . $post->ID . '][' . $url_key . ']"
             value="' . $url_val . '"
             placeholder="https://example.com/article"
             ' . $fields_active . '
             style="width:100%; color: ' . ($url_val ? '#000' : '#aaa') . ';" />
      <div class="alexk-press-checkbox-details">
        Link shown below the press image. Opens in a new tab.
      </div>',
  ];

  $form_fields[$group_key] = [
    'label' => 'Press group slug',
    'input' => 'html',
    'html'  => '
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" class="alexk-press-group-checkbox"
               id="alexk-group-enable-' . $post->ID . '"
               data-attachment-id="' . $post->ID . '"
               ' . ($has_group && $in_press ? 'checked' : '') . '
               ' . ($in_press ? '' : 'disabled') . ' />
        <label for="alexk-group-enable-' . $post->ID . '" style="margin:0;font-weight:normal;">
          This is part of a document group
        </label>
      </div>
      <input type="text"
             class="alexk-press-group-slug-field alexk-press-conditional-field"
             data-attachment-id="' . $post->ID . '"
             value="' . $group_val . '"
             placeholder="e.g. palliative-turn-2022"
             list="alexk-press-groups-datalist-' . $post->ID . '"
             ' . $group_active . '
             style="width:100%;margin-top:6px;color:' . ($group_val ? '#000' : '#aaa') . ';" />
      <datalist id="alexk-press-groups-datalist-' . $post->ID . '"></datalist>
      <div class="alexk-press-checkbox-details">
        Group slug links pages of the same document. Selecting an existing slug will inherit its URL, alt text, caption and description.
      </div>',
  ];

  $form_fields[$order_key] = [
    'label' => 'Press group order',
    'input' => 'html',
    'html'  => '
      <input type="number"
             class="alexk-press-conditional-field alexk-press-order-field"
             data-attachment-id="' . $post->ID . '"
             value="' . $order_val . '"
             placeholder="1"
             min="1"
             ' . $group_active . '
             style="width:80px;color:' . ($order_val ? '#000' : '#aaa') . ';" />
      <div class="alexk-press-checkbox-details">
        Page order within the group (1 = first).
      </div>',
  ];

  return $form_fields;
}, 10, 2);

/**
 * Save checkbox + URL.
 */
add_filter('attachment_fields_to_save', function($post, $attachment) {
  $meta_key  = alexk_press_meta_key();
  $url_key   = alexk_press_url_meta_key();
  $group_key = alexk_press_group_meta_key();
  $order_key = alexk_press_order_meta_key();

  // -- Checkbox --
  $new = (isset($attachment[$meta_key]) && $attachment[$meta_key] === '1') ? '1' : '0';
  $old = get_post_meta($post['ID'], $meta_key, true);
  $old = ($old === '1') ? '1' : '0';

  update_post_meta($post['ID'], $meta_key, $new);

  $id          = (int)$post['ID'];
  $file        = get_attached_file($id);
  $cancel_path = $file ? alexk_press_cancel_marker_path($id, $file) : null;

  if ($new !== $old) {
    if ($new === '1') {
      if ($cancel_path && file_exists($cancel_path)) @unlink($cancel_path);
      alexk_generate_press_derivatives_for_attachment($id);
// localStorage updated client-side in JS
    } else {
      if ($cancel_path) @file_put_contents($cancel_path, (string)time());
      alexk_delete_press_derivatives_for_attachment($id);
// localStorage updated client-side in JS
    }
  }

  // -- Press URL --
  if (isset($attachment[$url_key])) {
    $url = esc_url_raw(trim($attachment[$url_key]));
    update_post_meta($post['ID'], $url_key, $url);
  }

  // -- Press group slug (fallback — AJAX handles this in real-time) --
  if (isset($attachment[$group_key])) {
    $slug = sanitize_title(trim($attachment[$group_key]));
    if ($slug === '') {
      delete_post_meta($post['ID'], $group_key);
      delete_post_meta($post['ID'], $order_key);
    } else {
      update_post_meta($post['ID'], $group_key, $slug);
    }
  }

  // -- Press group order --
  if (isset($attachment[$order_key])) {
    $order = max(1, (int)$attachment[$order_key]);
    update_post_meta($post['ID'], $order_key, $order);
  }

  return $post;
}, 10, 2);


/* =========================================================
 * ADMIN (List View): Bulk actions
 * ======================================================= */

add_filter('bulk_actions-upload', function (array $actions): array {
  $actions['alexk_add_to_press']    = 'Add to press carousel';
  $actions['alexk_remove_from_press'] = 'Remove from press carousel';
  return $actions;
});

add_filter('handle_bulk_actions-upload', function (string $redirect_url, string $action, array $post_ids): string {
  if ($action !== 'alexk_add_to_press' && $action !== 'alexk_remove_from_press') {
    return $redirect_url;
  }
  if (!current_user_can('upload_files')) return $redirect_url;
  if (function_exists('set_time_limit')) @set_time_limit(60);

  $key     = alexk_press_meta_key();
  $updated = 0;
  $queue   = [];
  $last_filename = '';

  foreach ($post_ids as $id) {
    $id = (int) $id;
    if ($id <= 0) continue;
    if (get_post_type($id) !== 'attachment') continue;

    if ($action === 'alexk_add_to_press') {
      update_post_meta($id, $key, '1');
      $file = get_attached_file($id);
      if ($file) {
        $cancel = alexk_press_cancel_marker_path($id, $file);
        if ($cancel && file_exists($cancel)) @unlink($cancel);
        $last_filename = basename($file);
      }
    } else {
      update_post_meta($id, $key, '0');
      $file = get_attached_file($id);
      if ($file) {
        $cancel_path = alexk_press_cancel_marker_path($id, $file);
        if ($cancel_path) @file_put_contents($cancel_path, (string) time());
        $last_filename = basename($file);
      }
    }
    $queue[] = $id;
    $updated++;
  }

  alexk_press_bulk_job_clear();
  alexk_press_bulk_job_set([
    'pending'  => count($queue),
    'done'     => 0,
    'total'    => count($queue),
    'queue'    => array_values($queue),
    'started'  => time(),
    'mode'     => ($action === 'alexk_remove_from_press') ? 'remove' : 'add',
    'current_attachment_id' => 0,
    'current_filename'      => '',
    'file_pending'          => 0,
    'file_done'             => 0,
  ]);

  $redirect_url = add_query_arg([
    'alexk_press_list_bulk' => $action,
    'alexk_press_updated'   => $updated,
    'alexk_press_last_fn'   => $last_filename,
    'alexk_press_last_mode' => ($action === 'alexk_remove_from_press') ? 'remove' : 'add',
  ], $redirect_url);

  return $redirect_url;
}, 10, 3);

add_action('admin_notices', function () {
  global $pagenow;
  if ($pagenow !== 'upload.php') return;
  if (empty($_GET['alexk_press_list_bulk']) || !isset($_GET['alexk_press_updated'])) return;

  $action  = sanitize_text_field((string) $_GET['alexk_press_list_bulk']);
  $updated = (int) $_GET['alexk_press_updated'];
  $lastFn  = isset($_GET['alexk_press_last_fn'])   ? sanitize_text_field((string) $_GET['alexk_press_last_fn'])   : '';
  $lastMode = isset($_GET['alexk_press_last_mode']) ? sanitize_text_field((string) $_GET['alexk_press_last_mode']) : '';

  if ($lastFn) {
    $payload = wp_json_encode(['filename' => $lastFn, 'mode' => $lastMode, 'ts' => time()]);
    echo '<script>(function(){try{localStorage.setItem("alexk_press_last_completed",' . $payload . ');}catch(e){}})();</script>';
  }

  if ($action === 'alexk_add_to_press') {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html("Added {$updated} item(s) to the press carousel.") . '</p></div>';
  } elseif ($action === 'alexk_remove_from_press') {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html("Removed {$updated} item(s) from the press carousel.") . '</p></div>';
  }
});


/* =========================================================
 * ADMIN: List view column
 * ======================================================= */

add_filter('manage_media_columns', function (array $columns): array {
  $out = [];
  foreach ($columns as $key => $label) {
    $out[$key] = $label;
    if ($key === 'title') {
      $out['alexk_press'] = 'Press';
    }
  }
  if (!isset($out['alexk_press'])) $out['alexk_press'] = 'Press';
  return $out;
});

add_action('manage_media_custom_column', function (string $column_name, int $post_id): void {
  if ($column_name !== 'alexk_press') return;
  $val = get_post_meta($post_id, alexk_press_meta_key(), true);
  if ((string)$val === '1') {
    echo '<span class="alexk-press-dot" aria-label="In press carousel" title="In press carousel"></span>';
  }
}, 10, 2);


/* =========================================================
 * ADMIN: Grid view badge
 * ======================================================= */

add_filter('wp_prepare_attachment_for_js', function($response, $attachment, $meta) {
  $id = (int)($attachment->ID ?? 0);
  $response['alexk_in_press']    = $id ? (get_post_meta($id, alexk_press_meta_key(), true) === '1') : false;
  $response['alexk_press_group'] = $id ? (string)get_post_meta($id, alexk_press_group_meta_key(), true) : '';
  return $response;
}, 10, 3);


/* =========================================================
 * ADMIN: Enqueue scripts + styles
 * ======================================================= */

add_action('admin_enqueue_scripts', function ($hook) {
  // HUD (all admin pages)
  $hud_abs = plugin_dir_path(__FILE__) . 'js/hud.js';
  if (file_exists($hud_abs)) {
    wp_enqueue_script('alexk-press-hud', plugins_url('js/hud.js', __FILE__), [], filemtime($hud_abs), true);
  }

  if ($hook !== 'upload.php') return;

  // Suppress Elementor admin JS noise on media library
  global $wp_scripts;
  if ($wp_scripts && !empty($wp_scripts->registered) && is_array($wp_scripts->registered)) {
    foreach ($wp_scripts->registered as $handle => $obj) {
      $src = is_object($obj) ? (string)($obj->src ?? '') : '';
      if ($src && strpos($src, 'elementor') !== false && strpos($src, '/assets/js/admin') !== false) {
        wp_dequeue_script($handle);
      }
    }
  }

  wp_enqueue_media();

  $css_abs = plugin_dir_path(__FILE__) . 'css/admin.css';
  wp_enqueue_style('alexk-press-admin', plugins_url('css/admin.css', __FILE__), [], file_exists($css_abs) ? filemtime($css_abs) : '1.0.0');

  $js_abs = plugin_dir_path(__FILE__) . 'js/admin-bulk.js';
  wp_enqueue_script('alexk-press-admin-bulk', plugins_url('js/admin-bulk.js', __FILE__), ['media-views'], file_exists($js_abs) ? filemtime($js_abs) : '1.0.0', true);

  // Count included items for the UI badge
  $q = new WP_Query([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'paged'          => 1,
    'fields'         => 'ids',
    'no_found_rows'  => false,
    'meta_key'       => alexk_press_meta_key(),
    'meta_value'     => '1',
  ]);
  $included_count = (int)($q->found_posts ?? 0);

  wp_add_inline_script('alexk-press-admin-bulk', 'window.ALEXK_PRESS_BULK = ' . wp_json_encode([
    'nonce'          => wp_create_nonce('alexk_press_bulk'),
    'groups_nonce'   => wp_create_nonce('alexk_press_groups'),
    'included_count' => $included_count,
  ]) . ';', 'before');

  // Legend bar above media grid
  add_action('admin_footer', function() {
    ?>
    <script>
    (function() {
      // ---- Legend bar ----
      function injectLegend() {
        if (document.getElementById('alexk-dot-legend')) return;
        const grid = document.querySelector('.attachments-browser, .media-frame-content');
        if (!grid) return;
        const legend = document.createElement('div');
        legend.id = 'alexk-dot-legend';
        legend.innerHTML =
          '<span class="alexk-legend-dot alexk-legend-green"></span> Artwork carousel &nbsp;&nbsp;' +
          '<span class="alexk-legend-dot alexk-legend-magenta"></span> Press carousel &nbsp;&nbsp;' +
          '<span class="alexk-legend-dot alexk-legend-orange"></span> Press group (document)';
        grid.insertBefore(legend, grid.firstChild);
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectLegend);
      } else {
        injectLegend();
        setTimeout(injectLegend, 800);
      }

      // ---- Attachment details field interactivity ----
      // Runs whenever the media modal renders new fields
      let groupsCache = null;

      function fetchGroups() {
        if (groupsCache) return Promise.resolve(groupsCache);
        const nonce = window.ALEXK_PRESS_BULK?.groups_nonce || '';
        return fetch(`${window.ajaxurl}?action=alexk_press_get_groups&nonce=${nonce}`, {
          credentials: 'same-origin'
        }).then(r => r.json()).then(res => {
          if (res.success) groupsCache = res.data || {};
          return groupsCache || {};
        }).catch(() => ({}));
      }

      function ajaxGroupAction(action, attachmentId, extra) {
        const nonce = window.ALEXK_PRESS_BULK?.nonce || '';
        const body = new FormData();
        body.set('action', action);
        body.set('nonce', nonce);
        body.set('attachment_id', String(attachmentId));
        if (extra) Object.entries(extra).forEach(([k, v]) => body.set(k, String(v)));
        return fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body })
          .then(r => r.json()).catch(() => ({ success: false }));
      }

      function clearGroupFromTile(id) {
        if (!id) return;
        try {
          const att = window.wp?.media?.attachment?.(id);
          if (att && typeof att.set === 'function') {
            att.set('alexk_press_group', '');
            if (typeof att.trigger === 'function') att.trigger('change');
          }
        } catch(e) {}
        try {
          const tile = document.querySelector(`.attachments .attachment[data-id="${id}"]`);
          if (tile) {
            tile.classList.remove('alexk-in-press-group');
            tile.querySelector('.alexk-press-group-dot')?.remove();
          }
        } catch(e) {}
      }

      function applyGroupToTile(id, slug) {
        if (!id || !slug) return;
        try {
          const att = window.wp?.media?.attachment?.(id);
          if (att && typeof att.set === 'function') {
            att.set('alexk_press_group', slug);
            if (typeof att.trigger === 'function') att.trigger('change');
          }
        } catch(e) {}
        try {
          const tile = document.querySelector(`.attachments .attachment[data-id="${id}"]`);
          if (tile) {
            tile.classList.add('alexk-in-press-group');
            if (!tile.querySelector('.alexk-press-group-dot')) {
              const dot = document.createElement('span');
              dot.className = 'alexk-press-group-dot';
              dot.setAttribute('aria-hidden', 'true');
              tile.appendChild(dot);
            }
          }
        } catch(e) {}
      }

      function initPressFields(modal) {
        const mainCb    = modal.querySelector('.alexk-press-main-checkbox');
        const groupCb   = modal.querySelector('.alexk-press-group-checkbox');
        const groupSlug = modal.querySelector('.alexk-press-group-slug-field');
        const orderFld  = modal.querySelector('.alexk-press-order-field');
        const urlFld    = modal.querySelector('input[name*="alexk_press_url"]');
        const datalist  = modal.querySelector('datalist[id*="alexk-press-groups-datalist"]');

        if (!mainCb) return;

        const attachmentId = parseInt(groupCb?.dataset?.attachmentId || groupSlug?.dataset?.attachmentId || 0, 10);

        function setFieldState() {
          const inPress = mainCb.checked;
          const inGroup = groupCb?.checked;

          if (urlFld) {
            urlFld.disabled = !inPress;
            urlFld.style.color = urlFld.value ? '#000' : '#aaa';
          }
          if (groupCb) {
            groupCb.disabled = !inPress;
            if (!inPress) groupCb.checked = false;
          }
          if (groupSlug) {
            groupSlug.disabled = !(inPress && inGroup);
            groupSlug.style.color = groupSlug.value ? '#000' : '#aaa';
          }
          if (orderFld) {
            orderFld.disabled = !(inPress && inGroup);
            orderFld.style.color = orderFld.value ? '#000' : '#aaa';
          }
        }

        // Populate datalist
        if (datalist && !datalist.dataset.loaded) {
          datalist.dataset.loaded = '1';
          fetchGroups().then(groups => {
            Object.keys(groups).forEach(slug => {
              const opt = document.createElement('option');
              opt.value = slug;
              datalist.appendChild(opt);
            });
          });
        }

        // Inherit metadata when group slug is selected
        if (groupSlug) {
          groupSlug.addEventListener('change', function() {
            const slug = this.value.trim();
            if (!slug) return;
            fetchGroups().then(groups => {
              const data = groups[slug];
              if (!data) return;

              // Press URL
              if (urlFld && data.press_url) urlFld.value = data.press_url;

              // Alt text
              const altFld = modal.closest('.media-modal, .attachment-info')
                ?.querySelector('input[id*="attachment-details-alt-text"], textarea[id*="alt-text"], input[name*="_wp_attachment_image_alt"]');
              if (altFld && data.alt) altFld.value = data.alt;

              // Caption
              const captionFld = modal.closest('.media-modal, .attachment-info')
                ?.querySelector('textarea[id*="attachment-details-caption"], input[name*="post_excerpt"], textarea[name*="post_excerpt"]');
              if (captionFld && data.caption) captionFld.value = data.caption;

              // Description
              const descFld = modal.closest('.media-modal, .attachment-info')
                ?.querySelector('textarea[id*="attachment-details-description"], textarea[name*="post_content"]');
              if (descFld && data.description) descFld.value = data.description;

              // Update placeholder color
              groupSlug.style.color = '#000';
              if (urlFld) urlFld.style.color = urlFld.value ? '#000' : '#aaa';
            });
          });

          // Update color as user types
          groupSlug.addEventListener('input', function() {
            this.style.color = this.value ? '#000' : '#aaa';
          });
        }

        if (urlFld) {
          urlFld.addEventListener('input', function() {
            this.style.color = this.value ? '#000' : '#aaa';
          });
        }

        // Group checkbox: instant toggle — AJAX fires immediately, no Save needed
        if (groupCb) {
          groupCb.addEventListener('change', function() {
            setFieldState();
            if (!this.checked && attachmentId) {
              // Uncheck = immediately remove from group in DB
              ajaxGroupAction('alexk_press_clear_group', attachmentId).then(() => {
                // Bust cache so datalist reflects removal
                groupsCache = null;
                if (datalist) delete datalist.dataset.loaded;
              });
              clearGroupFromTile(attachmentId);
              if (groupSlug) groupSlug.value = '';
              if (orderFld) orderFld.value = '';
            }
            // Check = datalist will repopulate fresh on next focus
          });
        }

        // Slug field: save to DB on blur or datalist selection
        if (groupSlug) {
          function saveGroupSlug() {
            const slug = groupSlug.value.trim();
            if (!slug || !attachmentId) return;
            const order = parseInt(orderFld?.value || '1', 10) || 1;
            ajaxGroupAction('alexk_press_set_group', attachmentId, { group_slug: slug, group_order: order })
              .then(res => {
                if (res.success) {
                  applyGroupToTile(attachmentId, slug);
                  // Bust cache so datalist picks up the new group next time
                  groupsCache = null;
                  if (datalist) delete datalist.dataset.loaded;
                }
              });
          }
          groupSlug.addEventListener('blur', saveGroupSlug);
          groupSlug.addEventListener('change', saveGroupSlug);
        }

        // Order field: save on blur
        if (orderFld) {
          orderFld.addEventListener('blur', function() {
            const slug = groupSlug?.value.trim();
            if (!slug || !attachmentId) return;
            const order = parseInt(this.value || '1', 10) || 1;
            ajaxGroupAction('alexk_press_set_group', attachmentId, { group_slug: slug, group_order: order });
          });
        }

        mainCb.addEventListener('change', function() {
          setFieldState();
          try {
            const filename = mainCb.dataset.filename || '';
            const mode = mainCb.checked ? 'add' : 'remove';
            if (filename) {
              localStorage.setItem('alexk_press_last_completed',
                JSON.stringify({ filename, mode, ts: Date.now() })
              );
              const label = mode === 'add' ? 'Last added to press carousel:' : 'Last removed from press carousel:';
              const noticeLabel = document.querySelector('.alexk-press-lastdone-label');
              const noticeText  = document.querySelector('.alexk-press-lastdone-text');
              const noticeSep   = document.querySelector('.alexk-press-sep');
              if (noticeLabel) { noticeLabel.textContent = label + ' '; noticeLabel.style.display = ''; }
              if (noticeText)  { noticeText.textContent  = filename;    noticeText.style.display  = ''; }
              if (noticeSep)     noticeSep.style.display = '';
            }
          } catch(e) {}
        });
        setFieldState();
      }

      // Watch for media modal content rendering
      const observer = new MutationObserver(() => {
        document.querySelectorAll('.media-modal .attachment-details, .attachment-details').forEach(modal => {
          const mainCbCheck = modal.querySelector('.alexk-press-main-checkbox');
          const currentId = mainCbCheck?.dataset?.attachmentId || '';
          if (modal.dataset.alexkPressInit !== currentId) {
            modal.dataset.alexkPressInit = currentId;
            initPressFields(modal);
          }
        });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
  });
});

// Suppress jQuery Migrate noise on upload.php
add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'upload.php') return;
  $scripts = wp_scripts();
  if (!$scripts || empty($scripts->registered['jquery'])) return;
  $jquery = $scripts->registered['jquery'];
  if (is_array($jquery->deps) && in_array('jquery-migrate', $jquery->deps, true)) {
    $jquery->deps = array_values(array_diff($jquery->deps, ['jquery-migrate']));
  }
}, 1);


/* =========================================================
 * CLEANUP
 * ======================================================= */

add_action('delete_attachment', function($attachment_id) {
  alexk_delete_press_derivatives_for_attachment((int)$attachment_id);
});


/* =========================================================
 * DERIVATIVE GENERATION
 * Text-optimised: lossless WebP, high-quality JPEG, no chroma subsampling
 * ======================================================= */

function alexk_generate_press_derivatives_for_attachment(int $attachment_id): void {
  $file = get_attached_file($attachment_id);
  if (!$file || !file_exists($file)) return;

  $mime = get_post_mime_type($attachment_id);
  if (!$mime || strpos($mime, 'image/') !== 0) return;

  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  if (in_array($ext, ['svg', 'pdf'], true)) return;

  $out_dir     = alexk_press_output_dir_for_attachment($attachment_id, $file);
  $cancel_path = alexk_press_cancel_marker_path($attachment_id, $file);
  if (!$out_dir || !$cancel_path) return;
  if (file_exists($cancel_path)) return;

  if (!is_dir($out_dir)) wp_mkdir_p($out_dir);
  if (!is_dir($out_dir)) return;

  $base = basename($file);
  $stem = preg_replace('/\.[^.]+$/', '', $base);

  $info = @getimagesize($file);
  if (!$info || empty($info[0]) || empty($info[1])) return;

  $native_w   = (int)$info[0];
  $native_h   = (int)$info[1];
  $native_max = max($native_w, $native_h);
  if ($native_max <= 0) return;

  $widths   = alexk_press_widths();
  $max_list = max($widths);
  if ($native_max < $max_list) {
    $widths[] = $native_max;
    $widths   = array_values(array_unique($widths));
    sort($widths);
  }

  // Bulk UI: init per-file progress
  $job = alexk_press_bulk_job_get();
  if (!empty($job['pending']) && !empty($job['started'])) {
    alexk_press_bulk_job_patch([
      'current_attachment_id' => $attachment_id,
      'current_filename'      => basename($file),
      'file_pending'          => count($widths),
      'file_done'             => 0,
    ]);
  }

  foreach ($widths as $w) {
    if (file_exists($cancel_path)) return;
    if (!is_dir($out_dir)) return;

    $w = (int)$w;
    if ($w <= 0 || $w > $native_max) continue;

    $out_webp = $out_dir . '/' . $stem . '-w' . $w . '.webp';
    $out_jpg  = $out_dir . '/' . $stem . '-w' . $w . '.jpg';

    alexk_press_resize_and_write($file, $out_webp, $w, 'webp', $cancel_path, $out_dir);
    // Remove zero-byte files — Imagick may write empty files on failure
    if (file_exists($out_webp) && filesize($out_webp) === 0) @unlink($out_webp);
    alexk_press_resize_and_write($file, $out_jpg,  $w, 'jpg',  $cancel_path, $out_dir);
    if (file_exists($out_jpg)  && filesize($out_jpg)  === 0) @unlink($out_jpg);

    $job = alexk_press_bulk_job_get();
    if (!empty($job['pending']) && !empty($job['started'])) {
      alexk_press_bulk_job_patch(['file_done' => (int)($job['file_done'] ?? 0) + 1]);
    }
  }

  $job = alexk_press_bulk_job_get();
  if (!empty($job['pending']) && !empty($job['started'])) {
    alexk_press_bulk_job_patch([
      'current_attachment_id' => 0,
      'current_filename'      => '',
      'file_pending'          => 0,
      'file_done'             => 0,
    ]);
  }
}

function alexk_delete_press_derivatives_for_attachment(int $attachment_id): void {
  $file = get_attached_file($attachment_id);
  if (!$file) return;
  $out_dir = alexk_press_output_dir_for_attachment($attachment_id, $file);
  if ($out_dir) alexk_press_rmdir_recursive($out_dir);
}

/**
 * Text-optimised resize/write:
 * - WebP: lossless (quality 100, lossless flag)
 * - JPEG: quality 95, 4:4:4 chroma (no subsampling) for crisp text
 */
function alexk_press_resize_and_write(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  if (extension_loaded('imagick')) {
    $ok = alexk_press_imagick_resize_and_write($src, $dst, $max_edge, $format, $cancel_path, $out_dir);
    if ($ok) return true;
  }
  return alexk_press_wp_editor_resize_and_write($src, $dst, $max_edge, $format, $cancel_path, $out_dir);
}

function alexk_press_imagick_resize_and_write(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  try {
    if (file_exists($cancel_path)) return false;
    if (!is_dir($out_dir)) return false;

    $im = new Imagick();
    $im->readImage($src);
    if (method_exists($im, 'autoOrient')) $im->autoOrient();
    if (defined('Imagick::COLORSPACE_SRGB')) @$im->setImageColorspace(Imagick::COLORSPACE_SRGB);

    $w = $im->getImageWidth();
    $h = $im->getImageHeight();
    if ($w <= 0 || $h <= 0) return false;

    $long = max($w, $h);
    if ($max_edge > $long) $max_edge = $long;

    $scale = $max_edge / $long;
    $new_w = max(1, (int)round($w * $scale));
    $new_h = max(1, (int)round($h * $scale));

    // Lanczos for sharpest text downscale
    $im->resizeImage($new_w, $new_h, Imagick::FILTER_LANCZOS, 1, true);

    // Unsharp mask to recover text crispness lost during downscale
    // Parameters: radius, sigma, amount, threshold
    $im->unsharpMaskImage(0, 0.5, 0.8, 0.05);

    @$im->stripImage();

    if (file_exists($cancel_path)) return false;
    if (!is_dir($out_dir)) return false;

    if ($format === 'webp') {
      $im->setImageFormat('webp');
      // Lossless WebP — maximum quality for text
      $im->setImageCompressionQuality(100);
      @$im->setOption('webp:lossless', 'true');
      @$im->setOption('webp:method', '6');      // slowest = best compression
      @$im->setOption('webp:exact', 'true');    // preserve exact RGB values
    } else {
      $im->setImageFormat('jpeg');
      // Quality 95 + 4:4:4 chroma subsampling = sharpest JPEG text
      $im->setImageCompressionQuality(95);
      @$im->setOption('jpeg:sampling-factor', '4:4:4');
    }

    $ok = $im->writeImage($dst);
    $im->clear();
    $im->destroy();
    return (bool)$ok;
  } catch (Throwable $e) {
    return false;
  }
}

function alexk_press_wp_editor_resize_and_write(string $src, string $dst, int $max_edge, string $format, string $cancel_path, string $out_dir): bool {
  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  $editor = wp_get_image_editor($src);
  if (is_wp_error($editor)) return false;

  $size = $editor->get_size();
  if (empty($size['width']) || empty($size['height'])) return false;

  $long = max((int)$size['width'], (int)$size['height']);
  if ($long <= 0) return false;
  if ($max_edge > $long) $max_edge = $long;

  $res = $editor->resize($max_edge, $max_edge, false);
  if (is_wp_error($res)) return false;

  if (file_exists($cancel_path)) return false;
  if (!is_dir($out_dir)) return false;

  // GD fallback: quality 95 for JPEG, 100 for WebP
  $editor->set_quality(95);

  if ($format === 'webp') {
    $saved = $editor->save($dst, 'image/webp');
  } else {
    $saved = $editor->save($dst, 'image/jpeg');
  }

  return (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path']));
}


/* =========================================================
 * FRONTEND: enqueue assets + shortcode
 * ======================================================= */

add_action('wp_enqueue_scripts', function () {
  if (!is_singular()) return;
  $post = get_post();
  if (!$post) return;

  $content = (string)($post->post_content ?? '');
  if (!has_shortcode($content, 'alexk_press')) return;

  $base_url  = plugin_dir_url(__FILE__);
  $base_path = plugin_dir_path(__FILE__);

  foreach ([
    ['style',  'alexk-press-reset',    'css/reset.css',     []],
    ['style',  'alexk-press-frontend', 'css/frontend.css',  ['alexk-press-reset']],
    ['script', 'alexk-press-frontend', 'js/alexk-press-carousel.js', []],
  ] as [$type, $handle, $rel, $deps]) {
    $abs = $base_path . $rel;
    if (!file_exists($abs)) continue;
    if ($type === 'style') {
      wp_enqueue_style($handle, $base_url . $rel, $deps, filemtime($abs));
    } else {
      wp_enqueue_script($handle, $base_url . $rel, $deps, filemtime($abs), true);
    }
  }
});


add_shortcode('alexk_press', function($atts = []) {
  $q = new WP_Query([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => -1,
    'meta_key'       => alexk_press_meta_key(),
    'meta_value'     => '1',
  ]);

  if (!$q->have_posts()) return '';

  // Build flat list of all renderable items with group metadata
  $raw = [];
  while ($q->have_posts()) {
    $q->the_post();
    $id = get_the_ID();

    $file = get_attached_file($id);
    if (!$file) continue;

    $base = basename($file);
    $stem = preg_replace('/\.[^.]+$/', '', $base);

    $out_dir = alexk_press_output_dir_for_attachment($id, $file);
    if (!$out_dir || !is_dir($out_dir)) continue;

    $webp_srcset = [];
    $jpg_srcset  = [];

    foreach (alexk_press_widths() as $w) {
      $p_webp = $out_dir . '/' . $stem . '-w' . $w . '.webp';
      $p_jpg  = $out_dir . '/' . $stem . '-w' . $w . '.jpg';
      // Skip zero-byte files — Imagick may have failed silently and written an empty file
      if (file_exists($p_webp) && filesize($p_webp) > 0) $webp_srcset[] = alexk_press_path_to_upload_url($p_webp) . " {$w}w";
      if (file_exists($p_jpg)  && filesize($p_jpg)  > 0) $jpg_srcset[]  = alexk_press_path_to_upload_url($p_jpg)  . " {$w}w";
    }

    if (empty($webp_srcset) && empty($jpg_srcset)) continue;

    // Fallback src: cap at 1400px, skip zero-byte files
    // Also capture the local path so we can read dimensions cheaply (header only).
    $fallback      = '';
    $fallback_path = '';
    foreach ([1400, 1024, 950, 768, 480, 320] as $w) {
      $p = $out_dir . '/' . $stem . '-w' . $w . '.jpg';
      if (file_exists($p) && filesize($p) > 0) { $fallback = alexk_press_path_to_upload_url($p); $fallback_path = $p; break; }
    }
    if ($fallback === '') {
      foreach ([1400, 1024, 950, 768, 480, 320] as $w) {
        $p = $out_dir . '/' . $stem . '-w' . $w . '.webp';
        if (file_exists($p) && filesize($p) > 0) { $fallback = alexk_press_path_to_upload_url($p); $fallback_path = $p; break; }
      }
    }

    // Read pixel dimensions from the fallback file so JS can set data-orientation
    // and aspect-ratio immediately — before any load event fires — eliminating the
    // orientation snap and height-collapse flash on slide swaps.
    $img_w = 0;
    $img_h = 0;
    if ($fallback_path) {
      $dim = @getimagesize($fallback_path);
      if ($dim && !empty($dim[0]) && !empty($dim[1])) {
        $img_w = (int)$dim[0];
        $img_h = (int)$dim[1];
      }
    }

    $group_slug  = (string)get_post_meta($id, alexk_press_group_meta_key(), true);
    $group_order = (int)get_post_meta($id, alexk_press_order_meta_key(), true);
    $press_url   = esc_url(get_post_meta($id, alexk_press_url_meta_key(), true));

    $raw[] = [
      'webp_srcset' => implode(', ', $webp_srcset),
      'jpg_srcset'  => implode(', ', $jpg_srcset),
      'fallback'    => esc_url($fallback),
      'alt'         => esc_attr(get_post_meta($id, '_wp_attachment_image_alt', true)),
      'press_url'   => $press_url,
      'group_slug'  => $group_slug,
      'group_order' => $group_order ?: 999,
      'img_w'       => $img_w,
      'img_h'       => $img_h,
    ];
  }
  wp_reset_postdata();

  if (empty($raw)) return '';

  // Group items: items with a group slug are collected together as one slide;
  // items without a group slug are each their own slide.
  $slides = [];
  $groups = []; // slug => [images...]

  foreach ($raw as $item) {
    $slug = $item['group_slug'];
    if ($slug !== '') {
      $groups[$slug][] = $item;
    } else {
      // Solo item — becomes its own slide
      $slides[] = [
        'images'    => [$item],
        'press_url' => $item['press_url'],
        'grouped'   => false,
      ];
    }
  }

  // Sort each group by order, then add as single slide
  foreach ($groups as $slug => $images) {
    usort($images, fn($a, $b) => $a['group_order'] <=> $b['group_order']);
    // Use press_url from first item in the group
    $press_url = '';
    foreach ($images as $img) {
      if ($img['press_url']) { $press_url = $img['press_url']; break; }
    }
    $slides[] = [
      'images'    => $images,
      'press_url' => $press_url,
      'grouped'   => true,
    ];
  }

  if (empty($slides)) return '';

  // Shuffle slides (Fisher-Yates equivalent via PHP)
  shuffle($slides);

  // Encode all slides for JS
  $slides_json = wp_json_encode(array_map(function($slide) {
    return [
      'images'    => $slide['images'],
      'press_url' => $slide['press_url'],
      'grouped'   => $slide['grouped'],
    ];
  }, $slides));

  $first = $slides[0];

  ob_start(); ?>
<div class="alexk-press-page">
  <div class="alexk-press-carousel" data-slides="<?php echo esc_attr($slides_json); ?>">

    <div class="alexk-press-slide">
      <?php foreach ($first['images'] as $img):
        $iw          = (int)($img['img_w'] ?? 0);
        $ih          = (int)($img['img_h'] ?? 0);
        $orientation = ($iw && $ih) ? ($iw >= $ih ? 'landscape' : 'portrait') : '';
        $orient_attr = $orientation ? ' data-orientation="' . $orientation . '"' : '';
        $native_cap  = $iw ? ' style="max-width:min(' . $iw . 'px,100%)"' : '';
        $aspect_ratio= ($iw && $ih) ? 'aspect-ratio:' . $iw . '/' . $ih . ';' : '';
        $max_src_w   = min($iw ?: 1400, 1400);
        $sizes       = '(min-resolution: 2dppx) ' . $max_src_w . 'px, (max-width: 1400px) 100vw, 1400px';
      ?>
      <picture class="alexk-press-picture"<?php echo $orient_attr . $native_cap; ?>>
        <?php if (!empty($img['webp_srcset'])): ?>
          <source type="image/webp" srcset="<?php echo esc_attr($img['webp_srcset']); ?>" sizes="<?php echo esc_attr($sizes); ?>">
        <?php endif; ?>
        <?php if (!empty($img['jpg_srcset'])): ?>
          <source type="image/jpeg" srcset="<?php echo esc_attr($img['jpg_srcset']); ?>" sizes="<?php echo esc_attr($sizes); ?>">
        <?php endif; ?>
        <img class="alexk-press-image"
             src="<?php echo $img['fallback']; ?>"
             alt="<?php echo $img['alt']; ?>"
             sizes="<?php echo esc_attr($sizes); ?>"
             style="<?php echo esc_attr($aspect_ratio); ?>"
             fetchpriority="high"
             loading="eager"
             decoding="async">
      </picture>
      <?php endforeach; ?>
    </div>

    <?php
    $first_url = !empty($first['press_url']) ? $first['press_url'] : '#';
    $bar_visibility = !empty($first['press_url']) ? 'visible' : 'hidden';
    ?>
    <div class="alexk-press-link-bar" style="visibility: <?php echo $bar_visibility; ?>">
      <a class="alexk-press-link"
         href="<?php echo esc_url($first_url); ?>"
         target="_blank"
         rel="noopener noreferrer">Read article →</a>
    </div>

  </div>
</div>
<?php
  return ob_get_clean();
});


/* =========================================================
 * BULK JOB STATE (press-namespaced)
 * ======================================================= */

function alexk_press_bulk_job_key(): string { return 'alexk_press_bulk_job'; }
function alexk_press_bulk_job_set(array $job): void  { update_option(alexk_press_bulk_job_key(), $job, false); }
function alexk_press_bulk_job_get(): array           { $j = get_option(alexk_press_bulk_job_key(), []); return is_array($j) ? $j : []; }
function alexk_press_bulk_job_patch(array $patch): void {
  $job = alexk_press_bulk_job_get();
  if (!is_array($job)) $job = [];
  alexk_press_bulk_job_set(array_merge($job, $patch));
}
function alexk_press_bulk_job_clear(): void {
  alexk_press_bulk_job_set([
    'pending' => 0, 'done' => 0, 'total' => 0, 'queue' => [],
    'started' => 0, 'mode' => '',
    'current_attachment_id' => 0, 'current_filename' => '',
    'file_pending' => 0, 'file_done' => 0,
  ]);
}


/* =========================================================
 * BULK AJAX: add / remove / status
 * ======================================================= */


/* =========================================================
 * AJAX: set or clear group slug/order — instant, no Save needed
 * ======================================================= */
add_action('wp_ajax_alexk_press_set_group', function() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_press_bulk')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Permission denied'], 403);

  $id    = (int)($_POST['attachment_id'] ?? 0);
  $slug  = sanitize_title(trim((string)($_POST['group_slug'] ?? '')));
  $order = max(1, (int)($_POST['group_order'] ?? 1));

  if ($id <= 0 || get_post_type($id) !== 'attachment') {
    wp_send_json_error(['message' => 'Invalid attachment'], 400);
  }

  if ($slug !== '') {
    update_post_meta($id, alexk_press_group_meta_key(), $slug);
    update_post_meta($id, alexk_press_order_meta_key(), $order);
    wp_send_json_success(['id' => $id, 'slug' => $slug, 'order' => $order]);
  } else {
    delete_post_meta($id, alexk_press_group_meta_key());
    delete_post_meta($id, alexk_press_order_meta_key());
    wp_send_json_success(['id' => $id, 'slug' => '', 'order' => 0]);
  }
});

add_action('wp_ajax_alexk_press_clear_group', function() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_press_bulk')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Permission denied'], 403);

  $id = (int)($_POST['attachment_id'] ?? 0);
  if ($id <= 0 || get_post_type($id) !== 'attachment') {
    wp_send_json_error(['message' => 'Invalid attachment'], 400);
  }

  delete_post_meta($id, alexk_press_group_meta_key());
  delete_post_meta($id, alexk_press_order_meta_key());
  wp_send_json_success(['cleared' => $id]);
});

add_action('wp_ajax_alexk_press_bulk_add', function () {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_press_bulk')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Permission denied'], 403);
  if (empty($_POST['ids']))              wp_send_json_error(['message' => 'No IDs provided'], 400);

  $ids     = array_filter(array_map('intval', explode(',', (string) $_POST['ids'])));
  $queue   = [];
  $updated = 0;

  foreach ($ids as $id) {
    if ($id <= 0 || get_post_type($id) !== 'attachment') continue;
    update_post_meta($id, alexk_press_meta_key(), '1');
    $file = get_attached_file($id);
    if ($file) {
      $cancel = alexk_press_cancel_marker_path($id, $file);
      if ($cancel && file_exists($cancel)) @unlink($cancel);
    }
    $queue[] = $id;
    $updated++;
  }

  alexk_press_bulk_job_clear();
  alexk_press_bulk_job_set([
    'pending' => count($queue), 'done' => 0, 'total' => count($queue),
    'queue' => array_values($queue), 'started' => time(), 'mode' => 'add',
    'current_attachment_id' => 0, 'current_filename' => '',
    'file_pending' => 0, 'file_done' => 0,
  ]);

  wp_send_json_success(['updated' => $updated]);
});


add_action('wp_ajax_alexk_press_bulk_remove', function () {
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'alexk_press_bulk')) {
    wp_send_json_error(['message' => 'Invalid nonce'], 403);
  }
  if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Permission denied'], 403);
  if (empty($_POST['ids']))              wp_send_json_error(['message' => 'No IDs provided'], 400);

  $ids     = array_filter(array_map('intval', explode(',', (string) $_POST['ids'])));
  $queue   = [];
  $updated = 0;

  foreach ($ids as $id) {
    if ($id <= 0 || get_post_type($id) !== 'attachment') continue;
    update_post_meta($id, alexk_press_meta_key(), '0');
    $file = get_attached_file($id);
    if ($file) {
      $cancel = alexk_press_cancel_marker_path($id, $file);
      if ($cancel) @file_put_contents($cancel, (string)time());
    }
    $queue[] = $id;
    $updated++;
  }

  alexk_press_bulk_job_clear();
  alexk_press_bulk_job_set([
    'pending' => count($queue), 'done' => 0, 'total' => count($queue),
    'queue' => array_values($queue), 'started' => time(), 'mode' => 'remove',
    'current_attachment_id' => 0, 'current_filename' => '',
    'file_pending' => 0, 'file_done' => 0,
  ]);

  wp_send_json_success(['updated' => $updated]);
});


add_action('wp_ajax_alexk_press_bulk_job_status', function () {
  if (!current_user_can('upload_files')) wp_send_json_error(['message' => 'Permission denied'], 403);

  $job   = alexk_press_bulk_job_get();
  $mode  = (string)($job['mode'] ?? '');
  $queue = is_array($job['queue'] ?? null) ? $job['queue'] : [];
  $total = (int)($job['total'] ?? count($queue));
  $t0    = microtime(true);

  if (!empty($job['started']) && !empty($queue) && ($mode === 'add' || $mode === 'remove')) {

    if ((microtime(true) - $t0) <= 0.60) {
      $id = (int) array_shift($queue);
      if ($id > 0 && get_post_type($id) === 'attachment') {
        $file = get_attached_file($id);
        $job['current_attachment_id'] = $id;
        $job['current_filename']      = $file ? basename($file) : '';
        $job['file_pending']          = 0;
        $job['file_done']             = 0;
        alexk_press_bulk_job_set($job);

        if ($mode === 'add') {
          alexk_generate_press_derivatives_for_attachment($id);
        } else {
          alexk_delete_press_derivatives_for_attachment($id);
        }

        $job['last_completed_attachment_id'] = $id;
        $job['last_completed_filename']      = $job['current_filename'];
        $job['done']    = (int)($job['done'] ?? 0) + 1;
        $job['pending'] = max(0, $total - $job['done']);
      }
    }

    $job['queue'] = array_values($queue);

    if ((int)($job['pending'] ?? 0) <= 0) {
      $lastId = (int)($job['last_completed_attachment_id'] ?? 0);
      $lastFn = (string)($job['last_completed_filename'] ?? '');
      alexk_press_bulk_job_clear();
      $job = alexk_press_bulk_job_get();
      $job['last_completed_attachment_id'] = $lastId;
      $job['last_completed_filename']      = $lastFn;
      alexk_press_bulk_job_set($job);
    } else {
      alexk_press_bulk_job_set($job);
    }
  }

  wp_send_json_success([
    'pending'               => (int)($job['pending'] ?? 0),
    'done'                  => (int)($job['done'] ?? 0),
    'total'                 => (int)($job['total'] ?? 0),
    'mode'                  => (string)($job['mode'] ?? ''),
    'started'               => (int)($job['started'] ?? 0),
    'current_attachment_id' => (int)($job['current_attachment_id'] ?? 0),
    'current_filename'      => (string)($job['current_filename'] ?? ''),
    'file_pending'          => (int)($job['file_pending'] ?? 0),
    'file_done'             => (int)($job['file_done'] ?? 0),
    'last_completed_attachment_id' => (int)($job['last_completed_attachment_id'] ?? 0),
    'last_completed_filename'      => (string)($job['last_completed_filename'] ?? ''),
  ]);
});
