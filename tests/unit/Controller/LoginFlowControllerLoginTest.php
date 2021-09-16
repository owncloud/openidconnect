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

namespace OCA\OpenIdConnect\Tests\Unit\Controller;

use JuliusPC\OpenIDConnectClientException;
use OC\HintException;
use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Controller\LoginFlowController;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoginFlowControllerLoginTest extends TestCase {

	/**
	 * @var LoginFlowController
	 */
	private $controller;
	/**
	 * @var MockObject | UserLookupService
	 */
	private $userLookup;
	/**
	 * @var MockObject | IRequest
	 */
	private $request;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $memCacheFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userLookup = $this->createMock(UserLookupService::class);
		$this->userSession = $this->createMock(Session::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->client = $this->createMock(Client::class);
		$this->memCacheFactory = $this->createMock(ICacheFactory::class);

		$this->controller = new LoginFlowController(
			'openidconnect',
			$this->request,
			$this->userLookup,
			$this->userSession,
			$this->session,
			$this->logger,
			$this->client,
			$this->memCacheFactory
		);
	}

	public function testLoginNotConfigured(): void {
		$this->expectException(HintException::class);
		$this->expectExceptionMessage('Configuration issue in openidconnect app');

		$this->controller->login();
	}

	public function testLoginAuthenticateThrowsException(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('authenticate')->willThrowException(new OpenIDConnectClientException('foo'));
		$this->expectException(HintException::class);
		$this->expectExceptionMessage('Error in OpenIdConnect:foo');

		$this->controller->login();
	}

	public function testLoginNoUserInfo(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('requestUserInfo')->willReturn([]);
		$this->expectException(LoginException::class);
		$this->expectExceptionMessage('No user information available.');

		$this->controller->login();
	}

	public function testLoginUnknownUser(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->userLookup->method('lookupUser')->willThrowException(new LoginException('User foo is not known.'));
		$this->expectException(LoginException::class);
		$this->expectExceptionMessage('User foo is not known.');

		$this->controller->login();
	}

	public function testLoginCreateSessionFailed(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(false);

		$response = $this->controller->login();
		self::assertEquals(new RedirectResponse('/'), $response);
	}

	public function testLoginCreateSuccess(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		$this->session->expects(self::exactly(3))->method('set')->withConsecutive(
			['oca.openid-connect.id-token', 'id'],
			['oca.openid-connect.access-token', 'access'],
			['oca.openid-connect.refresh-token', 'refresh']
		);

		$response = $this->controller->login();

		self::assertEquals('http://localhost/index.php/apps/files/', $response->getRedirectURL());
	}

	public function testLoginCreateSuccessWithRedirect(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@exmaple.net']);
		$this->client->method('getIdToken')->willReturn('id');
		$this->client->method('getAccessToken')->willReturn('access');
		$this->client->method('getRefreshToken')->willReturn('refresh');
		$this->client->method('readRedirectUrl')->willReturn('index.php/apps/oauth2/foo/bla');
		$user = $this->createMock(IUser::class);
		$this->userLookup->method('lookupUser')->willReturn($user);
		$this->userSession->method('createSessionToken')->willReturn(true);
		$this->userSession->method('loginUser')->willReturn(true);
		$this->session->expects(self::exactly(3))->method('set')->withConsecutive(
			['oca.openid-connect.id-token', 'id'],
			['oca.openid-connect.access-token', 'access'],
			['oca.openid-connect.refresh-token', 'refresh']
		);

		$response = $this->controller->login();

		self::assertEquals('http://localhost/index.php/apps/oauth2/foo/bla', $response->getRedirectURL());
	}
}
