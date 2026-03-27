<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\HelloAi\Repository;

use CitOmni\Kernel\Repository\BaseRepository;

/**
 * Persist and retrieve HelloAi chat cache entries.
 *
 * Behavior:
 * - Reads valid cache entries by deterministic cache key.
 * - Stores cache payloads as JSON strings for request and response bodies.
 * - Uses upsert semantics so repeated saves for the same cache key replace the old entry.
 * - Treats malformed cached JSON as a cache miss and deletes the corrupted row.
 *
 * Notes:
 * - This repository owns all SQL for HelloAi cache storage.
 * - The table name is fixed and explicit to avoid config indirection in v1.
 * - TTL is enforced via expires_at.
 * - Datetime values are generated in PHP and compared against the DB using the same session timezone model.
 *
 * Typical usage:
 *   $repo = new HelloAiCacheRepository($this->app);
 *   $entry = $repo->findValidByCacheKey($cacheKey);
 *   $repo->save($cacheKey, $profile, $model, $request, $response, 3600);
 *
 * @phpstan-type CacheRow array{
 *     cache_key: string,
 *     profile: string,
 *     model: string,
 *     request_payload: string,
 *     response_payload: string,
 *     created_at: string,
 *     expires_at: string
 * }
 *
 * @phpstan-type CacheEntry array{
 *     cache_key: string,
 *     profile: string,
 *     model: string,
 *     request_payload: array,
 *     response_payload: array,
 *     created_at: string,
 *     expires_at: string
 * }
 */
final class HelloAiCacheRepository extends BaseRepository {

	private const TABLE = 'helloai_cache';

	/**
	 * Find one non-expired cache entry by cache key.
	 *
	 * Behavior:
	 * - Returns null when no valid row exists.
	 * - Returns null when the row exists but one of the JSON payloads is malformed.
	 * - Deletes malformed rows immediately to avoid repeated decode work on future cache checks.
	 *
	 * @param string $cacheKey Deterministic cache key.
	 * @return array|null Normalized cache entry or null when not found/invalid.
	 */
	public function findValidByCacheKey(string $cacheKey): ?array {
		$row = $this->app->db->fetchRow(
			'SELECT cache_key, profile, model, request_payload, response_payload, created_at, expires_at
			FROM ' . self::TABLE . '
			WHERE cache_key = ?
				AND expires_at > ?
			LIMIT 1',
			[
				$cacheKey,
				$this->now(),
			]
		);

		if ($row === null) {
			return null;
		}

		$requestPayload = $this->decodeJsonPayload($row['request_payload'] ?? null);
		$responsePayload = $this->decodeJsonPayload($row['response_payload'] ?? null);

		if ($requestPayload === null || $responsePayload === null) {
			$this->deleteByCacheKey($cacheKey);
			return null;
		}

		return [
			'cache_key' => (string)($row['cache_key'] ?? ''),
			'profile' => (string)($row['profile'] ?? ''),
			'model' => (string)($row['model'] ?? ''),
			'request_payload' => $requestPayload,
			'response_payload' => $responsePayload,
			'created_at' => (string)($row['created_at'] ?? ''),
			'expires_at' => (string)($row['expires_at'] ?? ''),
		];
	}


	/**
	 * Save or replace a cache entry.
	 *
	 * Behavior:
	 * - Encodes request and response payloads as JSON.
	 * - Replaces any existing row with the same cache key.
	 * - Resets created_at and expires_at on every write.
	 *
	 * @param string $cacheKey Deterministic cache key.
	 * @param string $profileId Resolved HelloAi profile id.
	 * @param string $model Resolved provider model name.
	 * @param array $requestPayload Normalized internal request payload.
	 * @param array $responsePayload Final normalized response payload.
	 * @param int $ttl Cache TTL in seconds. Values below 1 are normalized to 1.
	 * @return void
	 * @throws \JsonException When JSON encoding fails.
	 */
	public function save(string $cacheKey, string $profileId, string $model, array $requestPayload, array $responsePayload, int $ttl): void {

		$ttl = $ttl > 0 ? $ttl : 1;
		$createdAt = $this->now();
		$expiresAt = $this->dateTimeFromTimestamp(\time() + $ttl);

		$this->app->db->execute(
			'INSERT INTO ' . self::TABLE . ' (
				cache_key,
				profile,
				model,
				request_payload,
				response_payload,
				created_at,
				expires_at
			) VALUES (?, ?, ?, ?, ?, ?, ?)
			ON DUPLICATE KEY UPDATE
				profile = VALUES(profile),
				model = VALUES(model),
				request_payload = VALUES(request_payload),
				response_payload = VALUES(response_payload),
				created_at = VALUES(created_at),
				expires_at = VALUES(expires_at)',
			[
				$cacheKey,
				$profileId,
				$model,
				$this->encodeJsonPayload($requestPayload),
				$this->encodeJsonPayload($responsePayload),
				$createdAt,
				$expiresAt,
			]
		);
	}


	/**
	 * Delete one cache entry by cache key.
	 *
	 * @param string $cacheKey Deterministic cache key.
	 * @return int Number of deleted rows.
	 */
	public function deleteByCacheKey(string $cacheKey): int {
		return $this->app->db->delete(self::TABLE, 'cache_key = ?', [$cacheKey]);
	}


	/**
	 * Delete expired cache entries.
	 *
	 * @return int Number of deleted rows.
	 */
	public function pruneExpired(): int {
		return $this->app->db->delete(self::TABLE, 'expires_at <= ?', [$this->now()]);
	}


	/**
	 * Encode one payload as stable JSON for storage.
	 *
	 * @param array $payload Payload to encode.
	 * @return string JSON string.
	 * @throws \JsonException When encoding fails.
	 */
	private function encodeJsonPayload(array $payload): string {
		return \json_encode(
			$payload,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
		);
	}


	/**
	 * Decode one stored JSON payload.
	 *
	 * @param mixed $json Raw JSON column value.
	 * @return array|null Decoded payload array, or null when invalid.
	 */
	private function decodeJsonPayload(mixed $json): ?array {
		if (!\is_string($json) || $json === '') {
			return null;
		}

		try {
			$decoded = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return \is_array($decoded) ? $decoded : null;
	}


	/**
	 * Return the current local datetime string used for DB comparisons and writes.
	 *
	 * @return string Current datetime in SQL format.
	 */
	private function now(): string {
		return \date('Y-m-d H:i:s');
	}


	/**
	 * Format a Unix timestamp as SQL datetime.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Datetime in SQL format.
	 */
	private function dateTimeFromTimestamp(int $timestamp): string {
		return \date('Y-m-d H:i:s', $timestamp);
	}


}
