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
function alexk_press_widths(): array           { return [320, 480, 768, 1024, 1400]; }

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

add_filter('attachment_fields_to_edit', function($form_fields, $post) {
  $meta_key = alexk_press_meta_key();
  $url_key  = alexk_press_url_meta_key();

  $val     = get_post_meta($post->ID, $meta_key, true);
  $checked = ($val === '1') ? 'checked' : '';
  $url_val = esc_attr(get_post_meta($post->ID, $url_key, true));

  $form_fields[$meta_key] = [
    'label' => 'Include in press carousel',
    'input' => 'html',
    'html'  => '<label class="alexk-press-rightside-label">
                  <input type="checkbox" class="press-checkbox" name="attachments[' . $post->ID . '][' . $meta_key . ']" value="1" ' . $checked . ' />
                  Include in press carousel
                </label>
                <div class="alexk-press-checkbox-details">
                  When checked, this item is included in the press carousel and text-optimised responsive images are generated.
                </div>',
  ];

  $form_fields[$url_key] = [
    'label' => 'Press article URL',
    'input' => 'html',
    'html'  => '<input type="url"
                  name="attachments[' . $post->ID . '][' . $url_key . ']"
                  value="' . $url_val . '"
                  placeholder="https://example.com/article"
                  style="width:100%;" />
                <div class="alexk-press-checkbox-details">
                  Link shown below the press image. Opens in a new tab.
                </div>',
  ];

  return $form_fields;
}, 10, 2);

/**
 * Save checkbox + URL.
 */
add_filter('attachment_fields_to_save', function($post, $attachment) {
  $meta_key = alexk_press_meta_key();
  $url_key  = alexk_press_url_meta_key();

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
    } else {
      if ($cancel_path) @file_put_contents($cancel_path, (string)time());
      alexk_delete_press_derivatives_for_attachment($id);
    }
  }

  // -- Press URL --
  if (isset($attachment[$url_key])) {
    $url = esc_url_raw(trim($attachment[$url_key]));
    update_post_meta($post['ID'], $url_key, $url);
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
  $response['alexk_in_press'] = $id
    ? (get_post_meta($id, alexk_press_meta_key(), true) === '1')
    : false;
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
    'included_count' => $included_count,
  ]) . ';', 'before');
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
    alexk_press_resize_and_write($file, $out_jpg,  $w, 'jpg',  $cancel_path, $out_dir);

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
    @$im->stripImage();

    if (file_exists($cancel_path)) return false;
    if (!is_dir($out_dir)) return false;

    if ($format === 'webp') {
      $im->setImageFormat('webp');
      // Lossless WebP: best quality for text/screenshots
      $im->setImageCompressionQuality(100);
      @$im->setOption('webp:lossless', 'true');
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

  $items = [];
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
      if (file_exists($p_webp)) $webp_srcset[] = alexk_press_path_to_upload_url($p_webp) . " {$w}w";
      if (file_exists($p_jpg))  $jpg_srcset[]  = alexk_press_path_to_upload_url($p_jpg)  . " {$w}w";
    }

    if (empty($webp_srcset) && empty($jpg_srcset)) continue;

    // Fallback: largest generated JPG, then WebP
    $widths_desc = alexk_press_widths();
    rsort($widths_desc);
    $fallback = '';
    foreach ($widths_desc as $w) {
      $p = $out_dir . '/' . $stem . '-w' . $w . '.jpg';
      if (file_exists($p)) { $fallback = alexk_press_path_to_upload_url($p); break; }
    }
    if ($fallback === '') {
      foreach ($widths_desc as $w) {
        $p = $out_dir . '/' . $stem . '-w' . $w . '.webp';
        if (file_exists($p)) { $fallback = alexk_press_path_to_upload_url($p); break; }
      }
    }

    $press_url = esc_url(get_post_meta($id, alexk_press_url_meta_key(), true));

    $items[] = [
      'webp_srcset' => implode(', ', $webp_srcset),
      'jpg_srcset'  => implode(', ', $jpg_srcset),
      'fallback'    => esc_url($fallback),
      'alt'         => esc_attr(get_post_meta($id, '_wp_attachment_image_alt', true)),
      'press_url'   => $press_url,
    ];
  }
  wp_reset_postdata();

  if (empty($items)) return '';

  ob_start(); ?>
<div class="alexk-press-page">
  <div class="alexk-press-carousel" data-images="<?php echo esc_attr(wp_json_encode($items)); ?>">
    <picture class="alexk-press-picture">
      <?php if (!empty($items[0]['webp_srcset'])): ?>
        <source type="image/webp" srcset="<?php echo esc_attr($items[0]['webp_srcset']); ?>" sizes="(max-width: 1400px) 100vw, 1400px">
      <?php endif; ?>
      <?php if (!empty($items[0]['jpg_srcset'])): ?>
        <source type="image/jpeg" srcset="<?php echo esc_attr($items[0]['jpg_srcset']); ?>" sizes="(max-width: 1400px) 100vw, 1400px">
      <?php endif; ?>
      <img class="alexk-press-image"
           src="<?php echo $items[0]['fallback']; ?>"
           alt="<?php echo $items[0]['alt']; ?>"
           sizes="(max-width: 1400px) 100vw, 1400px"
           loading="lazy"
           decoding="async">
    </picture>
    <?php if (!empty($items[0]['press_url'])): ?>
    <div class="alexk-press-link-bar">
      <a class="alexk-press-link"
         href="<?php echo $items[0]['press_url']; ?>"
         target="_blank"
         rel="noopener noreferrer">Read article →</a>
    </div>
    <?php endif; ?>
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
