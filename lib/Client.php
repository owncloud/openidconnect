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
	/**
	 * The claims that can name the client a token was issued to, most authoritative
	 * first. The order is security relevant - see tokenNamesThisClient() - so it is
	 * declared once and walked once, in decidingClientClaim().
	 */
	private const CLIENT_NAMING_CLAIMS = ['azp', 'appid', 'client_id'];

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
	 * Same reasoning as $unusableAudienceReported: the compatibility acceptance is
	 * reported once per request, not once per verification.
	 *
	 * @var bool
	 */
	private $audienceFallbackReported = false;

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
				return $this->systemConfigOrNull();
			}
			// "123", "true" and bare strings are valid JSON, so json_last_error()
			// says nothing about them - and a scalar here reaches callers that expect
			// an array, where it is a TypeError rather than a configuration error.
			// Fail the same way a malformed value does.
			if (!\is_array($config)) {
				$this->logger->error(
					'Loaded config from DB is not valid (expected an object, got ' . \gettype($config) . ')',
					['app' => Application::APPID]
				);
				return $this->systemConfigOrNull();
			}
			return $config;
		}

		return $this->systemConfigOrNull();
	}

	/**
	 * The openid-connect system config, or null when it is not one. config.php can
	 * hold a scalar just as the app config can, and every caller here expects an
	 * array - so neither source may hand one out.
	 *
	 * @return array|null
	 */
	private function systemConfigOrNull(): ?array {
		$config = $this->config->getSystemValue('openid-connect', null);
		if ($config === null || \is_array($config)) {
			return $config;
		}
		$this->logger->error(
			'The openid-connect system config is not valid (expected an array, got ' . \gettype($config) . ')',
			['app' => Application::APPID]
		);
		return null;
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
			// NOTE: this does not constrain the algorithm. The vendored library routes
			// HS256/384/512 to an HMAC check against the *client secret* rather than
			// the JWKS, and returns false rather than throwing - so an HS* token is
			// refused for failing that check, not for being HS*, and one that does
			// verify against the client secret is accepted.
			if (!$this->verifyJWTsignature($token)) {
				// the token itself stays out of the log: a token that fails
				// verification because a key rotated out of the JWKS is still a live
				// credential until it expires, and this line is at a level that is
				// enabled by default.
				$this->logger->error('Token cannot be verified: ' . $this->describeToken($token, $payload));
				throw new OpenIDConnectClientException('Token cannot be verified.');
			}
			$this->assertIsAccessToken($payload);
			$clientId = $config['client-id'] ?? $this->getClientID();
			$this->verifyAudience($payload, $this->getExpectedAudiences($config, $clientId), isset($config['audience']), $clientId);
			$this->logger->debug('Access token payload: ' . \json_encode($payload, JSON_THROW_ON_ERROR));
			// RFC 9068 §2.2 makes "exp" REQUIRED in a JWT access token, and without
			// one OpenIdConnectAuthModule::authToken() skips its expiry check
			// altogether - "if ($expiry)" - so an unexpiring bearer credential would
			// be honoured. Fail closed instead.
			// int or float because RFC 7519 §2 defines NumericDate as a JSON number,
			// not specifically an integer - but not a numeric *string*, which is not
			// a JSON number at all. What counts as usable beyond that is in
			// usableExpiry().
			$exp = $payload->exp ?? null;
			$expiry = (\is_int($exp) || \is_float($exp)) ? $this->usableExpiry($exp) : null;
			if ($expiry === null) {
				$this->logger->error('Access token has no usable "exp" claim: ' . \json_encode($exp));
				throw new OpenIDConnectClientException('Access token has no expiry');
			}
			return $expiry;
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
		$this->assertIsAccessToken($introData);
		$clientId = $config['client-id'] ?? $this->getClientID();
		$this->verifyAudience($introData, $this->getExpectedAudiences($config, $clientId), isset($config['audience']), $clientId);
		// No "exp" requirement here, deliberately asymmetric to the JWT branch above:
		// RFC 7662 §2.2 makes "exp" OPTIONAL in an introspection response, where
		// "active": true is the authoritative statement. That statement is only
		// re-checked when this method actually runs, which is per cache *miss* rather
		// than per request - the auth module's cache short-circuits verification
		// entirely - so what bounds an entry with no expiry is the TTL that
		// updateCache() gives it. updateCache() therefore has to tolerate null.
		//
		// A present "exp" still has to be usable before it is handed on - see
		// usableExpiry() - because authToken() computes "$expiry - time()", where a
		// non-numeric string raises a TypeError, and a TypeError is not an
		// OpenIDConnectClientException: it escapes the handler as a 500 instead of a 401.
		// An unusable value degrades to "unknown" rather than rejecting the token: it is
		// the provider's bug, the bounded TTL already covers it, and a 401 would be one
		// more deployment locked out by a patch release. Numeric strings count here,
		// unlike in the JWT branch, where "exp" is REQUIRED and failing closed is the
		// whole point.
		$exp = $introData->exp ?? null;
		if ($exp === null) {
			return null;
		}
		$expiry = \is_numeric($exp) ? $this->usableExpiry($exp + 0) : null;
		if ($expiry === null) {
			$this->logger->error(
				'Introspection response carries an unusable "exp" claim, treating the expiry as unknown: '
				. \json_encode($exp)
			);
		}
		return $expiry;
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
		$expected = $this->usableAudienceStrings($configured);
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
				// unescaped for the same reason as the audience mismatch below: the
				// admin has to recognise their own value in it
				\json_encode($config['audience'], JSON_UNESCAPED_SLASHES)
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
	 * Unless the audience is authoritative, a claim naming us as the client the
	 * token was issued to is accepted instead - see tokenNamesThisClient(). Both
	 * branches follow that same rule: strictness is what "audience" buys.
	 *
	 * @param object $payload the decoded access token payload or the
	 *                        introspection response
	 * @param string[] $expectedAudiences as returned by getExpectedAudiences()
	 * @param bool $audienceIsAuthoritative whether "audience" is configured, in
	 *                                      which case only "aud" is consulted
	 * @param string|null $clientId the configured relying party client-id
	 * @throws OpenIDConnectClientException if the audience does not match
	 */
	private function verifyAudience(
		object $payload,
		array $expectedAudiences,
		bool $audienceIsAuthoritative,
		?string $clientId
	): void {
		$audience = $payload->aud ?? null;
		$audiences = \is_array($audience) ? $audience : [$audience];
		foreach ($expectedAudiences as $expected) {
			// strict, and deliberately not \array_intersect(): that compares
			// loosely, so 0 would match "0", and it string-casts a nested array.
			if (\in_array($expected, $audiences, true)) {
				return;
			}
		}
		if (!$audienceIsAuthoritative) {
			$this->reportClientClaimMismatch($payload, $clientId);
			$claim = $this->tokenNamesThisClient($payload, $clientId);
			if ($claim !== null) {
				// Only worth a word when the token carries an audience the admin
				// could actually declare. With no usable "aud" - Keycloak's stock
				// client, or an introspection response omitting it, both
				// legitimate - there is nothing to configure, so advising it would
				// be noise on every request.
				if ($this->usableAudienceStrings($audiences) !== []) {
					$this->reportAudienceFallbackOnce($claim, $audience);
				}
				return;
			}
		}
		// slashes unescaped: these values get copied into the config, and
		// "api:\/\/owncloud" is not what the provider sent.
		$this->logger->error(\sprintf(
			'Token audience does not match the expected audience: token "aud" is %s, expected one of %s',
			\json_encode($audience, JSON_UNESCAPED_SLASHES),
			\json_encode($expectedAudiences, JSON_UNESCAPED_SLASHES)
		));
		throw new OpenIDConnectClientException('Token audience does not match the expected audience');
	}

	/**
	 * Whether a claim names this relying party as the client the token was issued
	 * to, and which one did.
	 *
	 * This is the compatibility half of the audience check. An access token's
	 * "aud" belongs to the resource server (RFC 9068 §3), so a provider that
	 * addresses the resource never sends our client-id there, and requiring it
	 * unconditionally locks those deployments out: Keycloak sends no "aud" at all
	 * (observed against 26.0), Entra ID v1.0 tokens send the App ID URI, AD FS
	 * sends "microsoft:identityserver:<identifier>" (#373). What all of them do
	 * send is the client: "azp" per OpenID Connect Core 1.0 §2, "appid" on Entra
	 * ID v1.0 and AD FS, "client_id" per RFC 7662 §2.2.
	 *
	 * Accepting that keeps the finding this check was added for: the attacker's own
	 * client cannot mint a token that gets through here, because the claim names
	 * their client, not ours (OC10-115, OC10-147). Note the narrower scope of that
	 * statement - it is about *this* path. A token whose "aud" names us is still
	 * accepted by the audience comparison above no matter which client requested
	 * it, which is the resource-server model working as intended and unchanged from
	 * before this fallback existed.
	 *
	 * The claims are ordered by how authoritative they are, and the first one that
	 * carries a value decides - not the first one that happens to match. Falling
	 * through a claim naming somebody else to a lower-precedence one would let a
	 * client on the same issuer hand us a truthful "azp" naming itself alongside a
	 * hardcoded "client_id" naming us, which several providers allow their tenants to
	 * configure, and that token would pass while every claim in it was honest. No
	 * provider sends two of these with conflicting values, so deciding on the first
	 * costs nothing in compatibility. A claim explicitly set to JSON null carries no
	 * name, so it does not decide and the next one is consulted - no provider emits
	 * that, and it could not help an attacker if one did, since the only alternative
	 * to falling through is a rejection.
	 *
	 * What the fallback does not cover is a token this client obtained for a
	 * different *resource* and had replayed here (RFC 8707, RFC 8693) - configuring
	 * "audience" is what closes that, which is why it turns this fallback off.
	 *
	 * @param object $payload the decoded access token payload or the
	 *                        introspection response
	 * @param string|null $clientId the configured relying party client-id
	 * @return string|null the claim that named us, null if none did or if the most
	 *                     authoritative one carrying a value named somebody else
	 */
	private function tokenNamesThisClient(object $payload, ?string $clientId): ?string {
		if ($clientId === null || $clientId === '') {
			return null;
		}
		$claim = $this->decidingClientClaim($payload);
		if ($claim === null) {
			return null;
		}
		// strict: a numeric client-id must not match a numeric claim of a different
		// type, the same trap the audience comparison avoids.
		return $clientId === $payload->$claim ? $claim : null;
	}

	/**
	 * The client-naming claim that decides, i.e. the most authoritative one the token
	 * carries a value for - null when it carries none. "Carries a value" and not merely
	 * "is present": a claim set to JSON null names nobody, so it cannot decide.
	 *
	 * One walk for both callers on purpose. tokenNamesThisClient() decides on this
	 * order and reportClientClaimMismatch() explains the decision, so a second copy of
	 * the order could drift from it: change one and the log line would describe a
	 * decision the code did not make, or the rejection would go silent about its reason.
	 *
	 * @param object $payload the decoded access token payload or the introspection
	 *                        response
	 * @return string|null
	 */
	private function decidingClientClaim(object $payload): ?string {
		foreach (self::CLIENT_NAMING_CLAIMS as $claim) {
			if (isset($payload->$claim)) {
				return $claim;
			}
		}
		return null;
	}

	/**
	 * Says so when the claim that decided a rejection was a client-naming one, since
	 * the audience mismatch logged afterwards names the audience and would otherwise
	 * send an admin looking for a misconfigured "audience" that is not the problem.
	 *
	 * @param object $payload the decoded access token payload or the introspection
	 *                        response
	 * @param string|null $clientId the configured relying party client-id
	 */
	private function reportClientClaimMismatch(object $payload, ?string $clientId): void {
		if ($clientId === null || $clientId === '') {
			return;
		}
		$claim = $this->decidingClientClaim($payload);
		if ($claim !== null && $clientId !== $payload->$claim) {
			$this->logger->error(\sprintf(
				'Token was issued to another client: "%s" is %s, this relying party is %s. '
				. 'Lower-precedence claims are deliberately not consulted once a more '
				. 'authoritative one names somebody else.',
				$claim,
				\json_encode($payload->$claim, JSON_UNESCAPED_SLASHES),
				\json_encode($clientId, JSON_UNESCAPED_SLASHES)
			));
		}
	}

	/**
	 * A NumericDate claim as the number of seconds the callers can actually work with,
	 * or null when it is not one.
	 *
	 * What "unusable" has to mean here is set by what the callers do with the result.
	 * OpenIdConnectAuthModule::authToken() guards its expiry check with "if ($expiry)", so
	 * anything the cast turns into 0 skips that check exactly like a missing claim does -
	 * while updateCache() reads it as a *known* expiry and caches the token with no TTL at
	 * all. And casting a float PHP cannot hold is undefined: it wraps two's-complement, so
	 * an out-of-range claim can land on a plausible far-future expiry, which then gets
	 * cached forever. That is the worst outcome available here.
	 *
	 * Hence three tests, and each one is the only thing standing between one input and
	 * that outcome - every other unusable value is caught redundantly by all three:
	 *
	 * - the floor catches claims below the int range. -1e19 casts to 8446744073709551616,
	 *   so a *negative* claim would otherwise come out as a far-future expiry.
	 * - the ceiling catches claims above it, 2e19 casting to 1553255926290448384. It also
	 *   catches INF, which no comparison below would.
	 * - the test after the cast catches exactly 2^63, which is not *greater* than
	 *   PHP_INT_MAX once both are floats, yet casts to PHP_INT_MIN.
	 *
	 * All three are mutation-tested, and each has a data row that fails without it. An
	 * earlier version also called is_finite() and compared "$exp <= 0"; both of those
	 * mutated away with the suite still green, so they are gone rather than test-papered.
	 *
	 * @param int|float $exp
	 * @return int|null
	 */
	private function usableExpiry($exp): ?int {
		if (!($exp >= 1 && $exp <= \PHP_INT_MAX)) {
			return null;
		}
		$seconds = (int)$exp;
		return $seconds > 0 ? $seconds : null;
	}

	/**
	 * The values usable as an audience, i.e. the non-empty strings. Applied to
	 * both sides of the comparison - the configured `audience` and the token's
	 * "aud" - so that the two cannot drift apart on what "usable" means.
	 *
	 * @param array $audiences
	 * @return string[]
	 */
	private function usableAudienceStrings(array $audiences): array {
		return \array_values(\array_filter($audiences, static function ($audience) {
			return \is_string($audience) && $audience !== '';
		}));
	}

	/**
	 * Refuses a token that states it is something other than an access token,
	 * whatever its audience says - see nonAccessTokenMarker().
	 *
	 * Called from verifyToken() at the head of both branches rather than from
	 * verifyAudience(): the audience checker's name, docblock and @throws are all
	 * about audiences, and a guard hidden there is reachable only by remembering to
	 * call it. Here it is structural instead.
	 *
	 * @param object $claims the decoded access token payload or the introspection
	 *                       response
	 * @throws OpenIDConnectClientException
	 */
	private function assertIsAccessToken(object $claims): void {
		$marker = $this->nonAccessTokenMarker($claims);
		if ($marker === null) {
			return;
		}
		$this->logger->error(\sprintf(
			'Token states that it is not an access token (%s) and cannot be used to authenticate one',
			$marker
		));
		throw new OpenIDConnectClientException('Token is not an access token');
	}

	/**
	 * Identifies a token in a log line without reproducing it. A token that failed
	 * verification can still be valid - a signing key rotated out of the JWKS is the
	 * common case - so the value itself must not be written to a log that log
	 * shippers and support bundles collect.
	 *
	 * @param string $token
	 * @param object|null $payload the decoded payload, where it is already available
	 * @return string
	 */
	private function describeToken(string $token, ?object $payload = null): string {
		// explode() always yields element 0, and base64_decode() in non-strict mode
		// never returns false - so json_decode() is the only thing that can fail here,
		// and it fails to null, which the ?? below already covers.
		$header = \json_decode(\base64_decode(\strtr(\explode('.', $token)[0], '-_', '+/')), false);
		return \sprintf(
			'kid=%s alg=%s sub=%s',
			\json_encode($header->kid ?? null),
			\json_encode($header->alg ?? null),
			\json_encode($payload->sub ?? null)
		);
	}

	/**
	 * Whether the token states that it is something other than an access token, and
	 * how, formatted for a log line - null when it makes no such statement.
	 *
	 * This is an allowlist on purpose. Enumerating the refresh markers would close
	 * only what it enumerates: Keycloak's back-channel logout tokens ("typ" of
	 * "Logout"), its registration and initial access tokens, and its ID tokens are
	 * all realm-signed, carry an "aud" equal to the client-id, and would satisfy the
	 * default expectation. So when a token labels its own type at all, that label has
	 * to say "access token"; when it carries no label, there is nothing to go on and
	 * the audience decides as before. Keycloak marks the type in "typ" ("Bearer" for
	 * an access token), RFC 9068 §2.1 uses the "at+jwt" media type, AWS Cognito uses
	 * "token_use" - "access", and "access_token" is accepted for it too. Any of the four
	 * values is accepted in either claim, but *every* label the token carries has to be
	 * one of them: the loop below rejects on the first that is not. Entra ID and AD FS put
	 * no type claim in the payload, so nothing changes for them.
	 *
	 * The generic JOSE media type "jwt" is deliberately *not* on the list, though it does
	 * say nothing about the token's type. A provider that stamps it into the payload -
	 * by copying the header "typ", which is where Keycloak carries exactly that value -
	 * stamps it on every token it signs, refresh tokens included. Allowing it would
	 * therefore trade a hypothetical outage for a hypothetical refresh token becoming a
	 * bearer credential, on the same hypothetical provider, and the second is the reason
	 * this check exists. Note that there is no configuration that relaxes this: the
	 * check runs before the audience is looked at and reads nothing from the config, so
	 * such a provider would need a change here, not a setting.
	 *
	 * A refresh token is the reason this exists at all: it lives far longer than an
	 * access token and is stored rather than passed around, so accepting one as a
	 * bearer credential turns every place it rests into a login.
	 *
	 * @param object $payload the decoded access token payload or the
	 *                        introspection response
	 * @return string|null
	 */
	private function nonAccessTokenMarker(object $payload): ?string {
		foreach (['typ', 'token_use'] as $claim) {
			$value = $payload->$claim ?? null;
			if (!\is_string($value)) {
				continue;
			}
			if (!\in_array(\strtolower($value), ['bearer', 'at+jwt', 'access', 'access_token'], true)) {
				return \sprintf('"%s" is "%s"', $claim, $value);
			}
		}
		return null;
	}

	/**
	 * Reports that a token was accepted on a client-naming claim rather than on
	 * its audience, and how to make the check strict. Once per instance, for the
	 * same reason getExpectedAudiences() reports its complaint once: the Client is
	 * a per-request singleton whose verification runs more than once per request.
	 *
	 * @param string $claim the claim that named us
	 * @param mixed $audience the token's "aud" claim, for the admin to copy from
	 */
	private function reportAudienceFallbackOnce(string $claim, $audience): void {
		if ($this->audienceFallbackReported) {
			return;
		}
		$this->audienceFallbackReported = true;
		// info, not warning: this fires for every provider the fallback exists for,
		// which is the documented happy path rather than an anomaly, and the flag
		// above only dedupes within a request. A warning on every token acquisition
		// of every user would drown the log it is trying to inform.
		$this->logger->info(\sprintf(
			'Access token "aud" does not name this relying party, accepted because "%s" matches the configured client-id. '
			. 'To have the audience enforced, set the openid-connect "audience" config key to what the provider sends '
			. 'in "aud" - but only if that value is one only ownCloud can be issued a token for, since a shared or '
			. 'tenant-wide resource identifier would accept tokens issued to other clients of the same provider: %s',
			$claim,
			// unescaped: this value is meant to be copied into the config verbatim
			\json_encode($audience, JSON_UNESCAPED_SLASHES)
		));
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
