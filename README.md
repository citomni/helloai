# CitOmni HelloAi

`citomni/helloai` is a reusable CitOmni provider package for conversational AI integrations. It offers one compact, stable internal contract for chat-style requests and responses, so application code can work against a consistent structure rather than provider-specific JSON payloads. The package is intentionally narrow in scope: It is designed to be deterministic, explicit, and operationally useful, not a speculative "universal AI platform."

At its core, HelloAi separates application intent from provider transport. The application submits a normalized request, HelloAi resolves a profile, delegates provider translation to an adapter, performs the outbound HTTP call through the existing CitOmni cURL service, parses the provider response back into a common format, and optionally serves or stores the result through a database-backed cache. This design keeps the public surface area small while preserving room for provider-specific capabilities through explicit escape hatches.

## Highlights

- **Unified chat contract for CitOmni applications** with one compact internal request/response format across providers.
- **Profile-driven provider selection** so applications target stable profile ids rather than provider-specific payload logic.
- **Adapter-based translation layer** that keeps provider-specific JSON, headers, and response parsing out of application code.
- **Deterministic database-backed caching** keyed from the normalized request payload.
- **Operationally explicit logging and sanitization** with secret masking and useful execution context.
- **Reuse of the existing CitOmni cURL service** instead of introducing a parallel transport abstraction.
- **CLI integration out of the box** through `helloai:chat` for diagnostics, development, and scripting.

## Why HelloAi

Most chatbot APIs differ in naming, request shape, option semantics, authentication headers, and response envelopes. Those differences are operationally real, but they should not leak into every controller, command, or application service. HelloAi exists to absorb that variability behind a single internal request/response model and a profile-based configuration layer. Profiles select concrete adapters and models; adapters perform provider-specific translation; the application stays focused on intent. 

In practical terms, this yields four advantages:

- A single internal request format across providers.
- A single normalized response format for downstream application code.
- Explicit profile-driven configuration rather than implicit provider branching.
- Deterministic caching and logging without inventing a parallel transport stack. 

## Design principles

HelloAi follows a deliberately conservative architecture:

- **Profiles, not providers, are selected by the application.** A profile defines the adapter, model, endpoint, API credentials, and timeout policy.
- **Adapters own translation.** They build provider requests, headers, and response normalization.
- **The existing CitOmni cURL service remains the transport.** HelloAi does not introduce its own generic transport layer.
- **Validation is structural, not doctrinaire.** The package validates request shape and required fields, while leaving provider-specific extensibility available through explicit options.
- **Caching is deterministic.** Identical normalized requests map to identical cache keys.
- **Logging is explicit and sanitized.** Secrets are masked, but operationally relevant context remains available for debugging and observability. 

## Requirements

- PHP **8.2+**
- `ext-json`
- `citomni/kernel`
- `citomni/infrastructure`
- A CitOmni application with the existing cURL service available
- MySQL or MariaDB for the supplied cache table if response caching is enabled

OPcache is strongly recommended in production.

## Installation

```bash
composer require citomni/helloai
composer dump-autoload -o
````

Register the package provider in `config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\HelloAi\Boot\Registry::class,
];
```

If response caching is enabled, apply the package cache table to your application database.

Once registered, the package exposes the `helloAi` service and the `helloai:chat` CLI command.

## Public service

The primary entry point is the HelloAi service:

```php
$this->app->helloAi->chat(array $request): array
```

The service is responsible for:

1. Normalizing the internal request.
2. Resolving the selected profile.
3. Validating structural request shape.
4. Building a deterministic cache key.
5. Reading from cache before any external API call.
6. Instantiating the configured adapter.
7. Building the provider payload and headers.
8. Sending the HTTP request via `$this->app->curl->execute(...)`.
9. Parsing and normalizing the provider response.
10. Writing successful responses back to cache.
11. Returning the common response format.

## Internal request format

HelloAi uses a compact internal request model centered on `profile`, `messages`, `options`, `tools`, `provider_options`, and `debug`.

```php
[
	'profile' => 'claude-sonnet-4-6',
	'messages' => [
		[
			'role' => 'system',
			'content' => [
				['type' => 'text', 'text' => 'You are a precise assistant.'],
			],
		],
		[
			'role' => 'user',
			'content' => [
				['type' => 'text', 'text' => 'Write a short summary of this product.'],
			],
		],
	],
	'options' => [
		'temperature' => 0.7,
		'max_output_tokens' => 400,
	],
	'tools' => [],
	'provider_options' => [],
	'debug' => [
		'include_raw_response' => false,
		'include_built_request' => false,
	],
]
```

### Roles

The internal role vocabulary is:

* `system`
* `developer`
* `user`
* `assistant`
* `tool` 

### Content blocks

Messages use `content` blocks rather than a single `message` string. This keeps the internal contract structurally consistent and allows adapters to map content into provider-native payloads. Text blocks are the canonical baseline:

```php
[
	'role' => 'user',
	'content' => [
		['type' => 'text', 'text' => 'Hello'],
	],
]
```

### Options and provider-specific escape hatch

HelloAi does not impose a central whitelist over generic request options. The `options` array is intentionally open-ended. Adapters may consume the keys they support and ignore or reinterpret the rest according to explicit adapter logic.

Provider-specific deviations belong in `provider_options`, which acts as the sanctioned escape hatch for capabilities that do not fit the cross-provider abstraction cleanly. 

## Normalized response format

Adapters normalize provider responses into a common structure:

```php
[
	'profile' => 'claude-sonnet-4-6',
	'provider' => 'anthropic',
	'model' => 'claude-sonnet-4-6',
	'message' => [
		'role' => 'assistant',
		'content' => [
			['type' => 'text', 'text' => 'Here is your summary...'],
		],
	],
	'finish_reason' => 'end_turn',
	'usage' => [
		'input_tokens' => 123,
		'output_tokens' => 88,
		'total_tokens' => 211,
	],
	'tool_calls' => [],
	'raw' => null,
	'meta' => [
		'cached' => false,
		'cache_key' => '...',
		'duration_ms' => 123,
	],
]
```

This response model gives application code one stable place to read:

* the resolved profile,
* the provider name,
* the model name,
* the assistant message,
* the finish reason,
* token usage,
* tool calls,
* optional raw provider data,
* and operational metadata such as cache state and duration.

## Configuration

HelloAi is configured under the `helloai` node. The important concept is the **profile**: the application selects a profile, and the profile resolves to a concrete adapter and endpoint configuration.

```php
<?php
declare(strict_types=1);

