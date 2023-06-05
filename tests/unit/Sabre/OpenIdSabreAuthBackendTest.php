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

namespace OCA\OpenIdConnect\Tests\Unit\Sabre;

use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCA\OpenIdConnect\Sabre\OpenIdSabreAuthBackend;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Test\TestCase;

class OpenIdSabreAuthBackendTest extends TestCase {
	/**
	 * @var OpenIdSabreAuthBackend
	 */
	private $backend;
	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | Session
	 */
	private $userSession;
	/**
	 * @var MockObject | IRequest
	 */
	private $request;
	/**
	 * @var MockObject | OpenIdConnectAuthModule
	 */
	private $authModule;
	/**
	 * @var MockObject | RequestInterface
	 */
	private $sabreRequest;
	/**
	 * @var MockObject | ResponseInterface
	 */
	private $sabreResponse;

	protected function setUp(): void {
		parent::setUp();
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(Session::class);
		$this->request = $this->createMock(IRequest::class);
		$this->authModule = $this->createMock(OpenIdConnectAuthModule::class);

		$this->backend = $this->getMockBuilder(OpenIdSabreAuthBackend::class)
			->setConstructorArgs([$this->session, $this->userSession, $this->request, $this->authModule])
			->setMethods(['setupFilesystem'])
			->getMock();

		$this->sabreRequest = $this->createMock(RequestInterface::class);
		$this->sabreResponse = $this->createMock(ResponseInterface::class);

		$this->sabreRequest->method('getHeader')->with('Authorization')->willReturn('Bearer 1234567890');
	}

	public function testLoggedInWithInvalidToken(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->authModule->expects(self::once())->method('authToken')->with('Bearer', '1234567890')->willReturn(null);

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([false, 'Bearer/PoP token was incorrect'], $return);
	}

	public function testLoggedInWithValidToken(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->authModule->expects(self::once())->method('authToken')->with('Bearer', '1234567890')->willReturn($user);
		$this->backend->expects(self::once())->method('setupFilesystem')->with('alice');

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([true, 'principals/users/alice'], $return);
	}

	public function testNotLoggedInWithInvalidToken(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->userSession->expects(self::once())->method('tryAuthModuleLogin')->with($this->request)->willReturn(false);

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([false, 'Bearer/PoP token was incorrect'], $return);
	}

	public function testNotLoggedInAndExceptionInLogin(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->userSession->expects(self::once())->method('tryAuthModuleLogin')->with($this->request)->willThrowException(new \Exception(':boom:'));

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([false, 'Bearer/PoP token was incorrect'], $return);
	}

	public function testNotLoggedInWithValidToken(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->userSession->expects(self::once())->method('tryAuthModuleLogin')->with($this->request)->willReturn(true);
		$this->backend->expects(self::exactly(2))->method('setupFilesystem')->withConsecutive(
			[''],
			['alice']
		);

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([true, 'principals/users/alice'], $return);
	}

	public function testTokenExpiry(): void {
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->session->method('get')->with(OpenIdSabreAuthBackend::DAV_AUTHENTICATED)->willReturn('alice');

		$this->authModule->expects(self::once())->method('authToken')->with('Bearer', '1234567890')->willThrowException(new LoginException(':zzz:'));

		$return = $this->backend->check($this->sabreRequest, $this->sabreResponse);
		self::assertEquals([false, 'Bearer/PoP token was incorrect'], $return);
	}
}
