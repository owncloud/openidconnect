<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Miroslav Bauer <Miroslav.Bauer@cesnet.cz>
 * @author Ilja Neumann <ineumann@owncloud.com>
 *
 * @copyright Copyright (c) 2022, ownCloud GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OpenIdConnect;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\ILogger;

class Client extends OpenIDConnectClient {
	/** @var ISession */
	private $session;
	/** @var IConfig */
	private $config;
	/** @var array */
	private $wellKnownConfig;
	/** @var ILogger */
	private $logger;

	/**
	 * @var IURLGenerator
	 */
	private $generator;
	private IClientService $clientService;

	/**
	 * Client constructor.
	 *
	 * @param IConfig $config
	 * @param IURLGenerator $generator
	 * @param ISession $session
	 * @param ILogger $logger
	 *
	 * @throws \JsonException
	 */
	public function __construct(
		IConfig $config,
		IURLGenerator $generator,
		ISession $session,
		ILogger $logger,
		IClientService $clientService
	) {
		$this->session = $session;
		$this->config = $config;
		$this->generator = $generator;
		$this->logger = $logger;
		$this->clientService = $clientService;

		$openIdConfig = $this->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		parent::__construct(
			$openIdConfig['provider-url'],
			$openIdConfig['client-id'],
			$openIdConfig['client-secret']
		);
		$scopes = $openIdConfig['scopes'] ?? ['openid', 'profile', 'email'];
		$this->addScope($scopes);

		$insecure = $openIdConfig['insecure'] ?? false;
		if ($insecure) {
			$this->setVerifyHost(false);
			$this->setVerifyPeer(false);
		}
		// set config parameters in case well known is not supported
		if (isset($openIdConfig['provider-params'])) {
			$this->providerConfigParam($openIdConfig['provider-params']);
		}
		// set additional auth parameters
		if (isset($openIdConfig['auth-params'])) {
			$this->addAuthParam($openIdConfig['auth-params']);
		}
	}

	/**
	 * @return mixed
	 */
	public function getOpenIdConfig() {
		$configRaw = $this->config->getAppValue(Application::APPID, 'openid-connect', null);
		if ($configRaw) {
			$config = json_decode($configRaw, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->error(
					'Loaded config from DB is not valid (malformed JSON); JSON Last Error: ' . json_last_error(),
					['app' => Application::APPID]
				);
				return $this->config->getSystemValue('openid-connect', null);
			}
			return $config;
		}

		return $this->config->getSystemValue('openid-connect', null);
	}

	public function getAutoProvisionConfig(): array {
		return $this->getOpenIdConfig()['auto-provision'] ?? [];
	}

