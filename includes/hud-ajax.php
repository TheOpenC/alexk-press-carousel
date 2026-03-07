<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/hud-store.php';
require_once __DIR__ . '/hud-runner.php';
require_once __DIR__ . '/hud-helpers.php';

/**
 * AJAX: start job
 */
add_action('wp_ajax_alexk_press_job_start', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['msg' => 'forbidden'], 403);
  }

  $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'add';
  $ids  = isset($_POST['attachment_ids']) ? (array) $_POST['attachment_ids'] : [];
  $attachment_ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));

  if (count($attachment_ids) === 0) {
    wp_send_json_error(['msg' => 'no attachment_ids'], 400);
  }

  if (!function_exists('alexk_press_build_queue_from_attachment_ids')) {
    wp_send_json_error(['msg' => 'Missing function alexk_press_build_queue_from_attachment_ids'], 500);
  }

  $queue  = alexk_press_build_queue_from_attachment_ids($attachment_ids);
  if (!is_array($queue)) $queue = [];

  $job_id = 'job_' . time() . '_' . wp_generate_password(6, false, false);

  $created = alexk_press_job_create([
    'job_id' => $job_id,
    'mode'   => $mode,
    'total'  => count($queue),
  ]);

  if (!$created['ok']) {
    wp_send_json_error(['msg' => $created['error'] ?? 'job_create failed'], 500);
  }

  $job = alexk_press_job_patch($job_id, ['queue' => $queue]);

  alexk_press_job_schedule_next_pump($job_id);

  wp_send_json_success([
    'job_id' => $job_id,
    'total'  => (int)($job['total'] ?? count($queue)),
  ]);
});


/**
 * AJAX: job status
 */
add_action('wp_ajax_alexk_press_job_status', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['msg' => 'forbidden'], 403);
  }

  $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
  if ($job_id === '') {
    wp_send_json_error(['msg' => 'missing job_id'], 400);
  }

  $job = alexk_press_job_get($job_id);
  if (!$job) {
    wp_send_json_error(['msg' => 'unknown job_id'], 404);
  }

  $now             = time();
  $last            = (int)($job['last_update_ts'] ?? 0);
  $stall_threshold = 20;
  $stalled         = (($job['status'] ?? '') === 'running') && ($last > 0) && (($now - $last) > $stall_threshold);

  $done    = (int)($job['done'] ?? 0);
  $total   = max(0, (int)($job['total'] ?? 0));
  $percent = ($total > 0) ? (int)floor(($done / $total) * 100) : 0;

  wp_send_json_success([
    'job'      => $job,
    'computed' => [
      'now_ts'    => $now,
      'stalled'   => $stalled,
      'stall_age' => ($last > 0) ? ($now - $last) : null,
      'percent'   => $percent,
    ],
  ]);
});
