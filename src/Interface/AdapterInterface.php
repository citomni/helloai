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

namespace CitOmni\HelloAi\Interface;

/**
 * Interface for provider-specific HelloAi adapters.
 *
 * An adapter translates the internal HelloAi request format into the
 * concrete provider request format, builds the required provider headers,
 * and parses the Curl service response back into the shared HelloAi
 * response format.
 *
 * Behavior:
 * - Input is always the normalized internal HelloAi request.
 * - Output from buildRequest() must be ready for JSON serialization by HelloAi.
 * - Output from buildHeaders() must be a plain header-line array for Curl use.
 * - Output from parseResponse() must follow the shared HelloAi response shape.
 *
 * Notes:
 * - Adapters must not handle cache, central logging, profile selection, fallback,
 *   retry, or other package-level orchestration.
 * - Provider-specific validation, JSON decoding, and provider-specific error
 *   interpretation belong in the adapter.
 *
 * Typical usage:
 *   $providerRequest = $adapter->buildRequest($request);
 *   $providerHeaders = $adapter->buildHeaders($request);
 *   $response = $adapter->parseResponse($curlResponse, $request);
 */
interface AdapterInterface {

	/**
	 * Builds the provider-specific request payload from the normalized internal request.
	 *
	 * @param array $request Normalized internal HelloAi request.
	 * @return array Provider-specific request payload.
	 */
	public function buildRequest(array $request): array;

	/**
	 * Builds the provider-specific HTTP headers for the outgoing request.
	 *
	 * @param array $request Normalized internal HelloAi request.
	 * @return array Provider-specific HTTP headers.
	 */
	public function buildHeaders(array $request): array;

	/**
	 * Parses the Curl service response into the shared HelloAi response format.
	 *
	 * Expected Curl response shape:
	 * [
	 *     'request' => [
	 *         'method' => 'POST',
	 *         'url' => 'https://...',
	 *     ],
	 *     'status_code' => 200,
	 *     'is_http_success' => true,
	 *     'headers_raw' => 'HTTP/1.1 200 OK ...',
	 *     'headers' => [
	 *         'content-type' => 'application/json',
	 *     ],
	 *     'body' => '{...raw json string...}',
	 *     'body_bytes' => 1234,
	 *     'effective_url' => 'https://...',
	 *     'content_type' => 'application/json',
	 *     'info' => [],
	 * ]
	 *
	 * Expected shared response shape:
	 * [
	 *     'profile' => 'gpt-5.1-mini',
	 *     'provider' => 'openai',
	 *     'model' => 'gpt-5.1-mini',
	 *     'message' => [
	 *         'role' => 'assistant',
	 *         'content' => [
	 *             ['type' => 'text', 'text' => '...'],
	 *         ],
	 *     ],
	 *     'finish_reason' => 'stop',
	 *     'usage' => [
	 *         'input_tokens' => 123,
	 *         'output_tokens' => 45,
	 *         'total_tokens' => 168,
	 *     ],
	 *     'tool_calls' => [],
	 *     'raw' => null,
	 *     'meta' => [],
	 * ]
	 *
	 * @param array $transportResult Response returned by the Curl service.
	 * @param array $request Normalized internal HelloAi request.
	 * @return array Shared HelloAi response.
	 */
	public function parseResponse(array $transportResult, array $request): array;
}
