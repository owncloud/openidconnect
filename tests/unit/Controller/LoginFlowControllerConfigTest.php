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

use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Controller\LoginFlowController;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoginFlowControllerConfigTest extends TestCase {

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
			$this->request, $this->userLookup, $this->userSession, $this->session, $this->logger, $this->client, $this->memCacheFactory
		);
	}

	public function testConfigNotConfigured(): void {
		$response = $this->controller->config();
		self::assertEquals(new JSONResponse(), $response);
	}

	public function testConfig(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getWellKnownConfig')->willReturn('{"foo": "bar"}');

		$response = $this->controller->config();
		self::assertEquals(new JSONResponse('{"foo": "bar"}'), $response);
	}
}
