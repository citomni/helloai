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

namespace CitOmni\HelloAi\Provider\OpenAi;

use CitOmni\HelloAi\Exception\ProviderRequestException;
use CitOmni\HelloAi\Exception\ProviderResponseException;
use CitOmni\HelloAi\Exception\UnsupportedFeatureException;
use CitOmni\HelloAi\Interface\AdapterInterface;
use CitOmni\Kernel\App;

final class OpenAiAdapter implements AdapterInterface {

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

		$payload = [
			'model' => $this->resolveModel(),
			'input' => $this->normalizeInputMessages($messages),
			'text' => [
				'format' => [
					'type' => 'text',
				],
			],
		];

		if ($tools !== []) {
			$payload['tools'] = $this->normalizeTools($tools);
		}

		$this->applyOptionalScalarOption($payload, 'temperature', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'top_p', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'top_logprobs', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'max_tool_calls', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'truncation', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'tool_choice', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'service_tier', $options, $providerOptions);
		$this->applyOptionalScalarOption($payload, 'previous_response_id', $options, $providerOptions);
		
		$this->applyOptionalScalarFromSource($payload, 'prompt_cache_key', $providerOptions);
		$this->applyOptionalScalarFromSource($payload, 'prompt_cache_retention', $providerOptions);
		$this->applyOptionalScalarFromSource($payload, 'safety_identifier', $providerOptions);
		$this->applyOptionalScalarFromSource($payload, 'conversation', $providerOptions);
		$this->applyOptionalScalarFromSource($payload, 'prompt', $providerOptions);

		$this->applyOptionalBoolOption($payload, 'store', $options, $providerOptions);
		$this->applyOptionalBoolOption($payload, 'parallel_tool_calls', $options, $providerOptions);
		$this->applyOptionalBoolFromSource($payload, 'background', $providerOptions);

		$this->applyOptionalArrayOption($payload, 'include', $providerOptions);
		$this->applyOptionalArrayOption($payload, 'metadata', $providerOptions);
		$this->applyOptionalArrayOption($payload, 'reasoning', $providerOptions);
		$this->applyOptionalArrayOption($payload, 'text', $providerOptions, $payload['text']);
		$this->applyOptionalArrayOption($payload, 'context_management', $providerOptions);

		$maxOutputTokens = $providerOptions['max_output_tokens'] ?? $options['max_output_tokens'] ?? null;
		if ($maxOutputTokens !== null) {
			$payload['max_output_tokens'] = $this->normalizePositiveInt($maxOutputTokens, 'OpenAI max_output_tokens');
		}

		$instructions = $providerOptions['instructions'] ?? null;
		if ($instructions !== null) {
			if (!\is_string($instructions) || $instructions === '') {
				throw new ProviderRequestException('OpenAI instructions must be a non-empty string when provided.');
			}
			$payload['instructions'] = $instructions;
		}

		if (($providerOptions['stream'] ?? false) === true || ($options['stream'] ?? false) === true) {
			throw new UnsupportedFeatureException('OpenAI streaming is not supported in HelloAi v1.');
		}

