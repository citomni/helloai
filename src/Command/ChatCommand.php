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

namespace CitOmni\HelloAi\Command;

use CitOmni\Kernel\Command\BaseCommand;

final class ChatCommand extends BaseCommand {
	
	protected function signature(): array {
		return [
			'arguments' => [
				'prompt' => [
					'description' => 'User prompt to send',
					'required' => true,
				],
			],
			'options' => [
				'profile' => [
					'short' => 'p',
					'type' => 'string',
					'description' => 'HelloAi profile id',
					'default' => '',
				],
				'system' => [
					'short' => 's',
					'type' => 'string',
					'description' => 'Optional system message',
					'default' => '',
				],
				'developer' => [
					'short' => 'd',
					'type' => 'string',
					'description' => 'Optional developer message',
					'default' => '',
				],
				'raw' => [
					'short' => 'r',
					'type' => 'bool',
					'description' => 'Include raw provider response',
					'default' => false,
				],
				'built-request' => [
					'short' => 'b',
					'type' => 'bool',
					'description' => 'Include built provider request in meta',
					'default' => false,
				],
				'json' => [
					'short' => 'j',
					'type' => 'bool',
					'description' => 'Output the full normalized response as JSON',
					'default' => false,
				],
				'temperature' => [
					'short' => 't',
					'type' => 'string',
					'description' => 'Optional temperature value',
					'default' => '',
				],
				'max-output-tokens' => [
					'short' => 'm',
					'type' => 'int',
					'description' => 'Optional max output tokens',
					'default' => 0,
				],
			],
		];
	}

	protected function execute(): int {
		$prompt = $this->argString('prompt');
		$profile = $this->getString('profile');
		$system = $this->getString('system');
		$developer = $this->getString('developer');
		$json = $this->getBool('json');
		$raw = $this->getBool('raw');
		$builtRequest = $this->getBool('built-request');
		$temperature = $this->getString('temperature');
		$maxOutputTokens = $this->getInt('max-output-tokens');

		if ($maxOutputTokens < 0) {
			$this->error("--max-output-tokens must be >= 0, got {$maxOutputTokens}.");
			return self::FAILURE;
		}

		$request = [
			'messages' => [],
			'options' => [],
			'tools' => [],
			'provider_options' => [],
			'debug' => [
				'include_raw_response' => $raw,
				'include_built_request' => $builtRequest,
			],
		];

		if ($profile !== '') {
			$request['profile'] = $profile;
		}

		if ($system !== '') {
			$request['messages'][] = [
				'role' => 'system',
				'content' => [
					[
						'type' => 'text',
						'text' => $system,
					],
				],
			];
		}

		if ($developer !== '') {
			$request['messages'][] = [
				'role' => 'developer',
				'content' => [
					[
						'type' => 'text',
						'text' => $developer,
					],
				],
			];
		}

		$request['messages'][] = [
			'role' => 'user',
			'content' => [
				[
					'type' => 'text',
					'text' => $prompt,
				],
			],
		];

		if ($temperature !== '') {
			$request['options']['temperature'] = $temperature;
		}

		if ($maxOutputTokens > 0) {
			$request['options']['max_output_tokens'] = $maxOutputTokens;
		}


		try {
			$response = $this->app->helloAi->chat($request);
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		if ($json) {
			try {
				$this->stdout(\json_encode(
					$response,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
				));
			} catch (\JsonException $e) {
				$this->error('Failed to encode response as JSON: ' . $e->getMessage());
				return self::FAILURE;
			}

			return self::SUCCESS;
		}

		$text = $this->extractAssistantText($response);

		if ($text === null) {
			$this->warning('No assistant text block was found in the normalized response.');

			try {
				$this->stdout(\json_encode(
					$response,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
				));
			} catch (\JsonException $e) {
				$this->error('Failed to encode fallback response as JSON: ' . $e->getMessage());
				return self::FAILURE;
			}

			return self::SUCCESS;
		}

		$this->stdout($text);

		$resolvedProfile = (string)($response['profile'] ?? ($profile !== '' ? $profile : ''));
		$provider = (string)($response['provider'] ?? '');
		$model = (string)($response['model'] ?? '');
		$cached = (bool)($response['meta']['cached'] ?? false);

		$this->info(
			'profile=' . $resolvedProfile
			. ' provider=' . $provider
			. ' model=' . $model
			. ' cached=' . ($cached ? 'true' : 'false')
		);

		return self::SUCCESS;
	}

	private function extractAssistantText(array $response): ?string {
		$message = $response['message'] ?? null;
		if (!\is_array($message)) {
			return null;
		}

		$content = $message['content'] ?? null;
		if (!\is_array($content)) {
			return null;
		}

		$parts = [];

		foreach ($content as $block) {
			if (!\is_array($block)) {
				continue;
			}

			if (($block['type'] ?? null) !== 'text') {
				continue;
			}

			$text = $block['text'] ?? null;
			if (!\is_string($text) || $text === '') {
				continue;
			}

			$parts[] = $text;
		}

		if ($parts === []) {
			return null;
		}

		return \implode("\n\n", $parts);
	}
}
