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

namespace CitOmni\HelloAi\Provider\Anthropic;

use CitOmni\HelloAi\Exception\ProviderRequestException;
use CitOmni\HelloAi\Exception\ProviderResponseException;
use CitOmni\HelloAi\Exception\UnsupportedFeatureException;
use CitOmni\HelloAi\Interface\AdapterInterface;
use CitOmni\Kernel\App;

/**
 * Translate HelloAi requests to Anthropic Messages API payloads.
 *
 * Behavior:
 * - Maps the internal HelloAi request format to Anthropic's /v1/messages payload.
 * - Collapses internal "system" and "developer" roles into Anthropic top-level "system".
 * - Parses Anthropic JSON responses back into the shared HelloAi response format.
 *
 * Notes:
 * - Anthropic Messages API has no "system" role inside "messages"; system prompt is top-level.
 * - This means internal "developer" is intentionally merged into top-level "system" in v1.
 * - Internal "tool" input role is not supported in v1.
 * - Tool definitions may be forwarded, but tool execution orchestration is outside this adapter.
 */
final class AnthropicAdapter implements AdapterInterface {

	private const ANTHROPIC_VERSION = '2023-06-01';

	private string $profileId;
	private array $profileConfig;

	public function __construct(App $app, string $profileId, array $profileConfig) {
		unset($app);

		$this->profileId = $profileId;
		$this->profileConfig = $profileConfig;
	}

	public function buildRequest(array $request): array {
		$options = \is_array($request['options'] ?? null) ? $request['options'] : [];
		$providerOptions = \is_array($request['provider_options'] ?? null) ? $request['provider_options'] : [];
		$messages = \is_array($request['messages'] ?? null) ? $request['messages'] : [];
		$tools = \is_array($request['tools'] ?? null) ? $request['tools'] : [];

		$systemBlocks = [];
		$anthropicMessages = [];

		foreach ($messages as $messageIndex => $message) {
			if (!\is_array($message)) {
				throw new ProviderRequestException(\sprintf(
					'Anthropic request message at index %d must be an array.',
					$messageIndex
				));
			}

			$role = (string)($message['role'] ?? '');
			$content = $this->normalizeInputContentBlocks($message['content'] ?? null, $messageIndex);

			if ($role === 'system' || $role === 'developer') {
				foreach ($content as $block) {
					$systemBlocks[] = $block;
				}
				continue;
			}

			if ($role === 'tool') {
				throw new UnsupportedFeatureException(
					'AnthropicAdapter does not support internal "tool" input messages in v1.'
				);
			}

			if ($role !== 'user' && $role !== 'assistant') {
				throw new ProviderRequestException(\sprintf(
					'AnthropicAdapter does not support message role "%s" at index %d.',
					$role,
					$messageIndex
				));
			}

			$anthropicMessages[] = [
				'role' => $role,
				'content' => $content,
			];
		}

		if ($anthropicMessages === []) {
			throw new ProviderRequestException(
				'AnthropicAdapter requires at least one user or assistant message after role mapping.'
			);
		}

		$payload = [
			'model' => $this->resolveModel(),
			'max_tokens' => $this->resolveMaxTokens($options, $providerOptions),
			'messages' => $anthropicMessages,
		];

		if ($systemBlocks !== []) {
			$payload['system'] = $systemBlocks;
		}

		if ($tools !== []) {
			$payload['tools'] = $this->normalizeTools($tools);
		}

		$this->applyOptionalScalarOption($payload, 'temperature', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'top_p', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'top_k', $options, $providerOptions);
		$this->applyOptionalArrayOption($payload, 'stop_sequences', $options, $providerOptions);

		$this->applyOptionalMixedOption($payload, 'metadata', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'service_tier', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'thinking', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'tool_choice', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'output_config', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'container', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'inference_geo', $providerOptions);
		$this->applyOptionalMixedOption($payload, 'cache_control', $providerOptions);

		if (($providerOptions['stream'] ?? false) === true || ($options['stream'] ?? false) === true) {
			throw new UnsupportedFeatureException('Anthropic streaming is not supported in HelloAi v1.');
		}

		return $payload;
	}

	public function buildHeaders(array $request): array {
		unset($request);

		$apiKey = (string)($this->profileConfig['api_key'] ?? '');
		if ($apiKey === '') {
			throw new ProviderRequestException(\sprintf(
				'Anthropic profile "%s" must define a non-empty api_key.',
				$this->profileId
			));
		}

		return [
			'Accept: application/json',
			'Content-Type: application/json',
			'anthropic-version: ' . self::ANTHROPIC_VERSION,
			'x-api-key: ' . $apiKey,
		];
	}

