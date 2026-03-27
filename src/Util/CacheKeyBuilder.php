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

namespace CitOmni\HelloAi\Util;

/**
 * Deterministic cache-key builder for normalized request payloads.
 *
 * Behavior:
 * - Normalizes nested arrays recursively before hashing.
 * - Sorts associative array keys to eliminate insertion-order variance.
 * - Preserves list order because message and content block order is semantically significant.
 * - Encodes the normalized payload as stable JSON and returns a SHA-256 hash.
 *
 * Notes:
 * - This utility is pure and has no IO, config, or framework dependencies.
 * - The caller is responsible for passing already-normalized request data.
 * - v1 request shapes should normally contain arrays, scalars, and null only.
 * - Floats preserve zero fractions in the encoded JSON.
 *
 * Typical usage:
 *   $cacheKey = CacheKeyBuilder::build([
 *   	'profile' => $request['profile'],
 *   	'messages' => $request['messages'],
 *   	'options' => $request['options'],
 *   	'tools' => $request['tools'],
 *   	'provider_options' => $request['provider_options'],
 *   ]);
 *
 * @param array $payload Normalized payload used to derive the cache identity.
 * @return string Deterministic SHA-256 cache key.
 * @throws \InvalidArgumentException When the payload contains unsupported values.
 * @throws \JsonException When the normalized payload cannot be JSON-encoded.
 */
final class CacheKeyBuilder {

	/**
	 * Build a deterministic cache key from a normalized payload.
	 *
	 * @param array $payload Normalized payload used to derive the cache identity.
	 * @return string Deterministic SHA-256 cache key.
	 * @throws \InvalidArgumentException When the payload contains unsupported object values.
	 * @throws \JsonException When the normalized payload cannot be JSON-encoded.
	 */
	public static function build(array $payload): string {
		$normalized = self::normalizeValue($payload);
		$json = \json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

		return \hash('sha256', $json);
	}


	/**
	 * Normalize one value recursively for deterministic hashing.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 * @throws \InvalidArgumentException When the value contains an object.
	 */
	private static function normalizeValue(mixed $value): mixed {
		if (\is_array($value)) {
			return self::normalizeArray($value);
		}

		if (\is_object($value)) {
			throw new \InvalidArgumentException('CacheKeyBuilder payload must not contain objects.');
		}

		if (\is_resource($value)) {
			throw new \InvalidArgumentException('CacheKeyBuilder payload must not contain resources.');
		}

		return $value;
	}


	/**
	 * Normalize one array recursively.
	 *
	 * Behavior:
	 * - Associative arrays are key-sorted recursively.
	 * - Lists preserve original order and only normalize each element.
	 *
	 * @param array $value Array to normalize.
	 * @return array Normalized array.
	 * @throws \InvalidArgumentException When an array element contains an object.
	 */
	private static function normalizeArray(array $value): array {
		if (\array_is_list($value)) {
			$result = [];

			foreach ($value as $item) {
				$result[] = self::normalizeValue($item);
			}

			return $result;
		}

		$result = [];

		foreach ($value as $key => $item) {
			$result[(string)$key] = self::normalizeValue($item);
		}

		\ksort($result);

		return $result;
	}

}
