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
use OC\HintException;
use OC\Http\Client\ClientService;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCA\OpenIdConnect\SessionVerifier;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

class SessionVerifierTest extends TestCase {
	/**
	 * @var SessionVerifier
	 */
	private $sessionVerifier;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $cacheFactory;
	/**
	 * @var MockObject | Client
	 */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		$dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(Logger::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);

		$config = $this->createMock(IConfig::class);
		$generator = $this->createMock(IURLGenerator::class);
		$clientService = $this->createMock(ClientService::class);
		$this->client = $this->getMockBuilder(Client::class)
			->onlyMethods(['refreshToken', 'signOut', 'getOpenIdConfig', 'verifyJWTsignature', 'getAccessTokenPayload', 'setAccessToken', 'introspectToken'])
			->setConstructorArgs([$config, $generator, $this->session, $logger, $clientService])
			->getMock();

		$this->sessionVerifier = new SessionVerifier($logger, $this->session, $this->userSession, $this->cacheFactory, $dispatcher, $this->client);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testOpenIdSessionExpired(): void {
		$this->session->method('get')->with('oca.openid-connect.session-id')->willReturn('SID-1234');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('SID-1234')->willReturn(false);
		$this->cacheFactory->expects(self::once())->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testNoAccessTokenInSession(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, null);

		$this->userSession->expects(self::never())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidCachedAccessToken(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() + 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenNoRefresh(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::never())->method('refreshToken');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenRefresh(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token'],
			['oca.openid-connect.id-token'],
			['oca.openid-connect.refresh-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456', 'id-123456', 'refresh-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('refreshToken')->with('refresh-123456')->willReturn((object)[]);
		$this->session->expects(self::exactly(2))->method('set');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws JsonException
	 */
	public function testInvalidCachedAccessTokenRefreshError(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token'],
			['oca.openid-connect.id-token'],
			['oca.openid-connect.refresh-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456', 'id-123456', 'refresh-123456');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('access-123456')->willReturn(\time() - 3600);
		$this->cacheFactory->expects(self::once())->method('create')->with('oca.openid-connect')->willReturn($cache);

		$this->expectException(HintException::class);
		$this->expectExceptionMessage('Verifying token failed: access_denied');
		$this->userSession->expects(self::once())->method('logout');
		$this->client->expects(self::once())->method('refreshToken')->with('refresh-123456')->willReturn((object)['error' => 'access_denied']);
		$this->session->expects(self::never())->method('set');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidFreshAccessToken(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::exactly(2))->method('create')->with('oca.openid-connect')->willReturn($cache);
		$exp = \time() + 3600;
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => $exp]);

		$cache->expects(self::once())->method('set')->with('access-123456', $exp);
		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('setAccessToken')->with('access-123456');

		$this->sessionVerifier->verifySession();
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 * @throws JsonException
	 */
	public function testValidFreshAccessTokenWithIntrospection(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::exactly(2))->method('create')->with('oca.openid-connect')->willReturn($cache);
		$exp = \time() + 3600;
		$this->client->method('introspectToken')->willReturn((object)['active' => true, 'exp' => $exp]);

		$cache->expects(self::once())->method('set')->with('access-123456', $exp);
		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('setAccessToken')->with('access-123456');

		$this->sessionVerifier->verifySession();
	}

	public function provideOpenIdConfig(): array {
		return [
			[null, null],
			[[], null],
			[['post_logout_redirect_uri' => null], null],
			[['post_logout_redirect_uri' => 'http://localhost'], 'http://localhost'],
		];
	}

	/**
	 * @dataProvider provideOpenIdConfig
	 * @param string[]|null $openIdConfig
	 * @param string $expectedLogoutRedirectUri
	 * @throws JsonException
	 */
	public function testLogoutRedirect($openIdConfig, $expectedLogoutRedirectUri): void {
		$this->client->method('getOpenIdConfig')
			->willReturn($openIdConfig);
		$this->client->expects($this->once())
			->method('signOut')
			->with($this->anything(), $expectedLogoutRedirectUri);
		$this->sessionVerifier->afterLogout('dummy', 'dummy');
	}
}
