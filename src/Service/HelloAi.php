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

namespace CitOmni\HelloAi\Service;

use CitOmni\HelloAi\Interface\AdapterInterface;
use CitOmni\HelloAi\Exception\AdapterNotFoundException;
use CitOmni\HelloAi\Exception\InvalidRequestException;
use CitOmni\HelloAi\Exception\ProfileNotFoundException;
use CitOmni\HelloAi\Exception\ProviderRequestException;
use CitOmni\HelloAi\Repository\HelloAiCacheRepository;
use CitOmni\HelloAi\Util\CacheKeyBuilder;
use CitOmni\HelloAi\Util\SecretSanitizer;
use CitOmni\Infrastructure\Exception\CurlConfigException;
use CitOmni\Infrastructure\Exception\CurlExecException;
use CitOmni\Infrastructure\Exception\CurlResponseParseException;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Service\BaseService;

/**
 * Main HelloAi orchestration service.
 *
 * Receives the internal chat request, applies package defaults,
 * resolves the selected profile, validates the basic request shape,
 * performs cache lookup, delegates provider-specific request/response
 * translation to the adapter, sends the HTTP request via the existing
 * Curl service, logs useful development context, stores successful
 * responses in cache, and returns the normalized response format.
 *
 * Behavior:
 * - Owns the package-level orchestration for v1 chat calls.
 * - Keeps SQL out of the service by delegating cache persistence to Repository.
 * - Keeps provider-specific JSON out of the service by delegating translation to Adapter.
 * - Uses deterministic cache keys built from the normalized internal request.
 * - Does not do fallback, retry, streaming, or automatic model routing.
 *
 * Notes:
 * - This implementation assumes adapter and repository contracts that
 *   are not yet fully frozen in the current working material.
 * - Logging is intentionally detailed in v1, but auth secrets are sanitized before logging.
 * - Package-owned cfg paths are accessed directly; the package is expected to ship
 *   its own cfg baseline.
 *
 * Typical usage:
 *   $result = $this->app->helloAi->chat([
 *       'messages' => [
 *           [
 *               'role' => 'user',
 *               'content' => [
 *                   ['type' => 'text', 'text' => 'Hello'],
 *               ],
 *           ],
 *       ],
 *   ]);
 *
 * @param array $request Internal HelloAi request.
 * @return array Normalized HelloAi response.
 * @throws \CitOmni\HelloAi\Exception\AdapterNotFoundException When the configured adapter class is missing or invalid.
 * @throws \CitOmni\HelloAi\Exception\InvalidRequestException When the request shape is invalid.
 * @throws \CitOmni\HelloAi\Exception\ProfileNotFoundException When the selected profile does not exist.
 */
final class HelloAi extends BaseService {

	private const LOG_FILE = 'helloai';
	private const LOG_CATEGORY = 'helloai.chat';

	private HelloAiCacheRepository $cacheRepository;





	// ----------------------------------------------------------------
	// Lifecycle
	// ----------------------------------------------------------------

