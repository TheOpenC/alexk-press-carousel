<?php
if (!defined('ABSPATH')) exit;

/**
 * A single authoritative truth record per job_id.
 * Stored in wp_options as: alexk_job_{job_id}
 *
 * This file does NOT run work. It only stores/updates truth.
 */

function alexk_job_option_key(string $job_id): string {
  return 'alexk_job_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $job_id);
}

function alexk_job_now(): int {
  return time();
}

/**
 * Create a new job record.
 */
function alexk_job_create(array $args): array {
  $job_id = $args['job_id'] ?? '';
  if (!$job_id) {
    return ['ok' => false, 'error' => 'missing job_id'];
  }

  $job = [
    'job_id'          => (string) $job_id,
    'status'          => 'running',          // running|done|error|canceled
    'mode'            => (string) ($args['mode'] ?? ''), // add|remove|rebuild etc

    // Truth counters:
    'total'           => (int) ($args['total'] ?? 0),    // EXACT planned derivative count
    'done'            => 0,                              // increments only on successful derivative write
    'errors'          => 0,                              // increments on derivative failure

    // Current activity:
    'current'         => [
      'attachment_id' => 0,
      'filename'      => '',
      'variant'       => '', // e.g. "jpg-1200" or "webp-800"
      'step'          => '', // optional text like "encoding"
    ],

    // Timing:
    'started_ts'      => alexk_job_now(),
    'last_update_ts'  => alexk_job_now(), // MUST update whenever done/errors changes

    // Optional debug/help:
    'last_error'      => '',
  ];

  $key = alexk_job_option_key($job_id);
  update_option($key, $job, false);

  return ['ok' => true, 'job' => $job];
}

/**
 * Read job truth.
 */
function alexk_job_get(string $job_id): ?array {
  $key = alexk_job_option_key($job_id);
  $job = get_option($key, null);
  return is_array($job) ? $job : null;
}

/**
 * Patch-update job truth safely.
 * $patch is shallow for top-level keys; 'current' patch handled explicitly.
 */
function alexk_job_patch(string $job_id, array $patch): ?array {
  $job = alexk_job_get($job_id);
  if (!$job) return null;

  foreach ($patch as $k => $v) {
    if ($k === 'current' && is_array($v)) {
      $job['current'] = array_merge(is_array($job['current']) ? $job['current'] : [], $v);
    } else {
      $job[$k] = $v;
    }
  }

  // Always keep last_update_ts sane if caller sets it
  if (!isset($job['last_update_ts']) || !is_int($job['last_update_ts'])) {
    $job['last_update_ts'] = alexk_job_now();
  }

  update_option(alexk_job_option_key($job_id), $job, false);
  return $job;
}

/**
 * Truth tick: call this ONLY after a derivative file is successfully written.
 */
function alexk_job_tick_success(string $job_id, array $current = []): ?array {
  $job = alexk_job_get($job_id);
  if (!$job) return null;

  $job['done'] = (int) ($job['done'] ?? 0) + 1;
  $job['last_update_ts'] = alexk_job_now();
  if ($current) {
    $job['current'] = array_merge(is_array($job['current']) ? $job['current'] : [], $current);
  }

  update_option(alexk_job_option_key($job_id), $job, false);
  return $job;
}

/**
 * Truth tick: call this when a derivative fails (caught exception / wp_error / encode fail).
 */
function alexk_job_tick_error(string $job_id, string $error_message, array $current = []): ?array {
  $job = alexk_job_get($job_id);
  if (!$job) return null;

  $job['errors'] = (int) ($job['errors'] ?? 0) + 1;
  $job['last_update_ts'] = alexk_job_now();
  $job['last_error'] = $error_message;
  if ($current) {
    $job['current'] = array_merge(is_array($job['current']) ? $job['current'] : [], $current);
  }

  update_option(alexk_job_option_key($job_id), $job, false);
  return $job;
}

/**
 * Mark job complete (done/canceled/error).
 */
function alexk_job_finish(string $job_id, string $status = 'done'): ?array {
  $allowed = ['done', 'canceled', 'error'];
  if (!in_array($status, $allowed, true)) $status = 'done';

  return alexk_job_patch($job_id, [
    'status' => $status,
    'last_update_ts' => alexk_job_now(),
  ]);
}
