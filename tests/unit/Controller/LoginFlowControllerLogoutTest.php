<?php

namespace OCA\OpenIdConnect\Tests\Unit\Controller;

use Jumbojett\OpenIDConnectClientException;
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

class LoginFlowControllerLogoutTest extends TestCase {

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

	public function testLogouNotConfigured(): void {
		$this->logger->expects(self::once())->method('warning')->with('OpenID::logout: OpenID is not properly configured');

		$this->controller->logout();
	}

	public function testLogoutNotLoggedIn(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->logger->expects(self::once())->method('warning')->with('OpenID::logout: missing parameters: iss= and sid= and no active session');

		$this->controller->logout();
	}

	public function testLogoutWithSession(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->expects(self::once())->method('logout');

		$this->controller->logout();
	}
}