	/**
	 * Initializes repository collaborator.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->cacheRepository = new HelloAiCacheRepository($this->app);
	}






	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Sends one normalized chat request through the configured provider profile.
	 *
	 * @param array $request Internal HelloAi request.
	 * @return array Normalized HelloAi response.
	 * @throws \CitOmni\HelloAi\Exception\AdapterNotFoundException When the configured adapter class is missing or invalid.
	 * @throws \CitOmni\HelloAi\Exception\InvalidRequestException When the request shape is invalid.
	 * @throws \CitOmni\HelloAi\Exception\ProfileNotFoundException When the selected profile does not exist.
	 * @throws \CitOmni\HelloAi\Exception\ProviderRequestException When the provider request cannot be built or configured correctly.
	 * @throws \CitOmni\Infrastructure\Exception\CurlConfigException When the outbound Curl request is invalid.
	 * @throws \CitOmni\Infrastructure\Exception\CurlExecException When the outbound Curl request fails during execution.
	 * @throws \CitOmni\Infrastructure\Exception\CurlResponseParseException When the Curl response cannot be parsed.
	 */
	public function chat(array $request): array {
		$startedAt = \microtime(true);

		// -- 1. Normalize request and resolve profile -------------------
		$normalizedRequest = $this->normalizeRequest($request);
		$this->validateRequest($normalizedRequest);

		$profileId = (string)$normalizedRequest['profile'];
		$profileConfig = $this->resolveProfileConfig($profileId);
		$adapter = $this->buildAdapter($profileId, $profileConfig);
		$cacheKey = $this->buildCacheKey($normalizedRequest);
		$cacheEnabled = $this->isCacheEnabled();
		$ttl = $this->getCacheTtl();

		$this->log('request.prepared', [
			'profile' => $profileId,
			'model' => (string)($profileConfig['model'] ?? ''),
			'adapter' => $adapter::class,
			'cache_enabled' => $cacheEnabled,
			'cache_key' => $cacheKey,
			'request' => $this->sanitizeForLog($normalizedRequest),
		]);

		// -- 2. Return cached response when possible --------------------
		if ($cacheEnabled) {
			$cachedEntry = $this->cacheRepository->findValidByCacheKey($cacheKey);

			if (\is_array($cachedEntry)) {
				$response = $this->responseFromCacheEntry($cachedEntry, $cacheKey, $startedAt);

				$this->log('cache.hit', [
					'profile' => $profileId,
					'cache_key' => $cacheKey,
					'hit' => true,
					'duration_ms' => $response['meta']['duration_ms'] ?? null,
				]);

				return $response;
			}

			$this->log('cache.miss', [
				'profile' => $profileId,
				'cache_key' => $cacheKey,
				'hit' => false,
			]);
		}

		// -- 3. Build provider request and send it ----------------------
		try {
			$providerRequest = $adapter->buildRequest($normalizedRequest);
			$providerHeaders = $adapter->buildHeaders($normalizedRequest);
			$this->validateProviderHeaders($providerHeaders, $profileId);
			$curlRequest = $this->buildCurlRequest($profileId, $profileConfig, $adapter, $cacheKey, $providerHeaders, $providerRequest);

			$this->log('provider.request', [
				'profile' => $profileId,
				'model' => (string)($profileConfig['model'] ?? ''),
				'adapter' => $adapter::class,
				'cache_key' => $cacheKey,
				'base_url' => (string)($profileConfig['base_url'] ?? ''),
				'timeout' => (int)($profileConfig['timeout'] ?? 30),
				'headers' => $this->sanitizeForLog($providerHeaders),
				'request' => $this->sanitizeForLog($providerRequest),
				'curl_request' => $this->sanitizeForLog($curlRequest),
			]);

			$transportResult = $this->app->curl->execute($curlRequest);

			$this->log('provider.response', [
				'profile' => $profileId,
				'model' => (string)($profileConfig['model'] ?? ''),
				'adapter' => $adapter::class,
				'cache_key' => $cacheKey,
				'status_code' => $transportResult['status_code'] ?? null,
				'response' => $this->sanitizeForLog($transportResult),
			]);

			$response = $adapter->parseResponse($transportResult, $normalizedRequest);

			if (!\is_array($response['meta'] ?? null)) {
				$response['meta'] = [];
			}

			if (($normalizedRequest['debug']['include_built_request'] ?? false) === true) {
				$response['meta']['built_request'] = $providerRequest;
			}

			$response = $this->finalizeResponse($response, $profileId, $profileConfig, $cacheKey, false, $startedAt);

			if ($cacheEnabled) {
				$this->cacheRepository->save(
					$cacheKey,
					$profileId,
					(string)($response['model'] ?? (string)($profileConfig['model'] ?? '')),
					$normalizedRequest,
					$response,
					$ttl
				);

				$this->log('cache.store', [
					'profile' => $profileId,
					'cache_key' => $cacheKey,
					'ttl' => $ttl,
				]);
			}

			$this->log('request.completed', [
				'profile' => $profileId,
				'model' => (string)($response['model'] ?? ''),
				'adapter' => $adapter::class,
				'cache_key' => $cacheKey,
				'status_code' => $transportResult['status_code'] ?? null,
				'duration_ms' => $response['meta']['duration_ms'] ?? null,
			]);

			return $response;
			
		} catch (CurlConfigException | CurlExecException | CurlResponseParseException $e) {
			$this->logRequestFailure($profileId, $profileConfig, $adapter, $cacheKey, $startedAt, $e);
			throw $e;
		} catch (\Throwable $e) {
			$this->logRequestFailure($profileId, $profileConfig, $adapter, $cacheKey, $startedAt, $e);
			throw $e;
		}
	}







	// ----------------------------------------------------------------
	// Request normalization and validation
	// ----------------------------------------------------------------

