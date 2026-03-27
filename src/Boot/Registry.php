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
 * Boot registry for the HelloAi provider package.
 *
 * Exposes the package-level service map, config baseline, HTTP routes,
 * and CLI commands consumed by the CitOmni boot pipeline.
 *
 * Behavior:
 * - Registers the public HelloAi service for both HTTP and CLI mode.
 * - Ships the package baseline config under the `helloai` root key.
 * - Provides no HTTP routes in v1.
 * - Provides no CLI commands in v1.
 *
 * Notes:
 * - Config and routes are merged with the standard CitOmni last-wins rules.
 * - Services are merged through the normal service-map union rules.
 * - Profile-specific secrets and app-level overrides belong in the host app config,
 *   not hardcoded here beyond a safe baseline.
 *
 * Typical usage:
 *   Discovered automatically by the CitOmni app boot process through the
 *   package provider registry mechanism.
 */
final class Registry {
	
	public const MAP_HTTP = [
		'helloAi' => \CitOmni\HelloAi\Service\HelloAi::class,
	];

	public const CFG_HTTP = [
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

	public const ROUTES_HTTP = [];




	public const MAP_CLI = self::MAP_HTTP;

	public const CFG_CLI = self::CFG_HTTP;

	public const COMMANDS_CLI = [
		'helloai:chat' => [
			'command' => \CitOmni\HelloAi\Command\ChatCommand::class,
			'description' => 'Send a chat message through a configured HelloAi profile',
		],
	];

}
