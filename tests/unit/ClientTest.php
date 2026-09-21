<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
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

namespace OCA\OpenIdConnect\Tests\Unit;

use JsonException;
use Jumbojett\OpenIDConnectClientException;
use OCA\OpenIdConnect\Client;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\ILogger;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ClientTest extends TestCase {
	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | IConfig
	 */
	private $config;
	/**
	 * @var MockObject | IClientService
	 */
	private $clientService;

	public function providesGetUserInfoData(): array {
		return [
			'access-token' => [true],
			'user-info-endpoint' => [false],
		];
	}

	public function appConfigProvider(): \Generator {
		yield 'invalid json' => ['from system config', '{[s', 'Loaded config from DB is not valid (malformed JSON); JSON Last Error: 4'];
		yield 'empty app config' => ['from system config', ''];
		yield 'empty array' => [[], '[]'];
		yield 'json object' => [['foo' => 'bar'], '{"foo": "bar"}'];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->clientService = $this->createMock(IClientService::class);

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['fetchURL'])
			->getMock();
	}

	public function testGetConfig(): void {
		$this->config->expects(self::once())->method('getSystemValue')->willReturn('foo');
		$return = $this->client->getOpenIdConfig();
		self::assertEquals('foo', $return);
	}

	/**
	 * @dataProvider appConfigProvider
	 */
	public function testGetAppConfig($expectedData, $dataInConfig, $expectedErrorMessage = null): void {
		$this->config->method('getSystemValue')->willReturn('from system config');
		$this->config->expects(self::once())->method('getAppValue')->willReturnCallback(function () use ($dataInConfig) {
			return $dataInConfig;
		});
		if ($expectedErrorMessage) {
			$this->logger->expects(self::once())->method('error')->with($expectedErrorMessage);
		}
		$return = $this->client->getOpenIdConfig();
		self::assertEquals($expectedData, $return);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws JsonException
	 */
	public function testGetWellKnown(): void {
		$this->client->setProviderURL('https://example.net');
		$this->client->expects(self::once())->method('fetchURL')->with('https://example.net/.well-known/openid-configuration')->willReturn('{"foo": "bar"}');
		$return = $this->client->getWellKnownConfig();
		self::assertEquals((object)['foo' => 'bar'], $return);
	}

	/**
	 * @throws OpenIDConnectClientException
	 */
	public function testCtor(): void {
		$providerUrl = 'https://example.net';

		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($providerUrl) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => $providerUrl,
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'scopes' => ['openid', 'profile'],
					'provider-params' => ['bar'],
					'auth-params' => ['foo'],
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});
		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['fetchURL'])
			->getMock();

		self::assertEquals($providerUrl, $this->client->getProviderURL());
		self::assertEquals(true, $this->client->getVerifyHost());
		self::assertEquals(true, $this->client->getVerifyPeer());
	}

	/**
	 * @throws OpenIDConnectClientException
	 */
	public function testCtorInsecure(): void {
		$providerUrl = 'https://example.net';

		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($providerUrl) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => $providerUrl,
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'scopes' => ['openid', 'profile'],
					'provider-params' => ['bar'],
					'auth-params' => ['foo'],
					'insecure' => true
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});
		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['fetchURL'])
			->getMock();

		self::assertEquals($providerUrl, $this->client->getProviderURL());
		self::assertEquals(false, $this->client->getVerifyHost());
		self::assertEquals(false, $this->client->getVerifyPeer());
	}

	/**
	 * @dataProvider providesGetUserInfoData
	 * @param $useAccessTokenPayloadForUserInfo
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testGetUserInfo($useAccessTokenPayloadForUserInfo): void {
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($useAccessTokenPayloadForUserInfo) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => '$providerUrl',
					'client-id' => 'client-id',
					'client-secret' => 'secret',
					'use-access-token-payload-for-user-info' => $useAccessTokenPayloadForUserInfo
				];
			}
			if ($key === 'proxy') {
				return null;
			}
			if ($key === 'proxyuserpwd') {
				return null;
			}
			throw new \InvalidArgumentException("Unexpected key: $key");
		});

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['requestUserInfo', 'getAccessTokenPayload'])
			->getMock();
		if ($useAccessTokenPayloadForUserInfo) {
			$this->client->expects(self::never())->method('requestUserInfo');
			$this->client->expects(self::once())->method('getAccessTokenPayload')->willReturn((object)[
				'preferred_username' => 'alice@example.net'
			]);
		} else {
			$this->client->expects(self::never())->method('getAccessTokenPayload');
			$this->client->expects(self::once())->method('requestUserInfo')->willReturn((object)[
				'preferred_username' => 'alice@example.net'
			]);
		}

		$info = $this->client->getUserInfo();
		self::assertEquals((object)[
			'preferred_username' => 'alice@example.net'
		], $info);
	}

	public function providesAudienceData(): array {
		return [
			'aud string matches client-id' => ['owncloud-client', true],
			'aud array contains client-id' => [['owncloud-client', 'client-a'], true],
			'aud string is another client' => ['client-a', false],
			'aud array without client-id' => [['client-a', 'client-b'], false],
			'aud missing' => [null, false],
		];
	}

	/**
	 * @dataProvider providesAudienceData
	 * @param string|array|null $aud
	 * @param bool $expectValid
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenAudience($aud, bool $expectValid): void {
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => 'https://example.net',
					'client-id' => 'owncloud-client',
					'client-secret' => 'secret',
				];
			}
			return null;
		});

		$payload = ['exp' => \time() + 3600];
		if ($aud !== null) {
			$payload['aud'] = $aud;
		}

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['getAccessTokenPayload', 'verifyJWTsignature', 'setAccessToken'])
			->getMock();
		$this->client->method('setAccessToken');
		$this->client->method('getAccessTokenPayload')->willReturn((object)$payload);
		$this->client->method('verifyJWTsignature')->willReturn(true);

		if (!$expectValid) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}

		$exp = $this->client->verifyToken('some-token');

		if ($expectValid) {
			self::assertEquals($payload['exp'], $exp);
		}
	}

	/**
	 * @dataProvider providesAudienceData
	 * @param string|array|null $aud
	 * @param bool $expectValid
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenIntrospectionAudience($aud, bool $expectValid): void {
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => 'https://example.net',
					'client-id' => 'owncloud-client',
					'client-secret' => 'secret',
				];
			}
			return null;
		});

		$introspectionData = ['active' => true, 'exp' => \time() + 3600];
		if ($aud !== null) {
			$introspectionData['aud'] = $aud;
		}

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->getMock();
		$this->client->method('setAccessToken');
		// an opaque token has no JWT payload - this is what forces the
		// introspection branch of verifyToken
		$this->client->method('getAccessTokenPayload')->willReturn(null);
		$this->client->method('introspectToken')->willReturn((object)$introspectionData);

		if (!$expectValid) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}

		$exp = $this->client->verifyToken('opaque-token');

		if ($expectValid) {
			self::assertEquals($introspectionData['exp'], $exp);
		}
	}

	public function providesIntrospectionClientIdData(): array {
		return [
			// "client_id" is the claim which actually answers "was this token
			// issued to us", so a match is enough on its own
			'client_id matches, aud absent' => ['owncloud-client', 'owncloud-client', null, true],
			'client_id matches, aud names the resource server' => ['owncloud-client', 'owncloud-client', 'account', true],
			// a foreign client_id falls through to the audience claim
			'client_id is another client, aud absent' => ['owncloud-client', 'client-a', null, false],
			'client_id is another client, aud contains client-id' => ['owncloud-client', 'client-a', ['owncloud-client', 'client-a'], true],
			// with no client-id configured neither claim can name us
			'no client-id configured, no claim names us' => [null, null, null, false],
		];
	}

	/**
	 * The introspection branch prefers the "client_id" of RFC 7662 §2.2 over the
	 * optional "aud" claim, which providers commonly use to name the resource
	 * server rather than the relying party (OC10-147).
	 *
	 * @dataProvider providesIntrospectionClientIdData
	 * @param string|null $configuredClientId
	 * @param string|null $introspectedClientId
	 * @param string|array|null $aud
	 * @param bool $expectValid
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenIntrospectionClientId(
		?string $configuredClientId,
		?string $introspectedClientId,
		$aud,
		bool $expectValid
	): void {
		$this->config->method('getSystemValue')->willReturnCallback(static function ($key) use ($configuredClientId) {
			if ($key === 'openid-connect') {
				return [
					'provider-url' => 'https://example.net',
					'client-id' => $configuredClientId,
					'client-secret' => 'secret',
				];
			}
			return null;
		});

		$introspectionData = ['active' => true, 'exp' => \time() + 3600];
		if ($introspectedClientId !== null) {
			$introspectionData['client_id'] = $introspectedClientId;
		}
		if ($aud !== null) {
			$introspectionData['aud'] = $aud;
		}

		$this->client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->getMock();
		$this->client->method('setAccessToken');
		// an opaque token has no JWT payload - this is what forces the
		// introspection branch of verifyToken
		$this->client->method('getAccessTokenPayload')->willReturn(null);
		$this->client->method('introspectToken')->willReturn((object)$introspectionData);

		if (!$expectValid) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}

		$exp = $this->client->verifyToken('opaque-token');

		if ($expectValid) {
			self::assertEquals($introspectionData['exp'], $exp);
		}
	}

	/**
	 * Builds a client whose access token looks like a JWT, so verifyToken() takes
	 * its JWT branch.
	 *
	 * @param array $openIdConfig the openid-connect config
	 * @param array $payload the decoded access token payload
	 * @return Client
	 */
	private function buildClientForJwt(array $openIdConfig, array $payload): Client {
		$this->config->method('getSystemValue')->willReturnCallback(
			static function ($key) use ($openIdConfig) {
				return $key === 'openid-connect' ? $openIdConfig : null;
			}
		);
		$client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['getAccessTokenPayload', 'verifyJWTsignature', 'setAccessToken'])
			->getMock();
		$client->method('setAccessToken');
		$client->method('getAccessTokenPayload')->willReturn((object)$payload);
		$client->method('verifyJWTsignature')->willReturn(true);
		return $client;
	}

	/**
	 * Builds a client whose access token is opaque, so verifyToken() takes its
	 * introspection branch.
	 *
	 * @param array $openIdConfig the openid-connect config
	 * @param array $introspectionData the introspection response
	 * @return Client
	 */
	private function buildClientForIntrospection(array $openIdConfig, array $introspectionData): Client {
		$this->config->method('getSystemValue')->willReturnCallback(
			static function ($key) use ($openIdConfig) {
				return $key === 'openid-connect' ? $openIdConfig : null;
			}
		);
		$client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->getMock();
		$client->method('setAccessToken');
		// an opaque token has no JWT payload - this is what forces the
		// introspection branch of verifyToken
		$client->method('getAccessTokenPayload')->willReturn(null);
		$client->method('introspectToken')->willReturn((object)$introspectionData);
		return $client;
	}

	public function providesConfiguredAudienceData(): array {
		return [
			// the reported AD FS shape: the relying party identifier is not a URL,
			// so AD FS prefixes it and the client-id never appears in "aud" (#373)
			'audience matches the AD FS resource identifier' => [
				'microsoft:identityserver:owncloud-client', 'microsoft:identityserver:owncloud-client', true
			],
			'audience is one of several in the aud array' => [
				'microsoft:identityserver:owncloud-client',
				['microsoft:identityserver:owncloud-client', 'urn:microsoft:userinfo'],
				true
			],
			// "audience" replaces the client-id rather than adding to it
			'configured audience replaces the client-id' => [
				'microsoft:identityserver:owncloud-client', 'owncloud-client', false
			],
			'audience list, aud matches a later entry' => [['resource-a', 'resource-b'], 'resource-b', true],
			'audience list, aud matches nothing' => [['resource-a', 'resource-b'], 'resource-c', false],
			// a broken "audience" must fail closed, never accept everything
			'audience is an empty list' => [[], 'owncloud-client', false],
			'audience is an empty string' => ['', '', false],
			'audience list holds only an empty string' => [[''], '', false],
			// a non-string is not a usable audience, so it is dropped and nothing
			// is left to match against
			'audience is not a string' => [123, '123', false],
			// strict comparison, on the token side which is not filtered: a loose
			// one would accept this, since PHP evaluates '0' == 0 as true
			'aud is the numeric form of the audience' => ['0', 0, false],
			'audience is set but aud is missing' => ['resource-a', null, false],
			// the value has to be copied exactly
			'audience differs only in case' => ['Resource-A', 'resource-a', false],
		];
	}

	/**
	 * @dataProvider providesConfiguredAudienceData
	 * @param string|array|int $configuredAudience
	 * @param string|array|null $aud
	 * @param bool $expectValid
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenConfiguredAudience($configuredAudience, $aud, bool $expectValid): void {
		$payload = ['exp' => \time() + 3600];
		if ($aud !== null) {
			$payload['aud'] = $aud;
		}

		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => $configuredAudience,
		], $payload);

		if (!$expectValid) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}

		$exp = $this->client->verifyToken('some-token');

		if ($expectValid) {
			self::assertEquals($payload['exp'], $exp);
		}
	}

	/**
	 * Both branches of verifyToken() have to honour the same expected audiences.
	 *
	 * @dataProvider providesConfiguredAudienceData
	 * @param string|array|int $configuredAudience
	 * @param string|array|null $aud
	 * @param bool $expectValid
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenIntrospectionConfiguredAudience($configuredAudience, $aud, bool $expectValid): void {
		$introspectionData = ['active' => true, 'exp' => \time() + 3600];
		if ($aud !== null) {
			$introspectionData['aud'] = $aud;
		}

		$this->client = $this->buildClientForIntrospection([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => $configuredAudience,
		], $introspectionData);

		if (!$expectValid) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}

		$exp = $this->client->verifyToken('opaque-token');

		if ($expectValid) {
			self::assertEquals($introspectionData['exp'], $exp);
		}
	}

	/**
	 * Configuring "audience" makes the audience authoritative on the
	 * introspection path too. The RFC 7662 "client_id" shortcut exists only
	 * because "aud" is optional there and cannot be relied on - once the admin
	 * has said what "aud" holds, it can, and the shortcut must not wave through a
	 * token this client obtained for a different resource. Otherwise the resource
	 * binding the admin just configured is silently unenforced for opaque tokens.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenIntrospectionConfiguredAudienceBeatsClientId(): void {
		$this->client = $this->buildClientForIntrospection([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => 'https://oc.example.com',
		], [
			'active' => true,
			'exp' => \time() + 3600,
			// issued to us as a client ...
			'client_id' => 'owncloud-client',
			// ... but addressed at a different resource server
			'aud' => 'https://other-api.example.com',
		]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('opaque-token');
	}

	/**
	 * The flip side: with "audience" configured and satisfied, the token is
	 * accepted no matter which client it was issued to.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenIntrospectionConfiguredAudienceIgnoresClientId(): void {
		$introspectionData = [
			'active' => true,
			'exp' => \time() + 3600,
			'client_id' => 'somebody-else',
			'aud' => 'https://oc.example.com',
		];
		$this->client = $this->buildClientForIntrospection([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => 'https://oc.example.com',
		], $introspectionData);

		self::assertEquals($introspectionData['exp'], $this->client->verifyToken('opaque-token'));
	}

	/**
	 * Each row is [configured "audience", expected log wording, rejects everything?].
	 * The wording has to distinguish the two cases: "no usable audience" locks the
	 * instance out entirely, whereas dropping one entry of several does not, and an
	 * admin grepping logs during an incident must be able to tell them apart.
	 *
	 * @return array
	 */
	public function providesUnusableAudienceData(): array {
		return [
			// nothing is dropped here - the list is simply empty - so this is the
			// case a "did we drop anything?" check alone would miss
			'empty list' => [[], 'No usable "audience"', true],
			'JSON number' => [123, 'No usable "audience"', true],
			'bool' => [true, 'No usable "audience"', true],
			'empty string' => ['', 'No usable "audience"', true],
			// one usable value survives, so tokens still work - say so, and do not
			// claim there is no usable audience
			'one good value, one unusable' => [['resource-a', 7], 'Ignoring unusable "audience" values', false],
		];
	}

	/**
	 * An unusable "audience" has to be reported as the configuration error it is -
	 * the rejection alone reads like an attack rather than a typo.
	 *
	 * @dataProvider providesUnusableAudienceData
	 * @param string|array|int|bool $configuredAudience
	 * @param string $expectedWording
	 * @param bool $expectRejection
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testUnusableAudienceIsReported(
		$configuredAudience,
		string $expectedWording,
		bool $expectRejection
	): void {
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::stringContains($expectedWording));

		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => $configuredAudience,
		], ['exp' => \time() + 3600, 'aud' => 'resource-a']);

		if ($expectRejection) {
			$this->expectException(OpenIDConnectClientException::class);
		}
		$this->client->verifyToken('some-token');
	}

	/**
	 * The config complaint is raised once per client instance, not once per call.
	 * The Client is a per-request singleton and getExpectedAudiences() runs more
	 * than once per request (twice with exchange-token mode), so without the guard
	 * a misconfigured audience would repeat itself in the log on every request.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testUnusableAudienceIsReportedOnlyOnce(): void {
		$this->logger->expects(self::once())->method('warning');

		$payload = ['exp' => \time() + 3600, 'aud' => 'resource-a'];
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			// keeps one usable value, so neither call throws and both reach the
			// warning site
			'audience' => ['resource-a', 7],
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * A well-formed "audience" must not produce a configuration complaint.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testUsableAudienceIsNotReported(): void {
		$this->logger->expects(self::never())->method('warning');

		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			'audience' => ['resource-a', 'resource-b'],
		], ['exp' => \time() + 3600, 'aud' => 'resource-b']);

		$this->client->verifyToken('some-token');
	}

	/**
	 * Configuring "audience" is what makes the audience authoritative, and then a
	 * claim naming us as the client the token was issued to must not override it:
	 * the token would be one this client legitimately obtained for a *different*
	 * resource (RFC 8707, RFC 8693) and had replayed here. Pins the strict half of
	 * the rule introduced for #373 so a later refactor cannot quietly widen it.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtIgnoresClientIdClaimWhenAudienceConfigured(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			// the admin has declared what "aud" holds ...
			'audience' => 'resource-a',
		], [
			'exp' => \time() + 3600,
			// ... so naming us as the client the token was issued to ...
			'client_id' => 'owncloud-client',
			// ... does not rescue a token addressed at somebody else
			'aud' => 'attacker-app',
		]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function providesClientNamingClaims(): array {
		return [
			// OpenID Connect Core 1.0 §2; what Keycloak and Entra ID v2.0 send
			'azp' => ['azp'],
			// Entra ID v1.0 tokens and AD FS have no "azp", they send "appid"
			'appid' => ['appid'],
			// RFC 7662 §2.2 names it "client_id"; also seen in JWT access tokens
			'client_id' => ['client_id'],
		];
	}

	/**
	 * Without a configured "audience" the check has to stay as permissive as it
	 * was before the audience check existed, or a patch-level app update locks
	 * out every provider that does not put the client-id in "aud" - which
	 * includes Keycloak, whose access token carries no "aud" at all (observed),
	 * and Entra ID v1.0 tokens, whose "aud" is the App ID URI. A claim naming us
	 * as the client the token was issued to is accepted instead, which still
	 * rejects a token minted for a *different* client (OC10-115).
	 *
	 * @dataProvider providesClientNamingClaims
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtAcceptsClientNamingClaimWithoutConfiguredAudience(
		string $claim
	): void {
		$payload = [
			'exp' => \time() + 3600,
			// no "aud" at all - Keycloak's shape
			$claim => 'owncloud-client',
		];
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * The compatibility fallback must not become a hole: a token issued to a
	 * different client of the same issuer is what OC10-115 was about, and it stays
	 * rejected whether or not "audience" is configured.
	 *
	 * @dataProvider providesClientNamingClaims
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtRejectsClientNamingClaimOfAnotherClient(
		string $claim
	): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], [
			'exp' => \time() + 3600,
			$claim => 'attacker-app',
		]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	/**
	 * A claim value that is not a string can never name this client, and must not
	 * match through a loose comparison either - "0" == 0 is the classic way a
	 * numeric client-id would turn the fallback into an accept-anything.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtRejectsNonStringClientNamingClaim(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => '0',
			'client-secret' => 'secret',
		], ['exp' => \time() + 3600, 'azp' => 0]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	/**
	 * An empty configured client-id names nobody, so a token whose client claim is
	 * also empty must not pass on an ''==='' comparison. getExpectedAudiences()
	 * already drops the empty string for the same reason; the fallback has to
	 * agree, or a blank "client-id" in the config would accept such a token.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtRejectsEmptyClientIdMatchingEmptyClaim(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => '',
			'client-secret' => 'secret',
		], ['exp' => \time() + 3600, 'azp' => '']);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	/**
	 * Taking the fallback is worth one log line: it is the difference between
	 * "your provider does not address us in aud" and "your configuration is
	 * complete", and it tells the admin how to make the check strict. Once per
	 * instance, for the same reason the unusable-audience complaint is.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtClientNamingClaimAcceptanceIsReportedOnce(): void {
		$this->logger->expects(self::once())
			->method('warning')
			->with(self::logicalAnd(
				self::stringContains('appid'),
				self::stringContains('audience'),
				// the value the admin would copy into the config
				self::stringContains('api://owncloud-client')
			));

		$payload = [
			'exp' => \time() + 3600,
			'aud' => 'api://owncloud-client',
			'appid' => 'owncloud-client',
		];
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function providesUnconfigurableAudiences(): array {
		return [
			// Keycloak's stock client; RFC 7662 leaves "aud" optional too
			'no aud claim' => [null],
			// Ory Hydra addresses a token at nobody like this
			'empty aud list' => [[]],
			'empty aud string' => [''],
		];
	}

	/**
	 * When the token carries no usable audience there is nothing an admin could put
	 * in "audience" to make the check strict, so saying so on every request is
	 * noise rather than advice. Accept on the client claim and stay quiet -
	 * Keycloak's stock client and any introspection response without "aud" land
	 * here, and both are legitimate configurations.
	 *
	 * @dataProvider providesUnconfigurableAudiences
	 * @param mixed $aud
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtUnconfigurableAudienceIsNotReported($aud): void {
		$this->logger->expects(self::never())->method('warning');

		$payload = ['exp' => \time() + 3600, 'azp' => 'owncloud-client'];
		if ($aud !== null) {
			$payload['aud'] = $aud;
		}
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * The access token shape of every identity provider ownCloud documents, so a
	 * later tightening of the audience check cannot lock one of them out unnoticed.
	 * "client-id" is 'owncloud-client' throughout, except where a provider only
	 * issues GUIDs.
	 *
	 * Each case records where its shape comes from and whether it was *observed*
	 * or taken from vendor documentation - they are not equally strong evidence.
	 *
	 * @return array<string, array{array<string, mixed>, bool, string|null}>
	 */
	public function providesIdpAccessTokenShapes(): array {
		return [
			// OBSERVED: Keycloak 26.0, confidential client, scope=openid, no
			// audience mapper. There is no "aud" claim at all, so no configured
			// "audience" can rescue it either - only "azp" names us.
			'Keycloak, stock client (observed)' => [
				['azp' => 'owncloud-client'],
				true,
				null,
			],
			// OBSERVED: the same realm with an oidc-audience-mapper added, which is
			// the Keycloak-side way to get the client-id into "aud".
			'Keycloak with audience mapper (observed)' => [
				['aud' => 'owncloud-client', 'azp' => 'owncloud-client'],
				true,
				'owncloud-client',
			],
			// Microsoft Entra ID v2.0 tokens (app manifest
			// requestedAccessTokenVersion = 2): "aud" is the resource application's
			// client-id, which is ownCloud's own client-id in the setup our docs
			// describe, where ownCloud is both the client and the API.
			// Claim names from Microsoft's own v2.0 example token.
			'Entra ID v2.0 token (vendor doc)' => [
				[
					'aud' => 'owncloud-client',
					'azp' => 'owncloud-client',
					'azpacr' => '0',
					'scp' => 'access_as_user',
					'ver' => '2.0',
				],
				true,
				'owncloud-client',
			],
			// Microsoft Entra ID v1.0 tokens - the default, since
			// requestedAccessTokenVersion is null unless someone sets it. "aud" is
			// the App ID URI, and there is no "azp"; the client is named by "appid".
			'Entra ID v1.0 token (vendor doc)' => [
				[
					'aud' => 'api://owncloud-client',
					'appid' => 'owncloud-client',
					'appidacr' => '0',
					'scp' => 'user_impersonation',
					'ver' => '1.0',
				],
				true,
				'api://owncloud-client',
			],
			// AD FS, as reported in #373: the relying party identifier rendered as
			// "microsoft:identityserver:<identifier>", plus "appid" like Entra v1.0.
			'AD FS token (#373)' => [
				[
					'aud' => 'microsoft:identityserver:owncloud-client',
					'appid' => 'owncloud-client',
					'apptype' => 'Confidential',
					'scp' => 'email profile openid',
					'ver' => '1.0',
				],
				true,
				'microsoft:identityserver:owncloud-client',
			],
			// Kopano Konnect passes the client-id as the access token audience:
			// oidc/provider/handlers.go calls makeAccessToken(ctx, ar.ClientID, ...).
			'Kopano Konnect token (source)' => [
				['aud' => 'owncloud-client'],
				true,
				'owncloud-client',
			],
			// OneLogin API authorization: "aud" is the list of configured API
			// audience URIs, never the client-id, and "azp" carries the OIDC app id.
			'OneLogin API authorization token (vendor doc)' => [
				[
					'aud' => ['https://example.com', 'https://example.com/contacts'],
					'azp' => 'owncloud-client',
					'scope' => 'openid profile',
				],
				true,
				'https://example.com/contacts',
			],
			// A token from the same issuer for a different client stays rejected in
			// both modes - the finding all of this exists for (OC10-115).
			'another client of the same issuer' => [
				['aud' => 'other-app', 'azp' => 'other-app'],
				false,
				null,
			],
		];
	}

	/**
	 * With no "audience" configured - the state every existing install upgrades
	 * into - each documented provider must still authenticate.
	 *
	 * @dataProvider providesIdpAccessTokenShapes
	 * @param array<string, mixed> $claims
	 * @param bool $expectAccepted
	 * @param string|null $strictAudience
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testIdpAccessTokenShapeWithoutConfiguredAudience(
		array $claims,
		bool $expectAccepted,
		?string $strictAudience
	): void {
		$payload = \array_merge(['exp' => \time() + 3600], $claims);
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], $payload);

		if (!$expectAccepted) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}
		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * And with "audience" configured to what the provider sends, the check is
	 * strict and still passes - except for Keycloak's stock client, which sends no
	 * "aud" at all, so there is no value to configure. That asymmetry is the whole
	 * reason the client-naming fallback exists rather than only the config key.
	 *
	 * @dataProvider providesIdpAccessTokenShapes
	 * @param array<string, mixed> $claims
	 * @param bool $expectAccepted
	 * @param string|null $strictAudience
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testIdpAccessTokenShapeWithConfiguredAudience(
		array $claims,
		bool $expectAccepted,
		?string $strictAudience
	): void {
		$payload = \array_merge(['exp' => \time() + 3600], $claims);
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
			// nothing usable to declare: configure the client-id, which is what an
			// admin would try, and watch it fail closed
			'audience' => $strictAudience ?? 'owncloud-client',
		], $payload);

		if ($strictAudience === null) {
			$this->expectException(OpenIDConnectClientException::class);
			$this->expectExceptionMessage('Token audience does not match the expected audience');
		}
		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * A matching "aud" is the normal path and must not be reported as a
	 * compatibility fallback, even when a client-naming claim is present too -
	 * which is exactly what a spec-compliant provider sends.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtMatchingAudienceIsNotReportedAsFallback(): void {
		$this->logger->expects(self::never())->method('warning');

		$payload = [
			'exp' => \time() + 3600,
			'aud' => 'owncloud-client',
			'azp' => 'owncloud-client',
		];
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * The JWT-branch counterpart of the introspection branch's
	 * 'no client-id configured, no claim names us' case: with nothing to compare
	 * against, an unverifiable token must be rejected and not slip through on a
	 * null-equals-null comparison.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenJwtWithoutClientIdOrAudience(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => null,
			'client-secret' => 'secret',
		], ['exp' => \time() + 3600]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	/**
	 * "audience" is usable on its own, so a deployment that identifies itself
	 * only by resource does not have to configure a client-id to be verifiable.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenAudienceWithoutClientId(): void {
		$payload = ['exp' => \time() + 3600, 'aud' => 'resource-a'];
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => null,
			'client-secret' => 'secret',
			'audience' => 'resource-a',
		], $payload);

		self::assertEquals($payload['exp'], $this->client->verifyToken('some-token'));
	}

	/**
	 * An "aud" of [] is what Ory Hydra emits when a token is addressed at nobody;
	 * it must not be read as "addressed at everybody".
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenEmptyAudienceArray(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], ['exp' => \time() + 3600, 'aud' => []]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}

	public function providesExchangeTokenAudienceData(): array {
		return [
			'no audience configured falls back to the client-id' => [null, 'owncloud-client'],
			'the configured audience is requested' => [
				'microsoft:identityserver:owncloud-client', 'microsoft:identityserver:owncloud-client'
			],
			'the first entry of an audience list is requested' => [['resource-a', 'resource-b'], 'resource-a'],
			// nothing usable to ask for; an empty string makes the library omit the
			// parameter rather than sending a bogus one
			'an empty audience asks for nothing' => [[], ''],
		];
	}

	/**
	 * The exchanged token has to pass the same audience check verifyToken()
	 * applies, so the exchange must ask for the configured audience. Requesting
	 * the client-id - as this did before "audience" existed - would make the
	 * exchanged token fail the very check it has to pass.
	 *
	 * @dataProvider providesExchangeTokenAudienceData
	 * @param string|array|null $configuredAudience
	 * @param string $expectedRequestedAudience
	 * @throws OpenIDConnectClientException
	 */
	public function testExchangeTokenRequestsExpectedAudience(
		$configuredAudience,
		string $expectedRequestedAudience
	): void {
		$openIdConfig = [
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		];
		if ($configuredAudience !== null) {
			$openIdConfig['audience'] = $configuredAudience;
		}
		$this->config->method('getSystemValue')->willReturnCallback(
			static function ($key) use ($openIdConfig) {
				return $key === 'openid-connect' ? $openIdConfig : null;
			}
		);

		$client = $this->getMockBuilder(Client::class)
			->setConstructorArgs([$this->config, $this->urlGenerator, $this->session, $this->logger, $this->clientService])
			->onlyMethods(['requestTokenExchange'])
			->getMock();
		$client->expects(self::once())
			->method('requestTokenExchange')
			->with('subject-token', 'urn:ietf:params:oauth:token-type:access_token', $expectedRequestedAudience)
			->willReturn((object)['access_token' => 'exchanged-token']);

		self::assertEquals('exchanged-token', $client->exchangeToken('subject-token', 'access-token'));
	}

	/**
	 * A nested "aud" must be rejected without emitting an "Array to string
	 * conversion" warning - which is what \array_intersect() would have done.
	 *
	 * @throws JsonException
	 * @throws OpenIDConnectClientException
	 */
	public function testVerifyTokenNestedAudience(): void {
		$this->client = $this->buildClientForJwt([
			'provider-url' => 'https://example.net',
			'client-id' => 'owncloud-client',
			'client-secret' => 'secret',
		], ['exp' => \time() + 3600, 'aud' => [['owncloud-client']]]);

		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('Token audience does not match the expected audience');

		$this->client->verifyToken('some-token');
	}
}
