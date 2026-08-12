<?php
/* Copyright (C) 2026 AuVo Design
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    auvoshopware/class/ShopwareClient.class.php
 * \ingroup auvoshopware
 * \brief   Thin HTTP client for the Shopware 6 Admin API (OAuth2 client-credentials, REST/JSON).
 *
 * Endpoint shapes below are from Shopware's own Admin API documentation (token endpoint body
 * format, generic entity CRUD routes, /api/_action/sync payload shape) - not yet confirmed
 * against this project's own shop instance with a live request. The first real call made
 * through this class (the "Test connection" button on the module's setup page) is the live
 * verification step; adjust this file if the shop's actual responses differ from what's
 * assumed here (see PLAN.md "Hintergrundwissen" on always verifying API assumptions live).
 */
class ShopwareClient
{
	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var string Base URL of the Shopware shop, no trailing slash
	 */
	protected $baseUrl;

	/**
	 * @var string OAuth2 client id (Shopware calls this the integration's "Access ID")
	 */
	protected $clientId;

	/**
	 * @var string OAuth2 client secret ("Secret Access Key")
	 */
	protected $clientSecret;

	/**
	 * @var string|null Cached bearer token for the lifetime of this instance
	 */
	protected $accessToken;

	/**
	 * @var int Unix timestamp when the cached token expires
	 */
	protected $accessTokenExpiresAt = 0;

	/**
	 * Path (relative to base URL) of the OAuth2 token endpoint.
	 *
	 * @var string
	 */
	protected $tokenEndpoint = '/api/oauth/token';

	/**
	 * Path (relative to base URL) of the API resource root.
	 *
	 * @var string
	 */
	protected $apiBase = '/api';

	/**
	 * Constructor
	 *
	 * @param string $baseUrl      Base URL of the shop (e.g. https://my-shop.example.com)
	 * @param string $clientId     OAuth2 client id (Integration's Access ID)
	 * @param string $clientSecret OAuth2 client secret (Integration's Secret Access Key)
	 */
	public function __construct($baseUrl, $clientId, $clientSecret)
	{
		$this->baseUrl = rtrim((string) $baseUrl, '/');
		$this->clientId = (string) $clientId;
		$this->clientSecret = (string) $clientSecret;
	}

	/**
	 * Get a valid bearer token, requesting a new one if needed.
	 *
	 * Unlike PrestaShop's Admin API (HTTP Basic auth at the token endpoint), Shopware expects
	 * client_id/client_secret as JSON fields in the token request body.
	 *
	 * @return string|false
	 */
	protected function getAccessToken()
	{
		if ($this->accessToken && $this->accessTokenExpiresAt > (time() + 5)) {
			return $this->accessToken;
		}

		if (empty($this->baseUrl) || empty($this->clientId) || empty($this->clientSecret)) {
			$this->error = 'Missing Shopware URL, client id or client secret';
			return false;
		}

		$ch = curl_init($this->baseUrl.$this->tokenEndpoint);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Accept: application/json',
		));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'grant_type' => 'client_credentials',
			'client_id' => $this->clientId,
			'client_secret' => $this->clientSecret,
		)));

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			$this->error = 'cURL error while fetching access token: '.$curlError;
			return false;
		}

		$data = json_decode($response, true);
		if ($httpCode < 200 || $httpCode >= 300 || empty($data['access_token'])) {
			$this->error = 'Failed to fetch access token (HTTP '.$httpCode.'): '.$response;
			return false;
		}

		$this->accessToken = $data['access_token'];
		$this->accessTokenExpiresAt = time() + (int) ($data['expires_in'] ?? 600);

		return $this->accessToken;
	}

	/**
	 * Perform an authenticated JSON request against the Shopware Admin API.
	 *
	 * @param string     $method   HTTP method (GET, POST, PATCH, DELETE)
	 * @param string     $resource Resource path relative to the API base, e.g. 'product' or 'product/{id}'
	 * @param array|null $data     Payload to send as JSON (for POST/PATCH)
	 * @param array|null $query    Query string parameters
	 * @return array|false Decoded JSON body on success (may be empty array), false on error (see $this->error)
	 */
	protected function request($method, $resource, $data = null, $query = null)
	{
		$token = $this->getAccessToken();
		if ($token === false) {
			return false;
		}

		$url = $this->baseUrl.$this->apiBase.'/'.ltrim($resource, '/');
		if (!empty($query)) {
			$url .= '?'.http_build_query($query);
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$headers = array(
			'Authorization: Bearer '.$token,
			'Accept: application/json',
		);

		if ($data !== null) {
			$body = json_encode($data);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			$headers[] = 'Content-Type: application/json';
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			$this->error = 'cURL error on '.$method.' '.$resource.': '.$curlError;
			return false;
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			$this->error = 'Shopware API error on '.$method.' '.$resource.' (HTTP '.$httpCode.'): '.$response;
			return false;
		}

		if ($response === '') {
			return array();
		}

		$decoded = json_decode($response, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			$this->error = 'Invalid JSON response on '.$method.' '.$resource.': '.$response;
			return false;
		}

		return $decoded;
	}

	/**
	 * Check that the configured shop URL and credentials work: fetches the OAuth2 token and
	 * does one lightweight, always-available read (a single product, if any exist).
	 *
	 * @return bool
	 */
	public function testConnection()
	{
		return $this->request('GET', 'product', null, array('limit' => 1)) !== false;
	}

	/**
	 * Batch upsert/delete multiple entities of possibly different types in a single request,
	 * via the Sync API (POST /api/_action/sync). This is the mechanism that solves auvopresta's
	 * PHP-timeout problem (see PLAN.md): many products/variants/stock updates go out as one
	 * HTTP round trip instead of one call per record.
	 *
	 * $operations is keyed by an arbitrary operation key chosen by the caller, e.g.:
	 * [
	 *   'upsert-products' => [
	 *     'entity'  => 'product',
	 *     'action'  => 'upsert',
	 *     'payload' => [ ['id' => '...', 'name' => 'Foo', ...], ... ],
	 *   ],
	 * ]
	 *
	 * @param array<string, array{entity: string, action: string, payload: array<int, array<string, mixed>>}> $operations
	 * @param array|null $query Extra query params (e.g. ['indexing-behavior' => 'use-queue-indexing'])
	 * @return array|false Decoded JSON response on success, false on error (see $this->error)
	 */
	public function sync(array $operations, $query = null)
	{
		return $this->request('POST', '_action/sync', $operations, $query);
	}
}