return [
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

			'claude-sonnet-4-6' => [
				'adapter' => \CitOmni\HelloAi\Provider\Anthropic\AnthropicAdapter::class,
				'model' => 'claude-sonnet-4-6',
				'api_key' => '...',
				'base_url' => 'https://api.anthropic.com/v1/messages',
				'timeout' => 30,
			],
		],
	],
];
```

### Configuration semantics

* `default_profile` is used when the request does not provide one.
* Each profile points to an adapter class.
* Multiple profiles may reuse the same adapter.
* Each profile carries the concrete model, endpoint, credentials, and timeout.
* General transport defaults belong to the existing cURL configuration, not to a duplicated HelloAi transport subtree.

## Provider adapters

Adapters are the translation boundary between HelloAi's internal format and each provider's native API contract.

An adapter is responsible for:

* building the outbound provider request,
* building provider-specific headers,
* decoding the provider response body,
* normalizing the result into HelloAi's common response structure,
* and raising provider-appropriate exceptions on malformed or failed responses.

The adapter contract is intentionally small:

```php
interface AdapterInterface {
	public function buildRequest(array $request): array;
	public function buildHeaders(array $request): array;
	public function parseResponse(array $transportResult, array $request): array;
}
```

This keeps provider logic sharply localized and prevents transport, caching, or profile selection concerns from bleeding into adapter implementations.

## Transport model

HelloAi delegates HTTP execution to the existing CitOmni cURL service:

```php
$this->app->curl->execute(array $request): array
```

That service remains the transport authority. It validates transport request shape, performs the HTTP call, applies timeout and SSL behavior, and returns the raw transport result. HelloAi does not replace or duplicate that layer. Instead, it builds the cURL request array, delegates execution, and lets the adapter decode and interpret the response body. 

This separation is consequential:

* HelloAi does **not** centralize provider JSON parsing.
* Adapters decode provider JSON where appropriate.
* Transport exceptions remain transport exceptions.
* HelloAi may add context in logs, but it does not need a parallel transport abstraction to do so.

## Caching

HelloAi includes a database-backed cache accessed through `HelloAiCacheRepository`. Cache keys are built deterministically from the normalized request payload, specifically from:

* `profile`
* `messages`
* `options`
* `tools`
* `provider_options`

If those inputs are identical after normalization, the request is considered identical for caching purposes.

A typical cache entry contains:

* `cache_key`
* `profile`
* `model`
* `request_payload`
* `response_payload`
* `created_at`
* `expires_at`

The operational rule is simple:

* cache lookup happens before any external API call,
* successful normalized responses may be stored,
* invalid requests, provider failures, transport failures, and parse failures are not cached. 

## Logging and sanitization

HelloAi logs through the existing CitOmni log service. Logged context is designed to be operationally useful without casually exposing credentials.

The package sanitizes secrets such as:

* `api_key`
* `Authorization`
* `x-api-key`
* related authentication headers and tokens.

Useful HelloAi log context typically includes:

* profile,
* model,
* adapter class,
* cache hit or miss,
* cache key,
* duration,
* status code,
* sanitized request and response context,
* and exception details where relevant.

Because the underlying cURL service may also log, HelloAi should be used with a conscious logging policy to avoid redundant noise. 

## CLI usage

HelloAi also exposes a command-line entry point:

```bash
php bin/citomni helloai:chat "Hello. Who are you?" --profile="claude-haiku-4-5"
```

The command accepts the user prompt as a required argument and supports optional profile, system, developer, debug, JSON, temperature, and max-output-tokens flags. In plain mode it prints the assistant text and then a compact info line containing the resolved profile, provider, model, and cache status.

## Operational notes

### Profiles are the stable application contract

Application code should target profile ids rather than provider-specific endpoints or model payload formats directly. This keeps provider translation localized to adapters and configuration.

### Cache behavior is request-deterministic

Cache identity is derived from the normalized request payload. If `profile`, `messages`, `options`, `tools`, or `provider_options` differ, the request is treated as distinct for cache purposes.

### Transport remains delegated

HelloAi does not replace the CitOmni cURL service. Transport validation, HTTP execution, timeout handling, and low-level request mechanics remain delegated to the existing infrastructure layer.

### Logging should be explicit, not noisy

HelloAi logs package-level events such as cache hits/misses, adapter selection, sanitized request/response context, status information, and failures. Since the underlying cURL service may also log, production installations should choose a deliberate logging policy rather than accidentally generating duplicate transport noise.


## Package structure

The package is organized around a small set of clear responsibilities:

```text
src/
  Boot/
  Command/
  Exception/
  Interface/
  Provider/
  Repository/
  Service/
  Util/