	/**
	 * Applies package defaults to the incoming request.
	 *
	 * @param array $request Raw request.
	 * @return array Normalized request.
	 */
	private function normalizeRequest(array $request): array {
		$helloAiCfg = $this->app->cfg->helloai;

		$defaultDebug = [
			'include_raw_response' => (bool)($helloAiCfg->debug->include_raw_response ?? false),
			'include_built_request' => (bool)($helloAiCfg->debug->include_built_request ?? false),
		];

		$normalized = $request;

		$normalized['profile'] = (string)($normalized['profile'] ?? $helloAiCfg->default_profile ?? '');
		$normalized['messages'] = \is_array($normalized['messages'] ?? null) ? $normalized['messages'] : [];
		$normalized['options'] = \is_array($normalized['options'] ?? null) ? $normalized['options'] : [];
		$normalized['tools'] = \is_array($normalized['tools'] ?? null) ? $normalized['tools'] : [];
		$normalized['provider_options'] = \is_array($normalized['provider_options'] ?? null) ? $normalized['provider_options'] : [];
		$normalized['debug'] = \is_array($normalized['debug'] ?? null) ? (\array_replace($defaultDebug, $normalized['debug'])) : $defaultDebug;

		return $normalized;
	}


	/**
	 * Validates the basic request shape required by v1.
	 *
	 * @param array $request Normalized request.
	 * @return void
	 * @throws \CitOmni\HelloAi\Exception\InvalidRequestException When the request shape is invalid.
	 */
	private function validateRequest(array $request): void {
		if ($request['profile'] === '') {
			throw new InvalidRequestException('HelloAi request must resolve to a non-empty profile.');
		}

		if (!\is_array($request['messages']) || $request['messages'] === []) {
			throw new InvalidRequestException('HelloAi request must contain a non-empty messages array.');
		}

		foreach ($request['messages'] as $messageIndex => $message) {
			if (!\is_array($message)) {
				throw new InvalidRequestException(\sprintf('HelloAi message at index %d must be an array.', $messageIndex));
			}

			if (!isset($message['role']) || !\is_string($message['role']) || $message['role'] === '') {
				throw new InvalidRequestException(\sprintf('HelloAi message at index %d must contain a non-empty string role.', $messageIndex));
			}

			if (!isset($message['content']) || !\is_array($message['content'])) {
				throw new InvalidRequestException(\sprintf('HelloAi message at index %d must contain a content array.', $messageIndex));
			}

			foreach ($message['content'] as $blockIndex => $block) {
				if (!\is_array($block)) {
					throw new InvalidRequestException(\sprintf(
						'HelloAi content block at message index %d and block index %d must be an array.',
						$messageIndex,
						$blockIndex
					));
				}

				if (($block['type'] ?? null) === 'text' && (!isset($block['text']) || !\is_string($block['text']))) {
					throw new InvalidRequestException(\sprintf(
						'HelloAi text block at message index %d and block index %d must contain a string text field.',
						$messageIndex,
						$blockIndex
					));
				}
			}
		}

		if (!\is_array($request['options'])) {
			throw new InvalidRequestException('HelloAi request options must be an array.');
		}

		if (!\is_array($request['tools'])) {
			throw new InvalidRequestException('HelloAi request tools must be an array.');
		}

		if (!\is_array($request['provider_options'])) {
			throw new InvalidRequestException('HelloAi request provider_options must be an array.');
		}

		if (!\is_array($request['debug'])) {
			throw new InvalidRequestException('HelloAi request debug must be an array.');
		}
	}







	// ----------------------------------------------------------------
	// Profile and adapter resolution
	// ----------------------------------------------------------------

	/**
	 * Resolves one configured profile by profile id.
	 *
	 * @param string $profileId Profile id.
	 * @return array Profile configuration.
	 * @throws \CitOmni\HelloAi\Exception\ProfileNotFoundException When the profile does not exist.
	 */
	private function resolveProfileConfig(string $profileId): array {
		if (!isset($this->app->cfg->helloai->profiles->{$profileId})) {
			throw new ProfileNotFoundException(\sprintf('HelloAi profile "%s" was not found.', $profileId));
		}

		$profileConfig = $this->app->cfg->helloai->profiles->{$profileId};

		if (!$profileConfig instanceof Cfg) {
			throw new ProviderRequestException(\sprintf(
				'HelloAi profile "%s" must resolve to an associative config node.',
				$profileId
			));
		}

		return $profileConfig->toArray();
	}