	public function parseResponse(array $transportResult, array $request): array {
		$body = $transportResult['body'] ?? null;
		$isHttpSuccess = (bool)($transportResult['is_http_success'] ?? false);
		$statusCode = (int)($transportResult['status_code'] ?? 0);

		if (!\is_string($body) || $body === '') {
			if ($isHttpSuccess !== true) {
				throw new ProviderResponseException(
					'Anthropic request failed with HTTP status ' . $statusCode . '.'
				);
			}

			throw new ProviderResponseException(
				'Anthropic response body must be a non-empty JSON string.'
			);
		}

		try {
			$payload = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ProviderResponseException(
				'Anthropic response body is not valid JSON.',
				0,
				$e
			);
		}

		if (!\is_array($payload)) {
			throw new ProviderResponseException(
				'Anthropic response JSON must decode to an array.'
			);
		}

		if ($isHttpSuccess !== true) {
			throw new ProviderResponseException($this->extractErrorMessage($payload, $statusCode));
		}

		$toolCalls = $this->extractToolCalls($payload);

		$response = [
			'profile' => $this->profileId,
			'provider' => 'anthropic',
			'model' => (string)($payload['model'] ?? $this->resolveModel()),
			'message' => $this->normalizeAssistantMessage($payload, $toolCalls),
			'finish_reason' => $this->normalizeFinishReason($payload),
			'usage' => $this->normalizeUsage($payload['usage'] ?? null),
			'tool_calls' => $toolCalls,
			'raw' => $this->shouldIncludeRaw($request) ? $payload : null,
			'meta' => $this->buildMeta($payload),
		];

		return $response;
	}

	/**
	 * Normalize internal content blocks to Anthropic input content blocks.
	 *
	 * @param mixed $content Raw message content.
	 * @param int $messageIndex Message index for precise exception messages.
	 * @return array<int, array<string, mixed>> Normalized content blocks.
	 */
	private function normalizeInputContentBlocks(mixed $content, int $messageIndex): array {
		if (!\is_array($content)) {
			throw new ProviderRequestException(\sprintf(
				'Anthropic message content at index %d must be an array of content blocks.',
				$messageIndex
			));
		}

		$blocks = [];

		foreach ($content as $blockIndex => $block) {
			if (!\is_array($block)) {
				throw new ProviderRequestException(\sprintf(
					'Anthropic content block at message index %d and block index %d must be an array.',
					$messageIndex,
					$blockIndex
				));
			}

			$type = (string)($block['type'] ?? '');
			if ($type !== 'text') {
				throw new UnsupportedFeatureException(\sprintf(
					'AnthropicAdapter only supports text input blocks in v1. Unsupported type "%s" at message index %d and block index %d.',
					$type,
					$messageIndex,
					$blockIndex
				));
			}

			$text = $block['text'] ?? null;
			if (!\is_string($text)) {
				throw new ProviderRequestException(\sprintf(
					'Anthropic text block at message index %d and block index %d must contain a string text field.',
					$messageIndex,
					$blockIndex
				));
			}

			$normalizedBlock = [
				'type' => 'text',
				'text' => $text,
			];

			if (isset($block['cache_control']) && \is_array($block['cache_control'])) {
				$normalizedBlock['cache_control'] = $block['cache_control'];
			}

			if (isset($block['citations']) && \is_array($block['citations'])) {
				$normalizedBlock['citations'] = $block['citations'];
			}

			$blocks[] = $normalizedBlock;
		}

		if ($blocks === []) {
			throw new ProviderRequestException(\sprintf(
				'Anthropic message at index %d must contain at least one content block.',
				$messageIndex
			));
		}

		return $blocks;
	}