		return $payload;
	}

	public function buildHeaders(array $request): array {
		unset($request);

		$apiKey = (string)($this->profileConfig['api_key'] ?? '');
		if ($apiKey === '') {
			throw new ProviderRequestException(\sprintf(
				'OpenAI profile "%s" must define a non-empty api_key.',
				$this->profileId
			));
		}

		return [
			'Accept: application/json',
			'Content-Type: application/json',
			'Authorization: Bearer ' . $apiKey,
		];
	}

	public function parseResponse(array $transportResult, array $request): array {
		$body = $transportResult['body'] ?? null;
		$isHttpSuccess = (bool)($transportResult['is_http_success'] ?? false);
		$statusCode = (int)($transportResult['status_code'] ?? 0);

		if (!\is_string($body) || $body === '') {
			if ($isHttpSuccess !== true) {
				throw new ProviderResponseException(
					'OpenAI request failed with HTTP status ' . $statusCode . '.'
				);
			}

			throw new ProviderResponseException(
				'OpenAI response body must be a non-empty JSON string.'
			);
		}

		try {
			$payload = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ProviderResponseException(
				'OpenAI response body is not valid JSON.',
				0,
				$e
			);
		}

		if (!\is_array($payload)) {
			throw new ProviderResponseException(
				'OpenAI response JSON must decode to an array.'
			);
		}

		if ($isHttpSuccess !== true) {
			throw new ProviderResponseException($this->extractErrorMessage($payload, $statusCode));
		}

		if (\is_array($payload['error'] ?? null)) {
			throw new ProviderResponseException($this->extractErrorMessage($payload, $statusCode));
		}

		$toolCalls = $this->extractToolCalls($payload);

		return [
			'profile' => $this->profileId,
			'provider' => 'openai',
			'model' => (string)($payload['model'] ?? $this->resolveModel()),
			'message' => $this->normalizeAssistantMessage($payload),
			'finish_reason' => $this->normalizeFinishReason($payload, $toolCalls),
			'usage' => $this->normalizeUsage($payload['usage'] ?? null),
			'tool_calls' => $toolCalls,
			'raw' => $this->shouldIncludeRaw($request) ? $payload : null,
			'meta' => $this->buildMeta($payload),
		];
	}

	private function normalizeInputMessages(array $messages): array {
		if ($messages === []) {
			throw new ProviderRequestException('OpenAiAdapter requires at least one input message.');
		}

		$normalized = [];

		foreach ($messages as $messageIndex => $message) {
			if (!\is_array($message)) {
				throw new ProviderRequestException(\sprintf(
					'OpenAI request message at index %d must be an array.',
					$messageIndex
				));
			}

			$role = (string)($message['role'] ?? '');
			if ($role === 'tool') {
				throw new UnsupportedFeatureException(
					'OpenAiAdapter does not support internal "tool" input messages in v1.'
				);
			}

			if ($role !== 'system' && $role !== 'developer' && $role !== 'user' && $role !== 'assistant') {
				throw new ProviderRequestException(\sprintf(
					'OpenAiAdapter does not support message role "%s" at index %d.',
					$role,
					$messageIndex
				));
			}

			$normalized[] = [
				'type' => 'message',
				'role' => $role,
				'content' => $this->normalizeInputContentBlocks($message['content'] ?? null, $messageIndex),
			];
		}

		return $normalized;
	}

	private function normalizeInputContentBlocks(mixed $content, int $messageIndex): array {
		if (!\is_array($content)) {
			throw new ProviderRequestException(\sprintf(
				'OpenAI message content at index %d must be an array of content blocks.',
				$messageIndex
			));
		}

		$blocks = [];

		foreach ($content as $blockIndex => $block) {
			if (!\is_array($block)) {
				throw new ProviderRequestException(\sprintf(
					'OpenAI content block at message index %d and block index %d must be an array.',
					$messageIndex,
					$blockIndex
				));
			}

			$type = (string)($block['type'] ?? '');
			if ($type !== 'text') {
				throw new UnsupportedFeatureException(\sprintf(
					'OpenAiAdapter only supports internal text input blocks in v1. Unsupported type "%s" at message index %d and block index %d.',
					$type,
					$messageIndex,
					$blockIndex
				));
			}

			$text = $block['text'] ?? null;
			if (!\is_string($text)) {
				throw new ProviderRequestException(\sprintf(
					'OpenAI text block at message index %d and block index %d must contain a string text field.',
					$messageIndex,
					$blockIndex
				));
			}

			$blocks[] = [
				'type' => 'input_text',
				'text' => $text,
			];
		}

		if ($blocks === []) {
			throw new ProviderRequestException(\sprintf(
				'OpenAI message at index %d must contain at least one content block.',
				$messageIndex
			));
		}

		return $blocks;
	}

	private function normalizeTools(array $tools): array {
		$normalized = [];

		foreach ($tools as $toolIndex => $tool) {
			if (!\is_array($tool)) {
				throw new ProviderRequestException(\sprintf(
					'OpenAI tool definition at index %d must be an array.',
					$toolIndex
				));
			}

			$type = (string)($tool['type'] ?? '');
			if ($type === '') {
				throw new ProviderRequestException(\sprintf(
					'OpenAI tool definition at index %d must contain a non-empty string type.',
					$toolIndex
				));
			}

			$normalized[] = $tool;
		}

		return $normalized;
	}

	private function resolveModel(): string {
		$model = (string)($this->profileConfig['model'] ?? '');
		if ($model === '') {
			throw new ProviderRequestException(\sprintf(
				'OpenAI profile "%s" must define a non-empty model.',
				$this->profileId
			));
		}

		return $model;
	}

	private function applyOptionalScalarOption(array &$payload, string $key, array $options, array $providerOptions): void {
		$value = $providerOptions[$key] ?? $options[$key] ?? null;
		if ($value === null) {
			return;
		}
		$payload[$key] = $value;
	}

	private function applyOptionalScalarFromSource(array &$payload, string $key, array $source, mixed $fallback = null): void {
		$value = $source[$key] ?? $fallback;
		if ($value === null) {
			return;
		}
		$payload[$key] = $value;
	}

	private function applyOptionalBoolOption(array &$payload, string $key, array $options, array $providerOptions): void {
		$value = $providerOptions[$key] ?? $options[$key] ?? null;
		if (!\is_bool($value)) {
			return;
		}
		$payload[$key] = $value;
	}

	private function applyOptionalBoolFromSource(array &$payload, string $key, array $source, ?bool $fallback = null): void {
		$value = $source[$key] ?? $fallback;
		if (!\is_bool($value)) {
			return;
		}
		$payload[$key] = $value;
	}

	private function applyOptionalArrayOption(array &$payload, string $key, array $source, mixed $fallback = null): void {
		$value = $source[$key] ?? $fallback;
		if (!\is_array($value)) {
			return;
		}
		$payload[$key] = $value;
	}

	private function normalizeAssistantMessage(array $payload): array {
		$contentBlocks = $this->extractAssistantContentBlocks($payload);

		return [
			'role' => 'assistant',
			'content' => $contentBlocks,
		];
	}

	private function extractAssistantContentBlocks(array $payload): array {
		$output = $payload['output'] ?? null;
		if (!\is_array($output)) {
			$text = $this->extractTopLevelOutputText($payload);
			if ($text === null) {
				return [];
			}

			return [
				[
					'type' => 'text',
					'text' => $text,
				],
			];
		}

		$blocks = [];

		foreach ($output as $item) {
			if (!\is_array($item)) {
				continue;
			}

			if (($item['type'] ?? null) !== 'message') {
				continue;
			}

			if (($item['role'] ?? null) !== 'assistant') {
				continue;
			}

			$content = $item['content'] ?? null;
			if (!\is_array($content)) {
				continue;
			}

			foreach ($content as $contentItem) {
				if (!\is_array($contentItem)) {
					continue;
				}

				$type = (string)($contentItem['type'] ?? '');

				if ($type === 'output_text') {
					$text = $contentItem['text'] ?? null;
					if (\is_string($text) && $text !== '') {
						$blocks[] = [
							'type' => 'text',
							'text' => $text,
						];
					}
					continue;
				}

				if ($type === 'refusal') {
					$refusal = $contentItem['refusal'] ?? null;
					if (\is_string($refusal) && $refusal !== '') {
						$blocks[] = [
							'type' => 'text',
							'text' => $refusal,
						];
					}
				}
			}
		}

		if ($blocks !== []) {
			return $blocks;
		}

		$text = $this->extractTopLevelOutputText($payload);
		if ($text === null) {
			return [];
		}

		return [
			[
				'type' => 'text',
				'text' => $text,
			],
		];
	}

	private function extractTopLevelOutputText(array $payload): ?string {
		$value = $payload['output_text'] ?? null;
		if (\is_string($value) && $value !== '') {
			return $value;
		}

		return null;
	}

	private function extractToolCalls(array $payload): array {
		$output = $payload['output'] ?? null;
		if (!\is_array($output)) {
			return [];
		}

		$toolCalls = [];

		foreach ($output as $item) {
			if (!\is_array($item)) {
				continue;
			}

			$type = (string)($item['type'] ?? '');

			if ($type === 'function_call') {
				$toolCalls[] = [
					'id' => \is_string($item['id'] ?? null) ? $item['id'] : null,
					'call_id' => \is_string($item['call_id'] ?? null) ? $item['call_id'] : null,
					'type' => 'function',
					'name' => \is_string($item['name'] ?? null) ? $item['name'] : '',
					'arguments' => $this->normalizeFunctionArguments($item['arguments'] ?? null),
					'status' => \is_string($item['status'] ?? null) ? $item['status'] : null,
					'raw' => $item,
				];
				continue;
			}

			if ($type === 'custom_tool_call') {
				$toolCalls[] = [
					'id' => \is_string($item['id'] ?? null) ? $item['id'] : null,
					'call_id' => \is_string($item['call_id'] ?? null) ? $item['call_id'] : null,
					'type' => 'custom',
					'name' => \is_string($item['name'] ?? null) ? $item['name'] : '',
					'arguments' => $item['input'] ?? null,
					'status' => \is_string($item['status'] ?? null) ? $item['status'] : null,
					'raw' => $item,
				];
				continue;
			}

			if ($this->isHostedToolCallType($type)) {
				$toolCalls[] = [
					'id' => \is_string($item['id'] ?? null) ? $item['id'] : null,
					'call_id' => \is_string($item['call_id'] ?? null) ? $item['call_id'] : null,
					'type' => $type,
					'name' => $type,
					'arguments' => null,
					'status' => \is_string($item['status'] ?? null) ? $item['status'] : null,
					'raw' => $item,
				];
			}
		}

		return $toolCalls;
	}

	private function isHostedToolCallType(string $type): bool {
		return isset([
			'file_search_call' => true,
			'web_search_call' => true,
			'computer_call' => true,
			'code_interpreter_call' => true,
			'image_generation_call' => true,
			'tool_search_call' => true,
			'local_shell_call' => true,
			'shell_call' => true,
			'apply_patch_call' => true,
			'mcp_call' => true,
		][$type]);
	}

	private function normalizeFunctionArguments(mixed $arguments): mixed {
		if (\is_array($arguments)) {
			return $arguments;
		}

		if (!\is_string($arguments) || $arguments === '') {
			return null;
		}

		try {
			$decoded = \json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return $arguments;
		}

		return $decoded;
	}

	private function normalizeFinishReason(array $payload, array $toolCalls): ?string {
		$incompleteReason = $payload['incomplete_details']['reason'] ?? null;
		if (\is_string($incompleteReason) && $incompleteReason !== '') {
			return $incompleteReason;
		}

		if ($toolCalls !== []) {
			return 'tool_calls';
		}

		$status = $payload['status'] ?? null;
		if ($status === 'completed') {
			return 'stop';
		}
		if (\is_string($status) && $status !== '') {
			return $status;
		}

		return null;
	}

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

	private function buildMeta(array $payload): array {
		$meta = [];

		if (\is_string($payload['id'] ?? null) && $payload['id'] !== '') {
			$meta['response_id'] = $payload['id'];
		}

		if (\is_string($payload['status'] ?? null) && $payload['status'] !== '') {
			$meta['status'] = $payload['status'];
		}

		if (isset($payload['created_at'])) {
			$meta['created_at'] = $payload['created_at'];
		}

		if (isset($payload['completed_at'])) {
			$meta['completed_at'] = $payload['completed_at'];
		}

		if (\is_string($payload['service_tier'] ?? null) && $payload['service_tier'] !== '') {
			$meta['service_tier'] = $payload['service_tier'];
		}

		if (\is_array($payload['incomplete_details'] ?? null)) {
			$meta['incomplete_details'] = $payload['incomplete_details'];
		}

		return $meta;
	}

	private function extractErrorMessage(array $payload, int $statusCode): string {
		if (\is_array($payload['error'] ?? null) && \is_string($payload['error']['message'] ?? null)) {
			return 'OpenAI request failed: ' . $payload['error']['message'];
		}

		if (\is_string($payload['message'] ?? null) && $payload['message'] !== '') {
			return 'OpenAI request failed: ' . $payload['message'];
		}

		return 'OpenAI request failed with HTTP status ' . $statusCode . '.';
	}

	private function normalizePositiveInt(mixed $value, string $label): int {
		if (\is_int($value) && $value > 0) {
			return $value;
		}

		if (\is_string($value) && $value !== '' && \ctype_digit($value)) {
			$normalized = (int)$value;
			if ($normalized > 0) {
				return $normalized;
			}
		}

		throw new ProviderRequestException($label . ' must be a positive integer.');
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
