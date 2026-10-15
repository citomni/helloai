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

namespace CitOmni\HelloAi\Boot;

/**
 * Registry: Boot contributions of citomni/helloai.
 *
 * Declares what the package adds to a host app as constant arrays. Merging them with the host
 * app's own maps, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Services and cfg that work in both modes go in MAP_COMMON and CFG_COMMON. A mode-specific
 *   constant holds only values that depend on one runtime.
 * - Ship placeholders, never real API keys. Keys and app-specific profiles belong in the host
 *   app's cfg.
 */
final class Registry {

	/** Services for HTTP and CLI. */
	public const array MAP_COMMON = [
		'helloAi' => \CitOmni\HelloAi\Service\HelloAi::class,
	];

	/** Cfg baseline for HTTP and CLI. */
	public const array CFG_COMMON = [
		'helloai' => [
			'default_profile' => 'dev',

			'debug' => [
				'include_raw_response' => false,
				'include_built_request' => false,
			],

			'cache' => [
				'enabled' => true,
				'ttl' => 3600,
			],

			'profiles' => [
				'dev' => [
					'adapter' => \CitOmni\HelloAi\Provider\Dev\DevAdapter::class,
					'model' => 'dev',
					'api_key' => '',
					'base_url' => 'https://www.citomni.com/helloai/dev-endpoint.php',
					'timeout' => 30,
				],
				
				// Anthropic
				'claude-opus-4-6' => [
					'adapter' => \CitOmni\HelloAi\Provider\Anthropic\AnthropicAdapter::class,
					'model' => 'claude-opus-4-6',
					'api_key' => '...',
					'base_url' => 'https://api.anthropic.com/v1/messages',
					'timeout' => 60,
				],
				'claude-sonnet-4-6' => [
					'adapter' => \CitOmni\HelloAi\Provider\Anthropic\AnthropicAdapter::class,
					'model' => 'claude-sonnet-4-6',
					'api_key' => '...',
					'base_url' => 'https://api.anthropic.com/v1/messages',
					'timeout' => 60,
				],
				'claude-haiku-4-5' => [
					'adapter' => \CitOmni\HelloAi\Provider\Anthropic\AnthropicAdapter::class,
					'model' => 'claude-haiku-4-5',
					'api_key' => '...',
					'base_url' => 'https://api.anthropic.com/v1/messages',
					'timeout' => 60,
				],
				
				// OpenAI
				'gpt-5.4' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5.4',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5.4-mini' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5.4-mini',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5.4-nano' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5.4-nano',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-4.1' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-4.1',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-4.1-mini' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-4.1-mini',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5.1' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5.1',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5.1-mini' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5.1-mini',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5-mini' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5-mini',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-5-nano' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-5-nano',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'gpt-4.1-nano' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'gpt-4.1-nano',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'o4-mini' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'o4-mini',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				'o3' => [
					'adapter' => \CitOmni\HelloAi\Provider\OpenAi\OpenAiAdapter::class,
					'model' => 'o3',
					'api_key' => '...',
					'base_url' => 'https://api.openai.com/v1/responses',
					'timeout' => 30,
				],
				
			],
		],
	];

	/** HTTP routes. */
	public const array ROUTES_HTTP = [];

	/** CLI commands. */
	public const array COMMANDS_CLI = [
		'helloai:chat' => [
			'command' => \CitOmni\HelloAi\Command\ChatCommand::class,
			'description' => 'Send a chat message through a configured HelloAi profile',
		],
	];

}