	/**
	 * Normalize HelloAi tool definitions for Anthropic.
	 *
	 * @param array $tools Internal HelloAi tools array.
	 * @return array<int, array<string, mixed>> Forwardable Anthropic tools.
	 */
	private function normalizeTools(array $tools): array {
		$normalized = [];

		foreach ($tools as $toolIndex => $tool) {
			if (!\is_array($tool)) {
				throw new ProviderRequestException(\sprintf(
					'Anthropic tool definition at index %d must be an array.',
					$toolIndex
				));
			}

			$name = $tool['name'] ?? null;
			if (!\is_string($name) || $name === '') {
				throw new ProviderRequestException(\sprintf(
					'Anthropic tool definition at index %d must contain a non-empty string name.',
					$toolIndex
				));
			}

			$inputSchema = $tool['input_schema'] ?? null;
			if (!\is_array($inputSchema)) {
				throw new ProviderRequestException(\sprintf(
					'Anthropic tool definition "%s" must contain an input_schema array.',
					$name
				));
			}

			$normalizedTool = [
				'name' => $name,
				'input_schema' => $inputSchema,
			];

			if (isset($tool['description']) && \is_string($tool['description'])) {
				$normalizedTool['description'] = $tool['description'];
			}

			if (isset($tool['cache_control']) && \is_array($tool['cache_control'])) {
				$normalizedTool['cache_control'] = $tool['cache_control'];
			}

			if (isset($tool['strict']) && \is_bool($tool['strict'])) {
				$normalizedTool['strict'] = $tool['strict'];
			}

			if (isset($tool['type']) && \is_string($tool['type']) && $tool['type'] !== '') {
				$normalizedTool['type'] = $tool['type'];
			}

			$normalized[] = $normalizedTool;
		}

		return $normalized;
	}

	private function resolveModel(): string {
		$model = (string)($this->profileConfig['model'] ?? '');
		if ($model === '') {
			throw new ProviderRequestException(\sprintf(
				'Anthropic profile "%s" must define a non-empty model.',
				$this->profileId
			));
		}

		return $model;
	}

	private function resolveMaxTokens(array $options, array $providerOptions): int {
		$value = $providerOptions['max_tokens'] ?? $options['max_output_tokens'] ?? null;

		if ($value === null) {
			return 1024;
		}

		$normalized = $this->normalizePositiveInt($value);
		if ($normalized === null) {
			throw new ProviderRequestException(
				'Anthropic max_tokens must be a positive integer.'
			);
		}

		return $normalized;
	}

	private function applyOptionalScalarOption(array &$payload, string $key, array $options, array $providerOptions): void {
		$value = $providerOptions[$key] ?? $options[$key] ?? null;
		if ($value === null) {
			return;
		}

		$payload[$key] = $value;
	}

	private function applyOptionalArrayOption(array &$payload, string $key, array $options, array $providerOptions): void {
		$value = $providerOptions[$key] ?? $options[$key] ?? null;
		if ($value === null) {
			return;
		}

		if (!\is_array($value)) {
			throw new ProviderRequestException(\sprintf(
				'Anthropic option "%s" must be an array when provided.',
				$key
			));
		}

		$payload[$key] = $value;
	}

	private function applyOptionalMixedOption(array &$payload, string $key, array $providerOptions): void {
		if (!\array_key_exists($key, $providerOptions)) {
			return;
		}

		$payload[$key] = $providerOptions[$key];
	}

	private function normalizeAssistantMessage(array $payload, array $toolCalls): array {
		$content = $payload['content'] ?? null;
		if (!\is_array($content)) {
			throw new ProviderResponseException(
				'Anthropic response content must be an array.'
			);
		}

		$textBlocks = [];

		foreach ($content as $blockIndex => $block) {
			if (!\is_array($block)) {
				continue;
			}

			$type = (string)($block['type'] ?? '');
			if ($type !== 'text') {
				continue;
			}

			$text = $block['text'] ?? null;
			if (!\is_string($text)) {
				throw new ProviderResponseException(\sprintf(
					'Anthropic text content block at index %d must contain a string text field.',
					$blockIndex
				));
			}

			$normalizedBlock = [
				'type' => 'text',
				'text' => $text,
			];

			if (isset($block['citations']) && \is_array($block['citations'])) {
				$normalizedBlock['citations'] = $block['citations'];
			}

			$textBlocks[] = $normalizedBlock;
		}

		if ($textBlocks === [] && $toolCalls === []) {
			throw new ProviderResponseException(
				'Anthropic response content did not contain any supported text blocks.'
			);
		}

		return [
			'role' => 'assistant',
			'content' => $textBlocks,
		];
	}

	private function extractToolCalls(array $payload): array {
		$content = $payload['content'] ?? null;
		if (!\is_array($content)) {
			return [];
		}

		$toolCalls = [];

		foreach ($content as $block) {
			if (!\is_array($block)) {
				continue;
			}

			if (($block['type'] ?? null) !== 'tool_use') {
				continue;
			}

			$id = $block['id'] ?? null;
			$name = $block['name'] ?? null;
			$input = $block['input'] ?? null;

			if (!\is_string($id) || $id === '' || !\is_string($name) || $name === '') {
				continue;
			}

			$toolCalls[] = [
				'id' => $id,
				'type' => 'tool_use',
				'name' => $name,
				'input' => \is_array($input) ? $input : [],
			];
		}

		return $toolCalls;
	}

