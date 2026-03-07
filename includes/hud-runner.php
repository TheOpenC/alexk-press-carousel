<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/hud-store.php';
require_once __DIR__ . '/hud-helpers.php';

/**
 * Minimal self-pumping runner.
 *
 * Key principles:
 * - Runner does NOT "estimate" progress.
 * - Progress is truth: only tick_success/tick_error update done/errors.
 * - Runner only schedules work + moves through a queue.
 *
 * Assumptions:
 * - Job record contains 'queue' => array of work items.
 * - Each work item represents ONE derivative to create (one output file).
 *
 * A work item shape (example):
 * [
 *   'attachment_id' => 123,
 *   'src'           => '/abs/path/to/original.png',
 *   'dest'          => '/abs/path/to/output.webp',
 *   'variant'       => 'webp-800',  // any label you want
 *   'args'          => [ ... ],     // optional
 * ]
 *
 * You will provide the actual builder function:
 *   alexk_build_one_derivative(array $item): true|string
 *     - return true on success
 *     - return string error message on failure
 */

/** ---------- Locking (prevents concurrent pumps) ---------- */

function alexk_job_lock_key(string $job_id): string {
  return 'alexk_job_lock_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $job_id);
}

function alexk_job_acquire_lock(string $job_id, int $ttl = 60): bool {
  $key = alexk_job_lock_key($job_id);
  $now = time();

  // add_option returns false if it already exists
  $lock = [
    'ts'  => $now,
    'ttl' => $ttl,
  ];

  if (add_option($key, $lock, '', 'no')) return true;

  // If lock exists, check staleness
  $existing = get_option($key);
  $ts  = is_array($existing) ? (int)($existing['ts'] ?? 0) : 0;
  $etl = is_array($existing) ? (int)($existing['ttl'] ?? 0) : 0;

  $expired = ($ts > 0 && ($now - $ts) > max(5, $etl));
  if ($expired) {
    update_option($key, $lock, false);
    return true;
  }

  return false;
}

function alexk_job_release_lock(string $job_id): void {
  delete_option(alexk_job_lock_key($job_id));
}

/** ---------- Scheduling (self-pump) ---------- */

function alexk_job_schedule_next_pump(string $job_id): void {
  // Fire-and-forget request to admin-ajax pump endpoint
  $url = admin_url('admin-ajax.php?action=alexk_job_pump&job_id=' . rawurlencode($job_id));

  wp_remote_get($url, [
    'timeout'   => 0.01,
    'blocking'  => false,
    'sslverify' => true,
    'headers'   => [
      // helps avoid some proxy caches
      'Cache-Control' => 'no-cache',
    ],
  ]);
}

/** ---------- Pump core ---------- */

function alexk_job_do_pump(string $job_id, int $batch_size = 10): array {
  $job = alexk_job_get($job_id);
  if (!$job) return ['ok' => false, 'error' => 'unknown job'];

  if (($job['status'] ?? '') !== 'running') {
    return ['ok' => true, 'msg' => 'job not running', 'job' => $job];
  }

  if (!alexk_job_acquire_lock($job_id, 60)) {
    // Another pump is already active.
    return ['ok' => true, 'msg' => 'locked', 'job' => $job];
  }

  try {
    $queue = $job['queue'] ?? [];
    if (!is_array($queue)) $queue = [];

    // Nothing left? finish.
    if (count($queue) === 0) {
      $job = alexk_job_finish($job_id, 'done');
      return ['ok' => true, 'msg' => 'done', 'job' => $job];
    }

    $processed = 0;

    while ($processed < $batch_size && count($queue) > 0) {
      $item = array_shift($queue);
      if (!is_array($item)) continue;

      $current = [
        'attachment_id' => (int)($item['attachment_id'] ?? 0),
        'filename'      => (string)($item['dest'] ?? ''),
        'variant'       => (string)($item['variant'] ?? ''),
        'step'          => 'build',
      ];

      // IMPORTANT: this function is YOUR real builder.
      // Return true on success, string on failure.
      $result = function_exists('alexk_build_one_derivative')
        ? alexk_build_one_derivative($item)
        : 'Missing function alexk_build_one_derivative($item)';

      if ($result === true) {
        alexk_job_tick_success($job_id, $current);
      } else {
        $msg = is_string($result) ? $result : 'unknown error';
        alexk_job_tick_error($job_id, $msg, $current);
      }

      $processed++;
    }

    // Persist remaining queue + maybe finish
    $job = alexk_job_patch($job_id, [
      'queue' => $queue,
    ]);

    $remaining = is_array($queue) ? count($queue) : 0;
    if ($remaining <= 0) {
      $job = alexk_job_finish($job_id, 'done');
      return ['ok' => true, 'msg' => 'done', 'job' => $job];
    }

    // Schedule next pump
    alexk_job_schedule_next_pump($job_id);

    return ['ok' => true, 'msg' => 'pumped', 'processed' => $processed, 'remaining' => $remaining, 'job' => $job];

  } catch (Throwable $e) {
    alexk_job_tick_error($job_id, 'Pump exception: ' . $e->getMessage(), ['step' => 'exception']);
    $job = alexk_job_finish($job_id, 'error');
    return ['ok' => false, 'error' => $e->getMessage(), 'job' => $job];
  } finally {
    alexk_job_release_lock($job_id);
  }
}

/** ---------- AJAX endpoint for the pump ---------- */

add_action('wp_ajax_alexk_job_pump', function () {
  if (!current_user_can('manage_options')) {
    wp_send_json_error(['msg' => 'forbidden'], 403);
  }

  $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
  if ($job_id === '') {
    wp_send_json_error(['msg' => 'missing job_id'], 400);
  }

  $out = alexk_job_do_pump($job_id, 10);
  wp_send_json_success($out);
});