```

A representative structure includes:

* `Boot/Registry.php` for service/config/CLI registration,
* `Service/HelloAi.php` as the primary orchestration service,
* `Interface/AdapterInterface.php` for adapter contracts,
* `Provider/...` for concrete provider adapters,
* `Repository/HelloAiCacheRepository.php` for cache persistence,
* `Util/CacheKeyBuilder.php` and `Util/SecretSanitizer.php` for focused helper logic,
* `Exception/...` for package-specific domain and adapter exceptions.

## Performance notes

- Services are resolved through explicit service maps rather than scanning.
- Provider translation is localized to small adapters with a narrow contract.
- Deterministic caching reduces repeated external API calls for identical normalized requests.
- The package reuses existing infrastructure services rather than layering a second transport stack on top.
- Production should use optimized Composer autoloading.
- OPcache should be enabled in production.

Composer example:

```json
{
	"config": {
		"optimize-autoloader": true,
		"classmap-authoritative": true,
		"apcu-autoloader": true
	}
}
````

Then run:

```bash
composer dump-autoload -o
```

## Error model

HelloAi defines its own domain exception family for request, profile, adapter, provider, and unsupported-feature failures. Transport failures from the underlying cURL service remain the responsibility of that service and are not arbitrarily rewrapped into a redundant transport hierarchy. This keeps fault boundaries explicit and preserves the original transport semantics.

## Error handling philosophy

Fail fast.

HelloAi does not hide malformed requests, profile misconfiguration, unsupported features, provider parse failures, or transport failures behind vague fallback behavior. Invalid structure should fail as invalid structure; provider-specific problems should fail as provider-specific problems; transport problems should remain transport problems.

That bias is deliberate. In integration code, silent fallback logic often looks convenient right up until it becomes the reason nobody can tell what actually happened.

## Example

```php
<?php
declare(strict_types=1);

$response = $this->app->helloAi->chat([
	'profile' => 'claude-sonnet-4-6',
	'messages' => [
		[
			'role' => 'system',
			'content' => [
				['type' => 'text', 'text' => 'You are a concise technical writer.'],
			],
		],
		[
			'role' => 'user',
			'content' => [
				['type' => 'text', 'text' => 'Explain cache invalidation in two paragraphs.'],
			],
		],
	],
	'options' => [
		'temperature' => 0.3,
		'max_output_tokens' => 300,
	],
]);

$text = $response['message']['content'][0]['text'] ?? '';
```

## Position within CitOmni

HelloAi follows the broader CitOmni philosophy: explicit contracts, deterministic behavior, small public surfaces, and low overhead. It does one thing narrowly but well: it gives CitOmni applications a disciplined and reusable way to talk to chatbot providers without forcing application code to speak every provider's dialect.

---

## Contributing

- PHP 8.2+
- PSR-4
- Tabs for indentation
- K&R brace style
- Keep ownership boundaries sharp
- Keep SQL in repositories, transport in controllers, orchestration in operations
- Do not introduce magic or hidden fallback behavior in auth-critical flows without an explicit and documented reason

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Authenticate** is open-source under the **MIT License**.
See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.
For details, see [NOTICE](NOTICE).

---

## Author

Developed by Lars Grove Mortensen © 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.