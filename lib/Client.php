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
	 * getExpectedAudiences() runs more than once per request, so the config
	 * complaint it can raise is reported only the first time.
	 *
	 * @var bool
	 */
	private $unusableAudienceReported = false;

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
			// Deliberately NOT symmetrical with the introspection branch below.
			// That one may fall back to RFC 7662 "client_id" because RFC 7662
			// makes "aud" optional; RFC 9068 §2.2 makes "aud" REQUIRED in a JWT
			// access token, so there is no conformant provider that needs the
			// fallback here. It would also cost us: every claim in this branch
			// arrives inside the caller's own request rather than from an
			// authenticated back-channel call, and honouring "client_id" would
			// accept a token this client legitimately obtained for a *different*
			// resource (RFC 8707, RFC 8693) and had replayed at ownCloud. See #373.
			$clientId = $config['client-id'] ?? $this->getClientID();
			$this->verifyAudience($payload, $this->getExpectedAudiences($config, $clientId));
			$this->logger->debug('Access token payload: ' . \json_encode($payload, JSON_THROW_ON_ERROR));
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			return $payload->exp;
		}

		# use token introspection to verify the token
		$introspectionClientId = $config['token-introspection-endpoint-client-id'] ?? null;
		$introspectionClientSecret = $config['token-introspection-endpoint-client-secret'] ?? null;
		$tokenExchangeMode = $config['exchange-token-mode-before-introspection'] ?? null;

		if ($tokenExchangeMode) {
			// NOTE: this deliberately discards the $token argument and verifies
			// the token held in the OIDC session instead, so the audience check
			// below binds the exchanged session token - not the token the caller
			// presented. OpenIdConnectAuthModule::getUserResource() resolves the
			// identity from the presented token, so both sides have to be kept
			// in sync when either one changes.
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
		// RFC 7662 §2.2 defines "client_id" as the client the token was issued
		// to, which is exactly what has to match this relying party. Unlike
		// RFC 7519, RFC 7662 makes "aud" optional and providers commonly use it
		// to name the resource server rather than the client, so the audience
		// claim is only the fallback. Still fail closed: if neither claim
		// names us - or no client-id is configured - verifyAudience() throws.
		//
		// Unless "audience" is configured. Then the admin has told us what "aud"
		// holds, so it is no longer unreliable and becomes authoritative: taking
		// the "client_id" shortcut here would accept a token this client
		// obtained for a *different* resource, which is the same replay the JWT
		// branch above refuses to allow.
		$clientId = $config['client-id'] ?? $this->getClientID();
		if (isset($config['audience'])
			|| $clientId === null
			|| ($introData->client_id ?? null) !== $clientId
		) {
			$this->verifyAudience($introData, $this->getExpectedAudiences($config, $clientId));
		}
		return $introData->exp;
	}

	/**
	 * The audience value(s) an access token must carry to be accepted for this
	 * relying party.
	 *
	 * Defaults to the configured client-id, which is what a spec compliant
	 * provider puts into an *ID token's* "aud" (OpenID Connect Core 1.0 §2). An
	 * access token is a different thing: RFC 9068 §3 defines its "aud" as the
	 * resource server, not the client. Providers that address the resource
	 * therefore never send the client-id, and need the "audience" config key to
	 * declare what they do send. AD FS is the common case - it renders the
	 * relying party identifier as "microsoft:identityserver:<identifier>" unless
	 * that identifier is a URL, in which case it is sent verbatim.
	 *
	 * @param array|null $config the openid-connect config
	 * @param string|null $clientId the configured relying party client-id
	 * @return string[] only non-empty strings; an empty array means no token can
	 *                  be accepted, which is what a broken "audience" value must
	 *                  degrade to
	 */
	private function getExpectedAudiences(?array $config, ?string $clientId): array {
		$configured = $config['audience'] ?? $clientId;
		if (!\is_array($configured)) {
			$configured = [$configured];
		}
		// Anything that is not a usable audience string is a misconfiguration and
		// must not silently widen the check - an empty string in particular would
		// otherwise match a token carrying an empty "aud".
		$expected = \array_values(\array_filter($configured, static function ($audience) {
			return \is_string($audience) && $audience !== '';
		}));
		// An empty result rejects every token, so say why: otherwise the only
		// symptom of a JSON number, a bool or an empty list here is a site-wide
		// auth outage whose log line reads like an attack rather than a typo.
		// Both halves are needed - "audience": [] drops nothing yet still leaves
		// nothing to match, which is the case that most needs explaining.
		if (isset($config['audience'])
			&& ($expected === [] || \count($expected) !== \count($configured))
			&& !$this->unusableAudienceReported
		) {
			$this->unusableAudienceReported = true;
			$this->logger->warning(\sprintf(
				$expected === []
					? 'No usable "audience" in the openid-connect config, only non-empty strings are accepted, so every access token will be rejected: %s'
					: 'Ignoring unusable "audience" values in the openid-connect config, only non-empty strings are accepted: %s',
				\json_encode($config['audience'])
			));
		}
		return $expected;
	}

	/**
	 * Ensures the token was issued for this relying party by asserting that one
	 * of the expected audiences is present in the token's "aud" claim.
	 * Without this check a token minted by the same issuer for a different
	 * client would be accepted - either a correctly signed JWT (see OC10-115)
	 * or an opaque token reported as active by introspection (see OC10-147).
	 * The "aud" claim may be a single string or an array of strings per
	 * RFC 7519 and RFC 7662.
	 *
	 * @param object $payload the decoded access token payload or the
	 *                        introspection response
	 * @param string[] $expectedAudiences as returned by getExpectedAudiences()
	 * @throws OpenIDConnectClientException if the audience does not match
	 */
	private function verifyAudience(object $payload, array $expectedAudiences): void {
		$audience = $payload->aud ?? null;
		$audiences = \is_array($audience) ? $audience : [$audience];
		foreach ($expectedAudiences as $expected) {
			// strict, and deliberately not \array_intersect(): that compares
			// loosely, so 0 would match "0", and it string-casts a nested array.
			if (\in_array($expected, $audiences, true)) {
				return;
			}
		}
		$this->logger->error(\sprintf(
			'Token audience does not match the expected audience: token "aud" is %s, expected one of %s',
			\json_encode($audience),
			\json_encode($expectedAudiences)
		));
		throw new OpenIDConnectClientException('Token audience does not match the expected audience');
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
		// Ask for a token whose "aud" is what verifyToken() will then expect. This
		// used to request the client-id, which is the same value while "audience"
		// is unset; without this, setting "audience" would make the exchanged
		// token fail the very check it has to pass. An empty string makes the
		// library omit the parameter, which is also the safe landing spot when no
		// client-id is configured - the old code passed null into a string param.
		$config = $this->getOpenIdConfig();
		$audience = $this->getExpectedAudiences($config, $config['client-id'] ?? $this->getClientID())[0] ?? '';
		$exchangeResponse = $this->requestTokenExchange($subjectToken, $subjectTokenType, $audience);

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