	/**
	 * Instantiates the adapter configured for the selected profile.
	 *
	 * @param string $profileId Profile id.
	 * @param array $profileConfig Profile configuration.
	 * @return \CitOmni\HelloAi\Interface\AdapterInterface Adapter instance.
	 * @throws \CitOmni\HelloAi\Exception\AdapterNotFoundException When the adapter is missing or invalid.
	 */
	private function buildAdapter(string $profileId, array $profileConfig): AdapterInterface {
		$adapterClass = $profileConfig['adapter'] ?? null;

		if (!\is_string($adapterClass) || $adapterClass === '') {
			throw new AdapterNotFoundException(\sprintf('HelloAi profile "%s" does not define a valid adapter class.', $profileId));
		}

		if (!\class_exists($adapterClass)) {
			throw new AdapterNotFoundException(\sprintf('HelloAi adapter class "%s" was not found.', $adapterClass));
		}

		$adapter = new $adapterClass($this->app, $profileId, $profileConfig);

		if (!$adapter instanceof AdapterInterface) {
			throw new AdapterNotFoundException(\sprintf(
				'HelloAi adapter "%s" for profile "%s" must implement %s.',
				$adapterClass,
				$profileId,
				AdapterInterface::class
			));
		}

		return $adapter;
	}








	// ----------------------------------------------------------------
	// Provider request building
	// ----------------------------------------------------------------