	private function normalizeFinishReason(array $payload): ?string {
		$stopReason = $payload['stop_reason'] ?? null;
		return \is_string($stopReason) && $stopReason !== '' ? $stopReason : null;
	}

	private function normalizeUsage(mixed $usage): array {
		if (!\is_array($usage)) {
			return [
				'input_tokens' => null,
				'output_tokens' => null,
				'total_tokens' => null,
			];
		}

		$inputTokens = $this->normalizeNullableInt($usage['input_tokens'] ?? null);
		$outputTokens = $this->normalizeNullableInt($usage['output_tokens'] ?? null);
		$cacheCreationInputTokens = $this->normalizeNullableInt($usage['cache_creation_input_tokens'] ?? null);
		$cacheReadInputTokens = $this->normalizeNullableInt($usage['cache_read_input_tokens'] ?? null);

		$totalTokens = null;
		if ($inputTokens !== null || $outputTokens !== null || $cacheCreationInputTokens !== null || $cacheReadInputTokens !== null) {
			$totalTokens = (int)(
				($inputTokens ?? 0)
				+ ($cacheCreationInputTokens ?? 0)
				+ ($cacheReadInputTokens ?? 0)
				+ ($outputTokens ?? 0)
			);
		}

		return [
			'input_tokens' => $inputTokens,
			'output_tokens' => $outputTokens,
			'total_tokens' => $totalTokens,
		];
	}

	private function buildMeta(array $payload): array {
		$meta = [];

		if (($payload['stop_sequence'] ?? null) !== null) {
			$meta['stop_sequence'] = $payload['stop_sequence'];
		}

		if (isset($payload['id']) && \is_string($payload['id']) && $payload['id'] !== '') {
			$meta['provider_message_id'] = $payload['id'];
		}

		if (isset($payload['type']) && \is_string($payload['type']) && $payload['type'] !== '') {
			$meta['provider_type'] = $payload['type'];
		}

		if (\is_array($payload['usage'] ?? null)) {
			$usage = $payload['usage'];

			$cacheCreationInputTokens = $this->normalizeNullableInt($usage['cache_creation_input_tokens'] ?? null);
			$cacheReadInputTokens = $this->normalizeNullableInt($usage['cache_read_input_tokens'] ?? null);

			if ($cacheCreationInputTokens !== null) {
				$meta['usage_cache_creation_input_tokens'] = $cacheCreationInputTokens;
			}

			if ($cacheReadInputTokens !== null) {
				$meta['usage_cache_read_input_tokens'] = $cacheReadInputTokens;
			}

			if (isset($usage['cache_creation']) && \is_array($usage['cache_creation'])) {
				$meta['usage_cache_creation'] = $usage['cache_creation'];
			}

			if (isset($usage['server_tool_use']) && \is_array($usage['server_tool_use'])) {
				$meta['usage_server_tool_use'] = $usage['server_tool_use'];
			}

			if (isset($usage['service_tier']) && \is_string($usage['service_tier']) && $usage['service_tier'] !== '') {
				$meta['usage_service_tier'] = $usage['service_tier'];
			}

			if (isset($usage['inference_geo']) && \is_string($usage['inference_geo']) && $usage['inference_geo'] !== '') {
				$meta['usage_inference_geo'] = $usage['inference_geo'];
			}
		}

		return $meta;
	}

	private function extractErrorMessage(array $payload, int $statusCode): string {
		if (\is_array($payload['error'] ?? null)) {
			$error = $payload['error'];

			if (\is_string($error['message'] ?? null) && $error['message'] !== '') {
				return 'Anthropic request failed: ' . $error['message'];
			}

			if (\is_string($error['type'] ?? null) && $error['type'] !== '') {
				return 'Anthropic request failed: ' . $error['type'];
			}
		}

		if (\is_string($payload['error'] ?? null) && $payload['error'] !== '') {
			return 'Anthropic request failed: ' . $payload['error'];
		}

		return 'Anthropic request failed with HTTP status ' . $statusCode . '.';
	}

	private function normalizePositiveInt(mixed $value): ?int {
		if (\is_int($value)) {
			return $value > 0 ? $value : null;
		}

		if (\is_string($value) && $value !== '' && \ctype_digit($value)) {
			$intValue = (int)$value;
			return $intValue > 0 ? $intValue : null;
		}

		return null;
	}

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

	private function shouldIncludeRaw(array $request): bool {
		return (bool)($request['debug']['include_raw_response'] ?? false);
	}
}
