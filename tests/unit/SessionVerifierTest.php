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

use OC\HintException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCA\OpenIdConnect\SessionVerifier;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ISession;
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
	 * @var MockObject | EventDispatcherInterface
	 */
	private $dispatcher;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | Logger
	 */
	private $logger;
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
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(Logger::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->client = $this->createMock(Client::class);

		$this->sessionVerifier = new SessionVerifier($this->logger, $this->session, $this->userSession, $this->cacheFactory, $this->dispatcher, $this->client);
	}

	public function testOpenIdSessionExpired(): void {
		$this->session->method('get')->with('oca.openid-connect.session-id')->willReturn('SID-1234');
		$cache = $this->createMock(ICache::class);
		$cache->expects(self::once())->method('get')->with('SID-1234')->willReturn(false);
		$this->cacheFactory->expects(self::once())->method('create')->willReturn($cache);

		$this->userSession->expects(self::once())->method('logout');

		$this->sessionVerifier->verifySession();
	}

	public function testNoAccessTokenInSession(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, null);

		$this->userSession->expects(self::never())->method('logout');

		$this->sessionVerifier->verifySession();
	}

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
		$this->session->expects(self::exactly(3))->method('set');

		$this->sessionVerifier->verifySession();
	}

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

	public function testValidFreshAccessTokenWithIntrospection(): void {
		$this->session->method('get')->withConsecutive(
			['oca.openid-connect.session-id'],
			['oca.openid-connect.access-token']
		)->willReturnOnConsecutiveCalls(null, 'access-123456');
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->expects(self::exactly(2))->method('create')->with('oca.openid-connect')->willReturn($cache);
		$exp = \time() + 3600;
		$this->client->method('getOpenIdConfig')->willReturn(['use-token-introspection-endpoint' => true]);
		$this->client->method('introspectToken')->willReturn((object)['active' => true, 'exp' => $exp]);

		$cache->expects(self::once())->method('set')->with('access-123456', $exp);
		$this->userSession->expects(self::never())->method('logout');
		$this->client->expects(self::once())->method('setAccessToken')->with('access-123456');

		$this->sessionVerifier->verifySession();
	}

	public function provideOpenIdConfig() {
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
	 */
	public function testLogoutRedirect($openIdConfig, $expectedLogoutRedirectUri) {
		$this->client->method('getOpenIdConfig')
			->willReturn($openIdConfig);
		$this->client->expects($this->once())
			->method('signOut')
			->with($this->anything(), $expectedLogoutRedirectUri);
		$this->sessionVerifier->afterLogout('dummy', 'dummy');
	}
}