	/**
	 * Builds the outbound Curl request for one provider call.
	 *
	 * @param string $profileId Active profile id.
	 * @param array $profileConfig Active profile configuration.
	 * @param \CitOmni\HelloAi\Interface\AdapterInterface $adapter Active adapter.
	 * @param string $cacheKey Deterministic cache key.
	 * @param array $providerHeaders Provider-specific HTTP headers.
	 * @param array $providerRequest Provider-specific request payload.
	 * @return array Curl request array.
	 * @throws \CitOmni\HelloAi\Exception\ProviderRequestException When required provider transport config is missing or invalid.
	 */
	private function buildCurlRequest(string $profileId, array $profileConfig, AdapterInterface $adapter, string $cacheKey, array $providerHeaders, array $providerRequest): array {
		$baseUrl = (string)($profileConfig['base_url'] ?? '');
		if ($baseUrl === '') {
			throw new ProviderRequestException(\sprintf(
				'HelloAi profile "%s" must define a non-empty base_url.',
				$profileId
			));
		}

		$timeout = (int)($profileConfig['timeout'] ?? 30);
		if ($timeout < 0) {
			throw new ProviderRequestException(\sprintf(
				'HelloAi profile "%s" must define a timeout >= 0.',
				$profileId
			));
		}

		try {
			$body = \json_encode(
				$providerRequest,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		} catch (\JsonException $e) {
			throw new ProviderRequestException(
				'Failed to JSON encode the provider request payload.',
				0,
				$e
			);
		}

		return [
			'url' => $baseUrl,
			'method' => 'POST',
			'headers' => $providerHeaders,
			'body' => $body,
			'timeout' => $timeout,
			'log_context' => [
				'helloai_profile' => $profileId,
				'helloai_model' => (string)($profileConfig['model'] ?? ''),
				'helloai_adapter' => $adapter::class,
				'helloai_cache_key' => $cacheKey,
			],
		];
	}


	/**
	 * Validates provider-specific headers before sending the Curl request.
	 *
	 * @param array $providerHeaders Header lines.
	 * @param string $profileId Active profile id.
	 * @return void
	 * @throws \CitOmni\HelloAi\Exception\ProviderRequestException When headers are invalid.
	 */
	private function validateProviderHeaders(array $providerHeaders, string $profileId): void {
		foreach ($providerHeaders as $index => $headerLine) {
			if (!\is_string($headerLine) || $headerLine === '' || !\str_contains($headerLine, ':')) {
				throw new ProviderRequestException(\sprintf(
					'HelloAi profile "%s" produced an invalid header at index %d.',
					$profileId,
					$index
				));
			}
		}
	}







	// ----------------------------------------------------------------
	// Cache and response shaping
	// ----------------------------------------------------------------

	/**
	 * Builds the deterministic cache key for a normalized request.
	 *
	 * @param array $request Normalized request.
	 * @return string Cache key.
	 */
	private function buildCacheKey(array $request): string {
		return CacheKeyBuilder::build([
			'profile' => $request['profile'],
			'messages' => $request['messages'],
			'options' => $request['options'],
			'tools' => $request['tools'],
			'provider_options' => $request['provider_options'],
		]);
	}


	/**
	 * Converts one repository cache entry into the public normalized response.
	 *
	 * @param array $cacheEntry Repository cache row.
	 * @param string $cacheKey Cache key.
	 * @param float $startedAt Request start timestamp from microtime(true).
	 * @return array Normalized response.
	 */
	private function responseFromCacheEntry(array $cacheEntry, string $cacheKey, float $startedAt): array {
		$response = $cacheEntry['response_payload'] ?? [];

		if (!\is_array($response)) {
			$response = [];
		}

		$response['meta'] = \array_replace(
			\is_array($response['meta'] ?? null) ? $response['meta'] : [],
			[
				'cached' => true,
				'cache_key' => $cacheKey,
				'duration_ms' => $this->durationMs($startedAt),
			]
		);

		return $response;
	}


	/**
	 * Applies required top-level response defaults and meta fields.
	 *
	 * @param array $response Parsed adapter response.
	 * @param string $profileId Active profile id.
	 * @param array $profileConfig Active profile configuration.
	 * @param string $cacheKey Deterministic cache key.
	 * @param bool $cached Whether the response came from cache.
	 * @param float $startedAt Request start timestamp from microtime(true).
	 * @return array Finalized normalized response.
	 */
	private function finalizeResponse(array $response, string $profileId, array $profileConfig, string $cacheKey, bool $cached, float $startedAt): array {
		$response['profile'] = (string)($response['profile'] ?? $profileId);
		$response['provider'] = (string)($response['provider'] ?? '');
		$response['model'] = (string)($response['model'] ?? ($profileConfig['model'] ?? ''));
		$response['message'] = \is_array($response['message'] ?? null) ? $response['message'] : [
			'role' => 'assistant',
			'content' => [],
		];
		$response['finish_reason'] = $response['finish_reason'] ?? null;
		$response['usage'] = \is_array($response['usage'] ?? null) ? $response['usage'] : [
			'input_tokens' => null,
			'output_tokens' => null,
			'total_tokens' => null,
		];
		$response['tool_calls'] = \is_array($response['tool_calls'] ?? null) ? $response['tool_calls'] : [];
		$response['raw'] = $response['raw'] ?? null;
		$response['meta'] = \array_replace(
			\is_array($response['meta'] ?? null) ? $response['meta'] : [],
			[
				'cached' => $cached,
				'cache_key' => $cacheKey,
				'duration_ms' => $this->durationMs($startedAt),
			]
		);

		return $response;
	}


	/**
	 * Returns whether DB-backed caching is enabled.
	 *
	 * @return bool True when caching is enabled.
	 */
	private function isCacheEnabled(): bool {
		return (bool)($this->app->cfg->helloai->cache->enabled ?? true);
	}


	/**
	 * Returns cache TTL in seconds.
	 *
	 * @return int TTL in seconds.
	 */
	private function getCacheTtl(): int {
		$ttl = (int)($this->app->cfg->helloai->cache->ttl ?? 3600);

		return $ttl > 0 ? $ttl : 3600;
	}


	/**
	 * Computes elapsed duration in milliseconds.
	 *
	 * @param float $startedAt Request start timestamp from microtime(true).
	 * @return int Duration in milliseconds.
	 */
	private function durationMs(float $startedAt): int {
		return (int)\round((\microtime(true) - $startedAt) * 1000);
	}







	// ----------------------------------------------------------------
	// Logging helpers
	// ----------------------------------------------------------------

	/**
	 * Logs one failed HelloAi request with shared context.
	 *
	 * @param string $profileId Active profile id.
	 * @param array $profileConfig Active profile configuration.
	 * @param \CitOmni\HelloAi\Interface\AdapterInterface $adapter Active adapter.
	 * @param string $cacheKey Deterministic cache key.
	 * @param float $startedAt Request start timestamp from microtime(true).
	 * @param \Throwable $e Failure to log.
	 * @return void
	 */
	private function logRequestFailure(string $profileId, array $profileConfig, AdapterInterface $adapter, string $cacheKey, float $startedAt, \Throwable $e): void {
		$this->log('request.failed', [
			'profile' => $profileId,
			'model' => (string)($profileConfig['model'] ?? ''),
			'adapter' => $adapter::class,
			'cache_key' => $cacheKey,
			'exception_class' => $e::class,
			'exception_message' => $e->getMessage(),
			'duration_ms' => $this->durationMs($startedAt),
		]);
	}


	/**
	 * Writes one structured log entry when the log service is available.
	 *
	 * @param string $event Event name suffix.
	 * @param array $context Structured context.
	 * @return void
	 */
	private function log(string $event, array $context = []): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		$this->app->log->write(
			self::LOG_FILE,
			self::LOG_CATEGORY . '.' . $event,
			'HelloAi event',
			$context
		);
	}


	/**
	 * Sanitizes nested values before logging.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return mixed Sanitized value.
	 */
	private function sanitizeForLog(mixed $value): mixed {
		return SecretSanitizer::sanitize($value);
	}


}
