<?php
if (!defined('ABSPATH')) exit;

/**
 * HUD helpers for AlexK Press Carousel.
 * Adapted from alexk-carousel — uses press-namespaced functions.
 */

function alexk_build_queue_from_attachment_ids(array $attachment_ids): array {
  $queue = [];

  foreach ($attachment_ids as $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) continue;

    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) continue;

    $mime = get_post_mime_type($attachment_id);
    if (!$mime || strpos($mime, 'image/') !== 0) continue;

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, ['svg', 'pdf'], true)) continue;

    if (!function_exists('alexk_press_output_dir_for_attachment')) continue;
    if (!function_exists('alexk_press_cancel_marker_path')) continue;
    if (!function_exists('alexk_press_widths')) continue;

    $out_dir     = alexk_press_output_dir_for_attachment($attachment_id, $file);
    $cancel_path = alexk_press_cancel_marker_path($attachment_id, $file);
    if (!$out_dir || !$cancel_path) continue;
    if (file_exists($cancel_path)) continue;

    $base = basename($file);
    $stem = preg_replace('/\.[^.]+$/', '', $base);

    $info = @getimagesize($file);
    if (!$info || empty($info[0]) || empty($info[1])) continue;

    $native_max = max((int)$info[0], (int)$info[1]);
    if ($native_max <= 0) continue;

    $widths   = alexk_press_widths();
    $max_list = max($widths);

    if ($native_max < $max_list) {
      $widths[] = $native_max;
      $widths   = array_values(array_unique($widths));
      sort($widths);
    }

    $widths = array_values(array_filter($widths, function ($w) use ($native_max) {
      return (int)$w > 0 && (int)$w <= $native_max;
    }));

    if (empty($widths)) continue;

    foreach ($widths as $w) {
      $w = (int)$w;

      $queue[] = [
        'type'          => 'render',
        'attachment_id' => $attachment_id,
        'src'           => $file,
        'out_dir'       => $out_dir,
        'cancel_path'   => $cancel_path,
        'stem'          => $stem,
        'width'         => $w,
        'format'        => 'webp',
        'dest'          => $out_dir . '/' . $stem . '-w' . $w . '.webp',
        'variant'       => 'webp-w' . $w,
      ];

      $queue[] = [
        'type'          => 'render',
        'attachment_id' => $attachment_id,
        'src'           => $file,
        'out_dir'       => $out_dir,
        'cancel_path'   => $cancel_path,
        'stem'          => $stem,
        'width'         => $w,
        'format'        => 'jpg',
        'dest'          => $out_dir . '/' . $stem . '-w' . $w . '.jpg',
        'variant'       => 'jpg-w' . $w,
      ];
    }
  }

  return $queue;
}

/**
 * Execute ONE work item.
 */
function alexk_build_one_derivative(array $item) {
  $type          = (string)($item['type'] ?? '');
  $attachment_id = (int)($item['attachment_id'] ?? 0);
  $src           = (string)($item['src'] ?? '');
  $dest          = (string)($item['dest'] ?? '');
  $out_dir       = (string)($item['out_dir'] ?? '');
  $cancel_path   = (string)($item['cancel_path'] ?? '');

  if ($attachment_id <= 0) return 'bad attachment_id';
  if ($dest === '')         return 'missing dest';
  if ($out_dir === '')      return 'missing out_dir';
  if ($cancel_path === '')  return 'missing cancel_path';

  if (file_exists($cancel_path)) return 'canceled';

  if (!is_dir($out_dir)) wp_mkdir_p($out_dir);
  if (!is_dir($out_dir)) return 'failed to create output dir';

  if (file_exists($dest)) return true; // idempotent

  if ($type === 'render') {
    if (!$src || !file_exists($src)) return 'source missing';
    $w      = (int)($item['width'] ?? 0);
    $format = (string)($item['format'] ?? '');
    if ($w <= 0) return 'bad width';
    if ($format !== 'webp' && $format !== 'jpg') return 'bad format';

    if (!function_exists('alexk_press_resize_and_write')) {
      return 'missing function alexk_press_resize_and_write';
    }

    $ok = alexk_press_resize_and_write($src, $dest, $w, $format, $cancel_path, $out_dir);
    return $ok ? true : "render failed ({$format} {$w})";
  }

  if ($type === 'delete_attachment') {
    if (!function_exists('alexk_delete_press_derivatives_for_attachment')) {
      return 'missing function alexk_delete_press_derivatives_for_attachment';
    }
    alexk_delete_press_derivatives_for_attachment($attachment_id);
    return true;
  }

  return 'unknown item type';
}
