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

namespace CitOmni\HelloAi\Provider\Dev;

use CitOmni\HelloAi\Exception\ProviderResponseException;
use CitOmni\HelloAi\Interface\AdapterInterface;
use CitOmni\Kernel\App;

/**
 * Development-provider adapter for HelloAi.
 *
 * Translates normalized HelloAi requests into a simple dev payload,
 * builds standard JSON headers, and parses the Curl service response
 * back into the shared HelloAi response format.
 *
 * Behavior:
 * - Passes the internal request shape through as explicit dev payload.
 * - Builds standard JSON request headers for the dev endpoint.
 * - Decodes JSON returned by the dev endpoint and normalizes it to the shared HelloAi response format.
 * - Accepts either a fully normalized dev response or a simpler dev-specific response with text fields.
 *
 * Notes:
 * - This adapter is intentionally tolerant in response parsing because the dev endpoint contract
 *   is a local development aid, not a strict public provider schema.
 * - The HelloAi service owns outbound JSON encoding and transport dispatch.
 * - This adapter owns inbound JSON decoding for the provider response.
 *
 * Typical usage:
 *   $adapter = new DevAdapter($app, 'dev', $profileConfig);
 *   $providerRequest = $adapter->buildRequest($request);
 *   $headers = $adapter->buildHeaders($request);
 *   $response = $adapter->parseResponse($curlResponse, $request);
 */
final class DevAdapter implements AdapterInterface {

	private string $profileId;
	private array $profileConfig;



	/**
	 * Create a new dev adapter instance.
	 *
	 * @param App $app Application container.
	 * @param string $profileId Resolved profile id.
	 * @param array $profileConfig Resolved profile config.
	 */
	public function __construct(App $app, string $profileId, array $profileConfig) {
		unset($app);

		$this->profileId = $profileId;
		$this->profileConfig = $profileConfig;
	}





	// ----------------------------------------------------------------
	// Adapter interface
	// ----------------------------------------------------------------

	/**
	 * Build the provider request payload for the dev endpoint.
	 *
	 * @param array $request Normalized HelloAi request.
	 * @return array Provider request payload.
	 */
	public function buildRequest(array $request): array {
		return [
			'provider' => 'dev',
			'profile' => $this->profileId,
			'model' => (string)($this->profileConfig['model'] ?? 'dev'),
			'messages' => \is_array($request['messages'] ?? null) ? $request['messages'] : [],
			'options' => \is_array($request['options'] ?? null) ? $request['options'] : [],
			'tools' => \is_array($request['tools'] ?? null) ? $request['tools'] : [],
			'provider_options' => \is_array($request['provider_options'] ?? null) ? $request['provider_options'] : [],
			'debug' => \is_array($request['debug'] ?? null) ? $request['debug'] : [],
		];
	}


	/**
	 * Build provider headers for the dev endpoint.
	 *
	 * @param array $request Normalized HelloAi request.
	 * @return array Outbound header lines.
	 */
	public function buildHeaders(array $request): array {
		unset($request);

		return [
			'Accept: application/json',
			'Content-Type: application/json',
		];
	}


	/**
	 * Parse the response returned by the Curl service for the dev endpoint.
	 *
	 * Behavior:
	 * - Requires the response body to contain JSON on successful HTTP responses.
	 * - Throws on non-success HTTP status codes.
	 * - Accepts either:
	 *   1) A fully normalized HelloAi-style response shape, or
	 *   2) A simpler dev response shape with text/output_text/reply.
	 *
	 * @param array $transportResult Response returned by the Curl service.
	 * @param array $request Normalized HelloAi request.
	 * @return array Shared HelloAi response shape.
	 * @throws ProviderResponseException When the dev response cannot be parsed or is invalid.
	 */
	public function parseResponse(array $transportResult, array $request): array {

		// -- 1. Read the Curl response envelope -------------------------		
		$body = $transportResult['body'] ?? null;
		$isHttpSuccess = (bool)($transportResult['is_http_success'] ?? false);
		$statusCode = (int)($transportResult['status_code'] ?? 0);

		// -- 2. Validate the response body ------------------------------
		if (!\is_string($body) || $body === '') {
			if ($isHttpSuccess !== true) {
				throw new ProviderResponseException('Dev provider request failed with HTTP status ' . $statusCode . '.');
			}

			throw new ProviderResponseException('Dev provider response body must be a non-empty JSON string.');
		}

		// -- 3. Decode the provider payload -----------------------------
		try {
			$payload = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ProviderResponseException('Dev provider response body is not valid JSON.', 0, $e);
		}

		if (!\is_array($payload)) {
			throw new ProviderResponseException('Dev provider response JSON must decode to an array.');
		}

		// -- 4. Reject non-success HTTP responses -----------------------
		if ($isHttpSuccess !== true) {
			$message = $this->extractErrorMessage($payload, $transportResult);
			throw new ProviderResponseException($message);
		}

		// -- 5. Normalize the shared response ---------------------------
		$response = [
			'profile' => (string)($payload['profile'] ?? $this->profileId),
			'provider' => 'dev',
			'model' => (string)($payload['model'] ?? ($this->profileConfig['model'] ?? 'dev')),
			'message' => $this->normalizeAssistantMessage($payload),
			'finish_reason' => \is_string($payload['finish_reason'] ?? null) ? $payload['finish_reason'] : 'stop',
			'usage' => $this->normalizeUsage($payload['usage'] ?? null),
			'tool_calls' => \is_array($payload['tool_calls'] ?? null) ? $payload['tool_calls'] : [],
			'raw' => $this->shouldIncludeRaw($request) ? $payload : null,
			'meta' => [],
		];

		if ($this->shouldIncludeBuiltRequest($request)) {
			$response['meta']['built_request'] = $this->buildRequest($request);
		}

		return $response;
	}