	public function getAutoUpdateConfig(): array {
		return $this->getAutoProvisionConfig()['update'] ?? [];
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function getWellKnownConfig() {
		if (!$this->wellKnownConfig) {
			$well_known_config_url = \rtrim($this->getProviderURL(), '/') . '/.well-known/openid-configuration';
			$this->wellKnownConfig = \json_decode($this->fetchURL($well_known_config_url), false, 512, JSON_THROW_ON_ERROR);
		}
		return $this->wellKnownConfig;
	}

	public function mode() {
		return $this->getOpenIdConfig()['mode'] ?? 'userid';
	}

	/**
	 * @return object|null
	 */
	public function getAccessTokenPayload(): ?object {
		if ($this->accessToken === '') {
			return null;
		}
		$parts = explode('.', $this->accessToken);
		if (!isset($parts[1])) {
			return null;
		}

		return parent::getAccessTokenPayload();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function verifyToken(string $token) {
		$config = $this->getOpenIdConfig();
		$this->setAccessToken($token);
		$payload = $this->getAccessTokenPayload();
		if ($payload) {
			if (!$this->verifyJWTsignature($token)) {
				$this->logger->error('Token cannot be verified: ' . $token);
				throw new OpenIDConnectClientException('Token cannot be verified.');
			}
			$this->logger->debug('Access token payload: ' . \json_encode($payload, JSON_THROW_ON_ERROR));
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			return $payload->exp;
		}

		# use token introspection to verify the token
		$introspectionClientId = $config['token-introspection-endpoint-client-id'] ?? null;
		$introspectionClientSecret = $config['token-introspection-endpoint-client-secret'] ?? null;
		$tokenExchangeMode = $config['exchange-token-mode-before-introspection'] ?? null;

		if ($tokenExchangeMode) {
			$token = $tokenExchangeMode === 'refresh-token' ? $this->session->get('oca.openid-connect.refresh-token') : $this->session->get('oca.openid-connect.access-token');
			$this->logger->debug("Starting token-exchange to verify session with subject_token mode: $tokenExchangeMode");

			$token = $this->exchangeToken($token, $tokenExchangeMode);
		}

		$introData = $this->introspectToken($token, '', $introspectionClientId, $introspectionClientSecret);
		if ($introData === null) {
			return null;
		}
		$this->logger->debug('Introspection info: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
		if (\property_exists($introData, 'error')) {
			$this->logger->error('Token introspection failed: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
			throw new OpenIDConnectClientException("Verifying token failed: {$introData->error}");
		}
		if (!$introData->active) {
			$this->logger->error('Token (as per introspection) is inactive: ' . \json_encode($introData, JSON_THROW_ON_ERROR));
			throw new OpenIDConnectClientException('Token (as per introspection) is inactive');
		}
		return $introData->exp;
	}

	public function introspectToken($token, $token_type_hint = '', $clientId = null, $clientSecret = null) {
		try {
			# test if introspection is possible ...
			$this->getProviderConfigValue('introspection_endpoint');
		} catch (OpenIDConnectClientException $e) {
			return null;
		}

		return parent::introspectToken($token, $token_type_hint, $clientId, $clientSecret);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function getUserInfo() {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['use-access-token-payload-for-user-info']) && $openIdConfig['use-access-token-payload-for-user-info']) {
			if ($payload = $this->getAccessTokenPayload()) {
				return $payload;
			}
		}

		if (isset($openIdConfig['use-access-token-introspection-for-user-info']) && $openIdConfig['use-access-token-introspection-for-user-info']) {
			$introspectionClientId = $openIdConfig['token-introspection-endpoint-client-id'] ?? null;
			$introspectionClientSecret = $openIdConfig['token-introspection-endpoint-client-secret'] ?? null;
			$accessToken = $this->getAccessToken();
			if (isset($openIdConfig['exchange-token-mode-before-introspection'])) {
				$mode = $openIdConfig['exchange-token-mode-before-introspection'];
				$token = $mode === 'refresh-token' ? $this->getRefreshToken() : $this->getAccessToken();
				$this->logger->debug("Starting token-exchange to get user_info with subject_token mode: $mode");
				$accessToken = $this->exchangeToken($token, $mode);
			}

			return $this->introspectToken($accessToken, '', $introspectionClientId, $introspectionClientSecret);
		}

		return $this->requestUserInfo();
	}

	public function getIdentityClaim() {
		return $this->getOpenIdConfig()['search-attribute'] ?? 'email';
	}

	public function getEmailClaim(): ?string {
		return $this->getAutoProvisionConfig()['email-claim'] ?? null;
	}

	public function getDisplayNameClaim(): ?string {
		return $this->getAutoProvisionConfig()['display-name-claim'] ??	null;
	}

	public function getPictureClaim(): ?string {
		return $this->getAutoProvisionConfig()['picture-claim'] ?? null;
	}

	public function getGroupsClaim(): ?string
	{
		return $this->getAutoProvisionConfig()['groups-claim'] ?? null;
	}

	public function getUserEmail($userInfo): ?string {
		$email = $this->mode() === 'email' ? $userInfo->{$this->getIdentityClaim()} : null;
		$emailClaim = $this->getEmailClaim();
		if (!$email && $emailClaim) {
			return $userInfo->$emailClaim;
		}
		return $email;
	}

	public function getUserDisplayName($userInfo): ?string {
		$displayNameClaim = $this->getDisplayNameClaim();
		if ($displayNameClaim) {
			return $userInfo->$displayNameClaim;
		}
		return null;
	}

	public function getUserPicture($userInfo): ?string {
		$pictureClaim = $this->getPictureClaim();
		if ($pictureClaim) {
			return $userInfo->$pictureClaim;
		}
		return null;
	}

	public function getUserGroupIds($userInfo): ?string {
		$groupsClaim = $this->getGroupsClaim();
		if ($groupsClaim) {
			return $userInfo->$groupsClaim;
		}
		return null;
	}

	/**
	 * Perform a RFC8693 Token Exchange
	 * https://datatracker.ietf.org/doc/html/rfc8693
	 *
	 * @param string $subjectToken
	 * @param string $tokenType Type of the token to exchange 'refresh-token' or 'access-token'
	 * @return string Access Token
	 * @throws OpenIDConnectClientException
	 */
	public function exchangeToken(string $subjectToken, string $tokenType): string {
		$subjectTokenType = $tokenType === 'refresh-token' ? 'urn:ietf:params:oauth:token-type:refresh_token' : 'urn:ietf:params:oauth:token-type:access_token';
		$exchangeResponse = $this->requestTokenExchange($subjectToken, $subjectTokenType, $this->getClientID());

		if (isset($exchangeResponse->error)) {
			if (isset($exchangeResponse->error_description)) {
				throw new OpenIDConnectClientException('TokenExchange response: ' . $exchangeResponse->error_description);
			}
			throw new OpenIDConnectClientException('TokenExchange response: ' . $exchangeResponse->error);
		}

		return $exchangeResponse->access_token;
	}

	public function storeRedirectUrl(?string $redirectUrl): void {
		if ($redirectUrl) {
			$this->setSessionKey('openid_connect_redirect_url', $redirectUrl);
		}
	}

	public function readRedirectUrl(): ?string {
		return $this->getSessionKey('openid_connect_redirect_url');
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function startSession() {
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function setSessionKey($key, $value) {
		$this->session->set($key, $value);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function getSessionKey($key) {
		return $this->session->get($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function unsetSessionKey($key) {
		$this->session->remove($key);
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function commitSession() {
	}

	/**
	 * @codeCoverageIgnore
	 * @throws OpenIDConnectClientException
	 */
	protected function fetchURL($url, $post_body = null, $headers = []) {
		$this->logger->debug("Fetching URL: $url");

		$parsedHeaders = [];
		foreach ($headers as $header) {
			$sHeader = explode(':', $header, 2);
			if (\count($sHeader) === 2) {
				$parsedHeaders[trim($sHeader[0])] = trim($sHeader[1]);
			}
		}

		$params = [
			'headers' => $parsedHeaders,
		];

		try {
			$client = $this->clientService->newClient();
			if ($post_body === null) {
				$response = $client->get($url, $params);
				return $this->processResponseAndGetBody($response);
			}

			// Determine if this is a JSON payload and add the appropriate content type
			$json_post_body = \json_decode($post_body);
			if (\is_object($json_post_body)) {
				$params['headers']['Content-Type'] = 'application/json';
				$params['json'] = $json_post_body;
			} else {
				$params['form_params'] = [];
				\parse_str($post_body, $params['form_params']);
			}

			return $this->processResponseAndGetBody($client->post($url, $params));
		} catch (\Exception $ex) {
			$exception = \get_class($ex);
			$msg = $ex->getMessage();
			$this->logger->error("$exception accessing $url: $msg");
			throw $ex;
		}
	}

	private function processResponseAndGetBody($response) {
		$this->responseCode = $response->getStatusCode();
		// we can't set the content type for now: the attribute is private
		//$this->responseContentType = $response->getHeader('Content-Type');
		return $response->getBody();
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getCodeChallengeMethod() {
		return 'S256';
	}

	protected function verifyJWKHeader($jwk) {
		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['jwt-self-signed-jwk-header-supported']) && $openIdConfig['jwt-self-signed-jwk-header-supported']) {
			return;
		}
		throw new OpenIDConnectClientException('Self signed JWK header is not valid');
	}

	/**
	 * @codeCoverageIgnore
	 *
	 * @return bool
	 * @throws OpenIDConnectClientException
	 * @throws \JsonException
	 */
	public function authenticate() : bool {
		$redirectUrl = $this->generator->linkToRouteAbsolute('openidconnect.loginFlow.login');

		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['redirect-url'])) {
			$redirectUrl = $openIdConfig['redirect-url'];
		}

		$this->setRedirectURL($redirectUrl);
		return parent::authenticate();
	}
}
