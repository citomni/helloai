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

final class SecretSanitizer {

	private const MASK = '[REDACTED]';

	/**
	 * Key names that should always be masked when encountered in arrays.
	 *
	 * @var array<string, bool>
	 */
	private const SENSITIVE_KEYS = [
		'api_key' => true,
		'authorization' => true,
		'bearer_token' => true,
		'x-api-key' => true,
		'proxy-authorization' => true,
		'proxy_authorization' => true,
		'basic_auth' => true,
		'password' => true,
		'client_secret' => true,
		'access_token' => true,
		'refresh_token' => true,
	];

	/**
	 * Header names that should be masked when encountered as header lines.
	 *
	 * @var array<string, bool>
	 */
	private const SENSITIVE_HEADER_NAMES = [
		'authorization' => true,
		'x-api-key' => true,
		'proxy-authorization' => true,
	];

	/**
	 * Sanitizes a value for logging.
	 *
	 * Behavior:
	 * - Recursively sanitizes arrays.
	 * - Masks known sensitive keys.
	 * - Masks sensitive header lines like Authorization and X-API-Key.
	 * - Leaves non-array scalar values unchanged unless they are explicit auth headers.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize(mixed $value): mixed {
		if (\is_array($value)) {
			return self::sanitizeArray($value);
		}

		if (\is_string($value)) {
			return self::sanitizeHeaderLine($value);
		}

		return $value;
	}

	/**
	 * Recursively sanitizes an array.
	 *
	 * @param array<mixed> $value Array to sanitize.
	 * @return array<mixed> Sanitized array.
	 */
	private static function sanitizeArray(array $value): array {
		$result = [];

		foreach ($value as $key => $item) {
			$normalizedKey = \is_string($key) ? self::normalizeKey($key) : null;

			if ($normalizedKey !== null && isset(self::SENSITIVE_KEYS[$normalizedKey])) {
				$result[$key] = self::maskSensitiveValue($normalizedKey, $item);
				continue;
			}

			if (\is_string($item)) {
				$result[$key] = self::sanitizeHeaderLine($item);
				continue;
			}

			if (\is_array($item)) {
				$result[$key] = self::sanitizeArray($item);
				continue;
			}

			$result[$key] = $item;
		}

		return $result;
	}

	/**
	 * Masks a sensitive value while preserving a useful shape where practical.
	 *
	 * Notes:
	 * - Arrays are sanitized recursively and then fully replaced when the top-level
	 *   key itself is sensitive, except for basic_auth where username may be useful.
	 *
	 * @param string $normalizedKey Normalized sensitive key.
	 * @param mixed $value Original value.
	 * @return mixed Sanitized value.
	 */
	private static function maskSensitiveValue(string $normalizedKey, mixed $value): mixed {
		if ($normalizedKey === 'basic_auth' && \is_array($value)) {
			return [
				'username' => isset($value['username']) && \is_string($value['username']) ? $value['username'] : null,
				'password' => self::MASK,
			];
		}

		return self::MASK;
	}

	/**
	 * Masks sensitive header lines.
	 *
	 * Supported examples:
	 * - Authorization: Bearer abc123
	 * - X-API-Key: abc123
	 * - Proxy-Authorization: Basic abc123
	 *
	 * @param string $value String that may be a header line.
	 * @return string Sanitized or original string.
	 */
	private static function sanitizeHeaderLine(string $value): string {
		$pos = \strpos($value, ':');
		if ($pos === false) {
			return $value;
		}

		$name = \strtolower(\trim((string)\substr($value, 0, $pos)));
		if ($name === '' || !isset(self::SENSITIVE_HEADER_NAMES[$name])) {
			return $value;
		}

		return \substr($value, 0, $pos + 1) . ' ' . self::MASK;
	}

	/**
	 * Normalizes an array key for sensitive-key comparison.
	 *
	 * @param string $key Original key.
	 * @return string Normalized key.
	 */
	private static function normalizeKey(string $key): string {
		return \strtolower(\str_replace(' ', '_', \trim($key)));
	}

}