	// ----------------------------------------------------------------
	// Response normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize the assistant message from the dev response payload.
	 *
	 * @param array $payload Decoded provider payload.
	 * @return array Normalized assistant message.
	 * @throws ProviderResponseException When no usable assistant text/message can be found.
	 */
	private function normalizeAssistantMessage(array $payload): array {
		if (\is_array($payload['message'] ?? null)) {
			$message = $payload['message'];
			$content = $this->normalizeContentBlocks($message['content'] ?? null);

			if ($content === []) {
				throw new ProviderResponseException('Dev provider response message.content must contain at least one valid block.');
			}

			return [
				'role' => 'assistant',
				'content' => $content,
			];
		}

		$text = $this->extractTextReply($payload);
		if ($text === null) {
			throw new ProviderResponseException(
				'Dev provider response must contain either a message array or a text/output_text/reply string.'
			);
		}

		return [
			'role' => 'assistant',
			'content' => [
				[
					'type' => 'text',
					'text' => $text,
				],
			],
		];
	}


	/**
	 * Normalize a usage payload.
	 *
	 * @param mixed $usage Raw usage payload.
	 * @return array Normalized usage structure.
	 */
	private function normalizeUsage(mixed $usage): array {
		if (!\is_array($usage)) {
			return [
				'input_tokens' => null,
				'output_tokens' => null,
				'total_tokens' => null,
			];
		}

		return [
			'input_tokens' => $this->normalizeNullableInt($usage['input_tokens'] ?? null),
			'output_tokens' => $this->normalizeNullableInt($usage['output_tokens'] ?? null),
			'total_tokens' => $this->normalizeNullableInt($usage['total_tokens'] ?? null),
		];
	}


	/**
	 * Normalize content blocks to the internal v1 text-block format.
	 *
	 * @param mixed $content Raw content payload.
	 * @return array Normalized content blocks.
	 */
	private function normalizeContentBlocks(mixed $content): array {
		if (!\is_array($content)) {
			return [];
		}

		$blocks = [];

		foreach ($content as $block) {
			if (!\is_array($block)) {
				continue;
			}

			$type = (string)($block['type'] ?? '');
			if ($type !== 'text') {
				continue;
			}

			$text = $block['text'] ?? null;
			if (!\is_string($text)) {
				continue;
			}

			$blocks[] = [
				'type' => 'text',
				'text' => $text,
			];
		}

		return $blocks;
	}


	/**
	 * Extract a plain text assistant reply from a dev payload.
	 *
	 * @param array $payload Decoded provider payload.
	 * @return string|null Extracted text, or null when not found.
	 */
	private function extractTextReply(array $payload): ?string {
		foreach (['text', 'output_text', 'reply'] as $key) {
			$value = $payload[$key] ?? null;
			if (\is_string($value) && $value !== '') {
				return $value;
			}
		}

		return null;
	}


	/**
	 * Extract a readable provider error message.
	 *
	 * @param array $payload Decoded provider payload.
	 * @param array $transportResult Response returned by the Curl service.
	 * @return string Error message.
	 */
	private function extractErrorMessage(array $payload, array $transportResult): string {
		$statusCode = (int)($transportResult['status_code'] ?? 0);

		if (\is_string($payload['error'] ?? null) && $payload['error'] !== '') {
			return 'Dev provider request failed: ' . $payload['error'];
		}

		if (\is_array($payload['error'] ?? null) && \is_string($payload['error']['message'] ?? null)) {
			return 'Dev provider request failed: ' . $payload['error']['message'];
		}

		if (\is_string($payload['message'] ?? null) && $payload['message'] !== '') {
			return 'Dev provider request failed: ' . $payload['message'];
		}

		return 'Dev provider request failed with HTTP status ' . $statusCode . '.';
	}


	/**
	 * Normalize a nullable integer-ish value.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null Normalized integer or null.
	 */
	private function normalizeNullableInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		if (\is_int($value)) {
			return $value;
		}

		if (\is_string($value) && $value !== '' && \ctype_digit($value)) {
			return (int)$value;
		}

		return null;
	}








	// ----------------------------------------------------------------
	// Debug helpers
	// ----------------------------------------------------------------

	/**
	 * Determine whether the raw decoded provider payload should be included.
	 *
	 * @param array $request Normalized HelloAi request.
	 * @return bool True when raw payload should be returned.
	 */
	private function shouldIncludeRaw(array $request): bool {
		return (bool)($request['debug']['include_raw_response'] ?? false);
	}


	/**
	 * Determine whether the built provider request should be included in meta.
	 *
	 * @param array $request Normalized HelloAi request.
	 * @return bool True when the built request should be exposed in meta.
	 */
	private function shouldIncludeBuiltRequest(array $request): bool {
		return (bool)($request['debug']['include_built_request'] ?? false);
	}

}
