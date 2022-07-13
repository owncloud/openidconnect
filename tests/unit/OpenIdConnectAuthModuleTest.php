<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Miroslav Bauer <Miroslav.Bauer@cesnet.cz>
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

namespace OCA\OpenIdConnect\Tests\Unit;

use Jumbojett\OpenIDConnectClientException;
use OC\Memcache\ArrayCache;
use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class OpenIdConnectAuthModuleTest extends TestCase {

	/**
	 * @var OpenIdConnectAuthModule
	 */
	private $authModule;
	/**
	 * @var MockObject | IUserManager
	 */
	private $manager;
	/**
	 * @var MockObject | ILogger
	 */
	private $logger;
	/**
	 * @var MockObject | ICacheFactory
	 */
	private $cacheFactory;
	/**
	 * @var MockObject | UserLookupService
	 */
	private $lookupService;
	/**
	 * @var MockObject | Client
	 */
	private $client;
	/**
	 * @var MockObject | AutoProvisioningService
	 */
	private $autoProvisioningService;

	protected function setUp(): void {
		parent::setUp();
		$this->manager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->lookupService = $this->createMock(UserLookupService::class);

		$config = $this->createMock(IConfig::class);
		$generator = $this->createMock(IURLGenerator::class);
		$session = $this->createMock(ISession::class);
		$this->client = $this->getMockBuilder(Client::class)
			->onlyMethods(['getUserInfo', 'refreshToken', 'signOut', 'getOpenIdConfig', 'verifyJWTsignature', 'getAccessTokenPayload', 'setAccessToken', 'introspectToken', 'getAutoProvisionConfig'])
			->setConstructorArgs([$config, $generator, $session, $this->logger])
			->getMock();

		$this->autoProvisioningService = $this->createMock(AutoProvisioningService::class);
		$this->authModule = new OpenIdConnectAuthModule(
			$this->manager,
			$this->logger,
			$this->cacheFactory,
			$this->lookupService,
			$this->client,
			$this->autoProvisioningService
		);
	}

	public function testNoBearer(): void {
		$request = $this->createMock(IRequest::class);

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	public function testNotConfigured(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	public function testInvalidToken(): void {
		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->client->method('getAccessTokenPayload')->willReturn((object)[]);
		$this->client->method('verifyJWTsignature')->willReturn(false);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Token cannot be verified.'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	public function testInvalidTokenWithIntrospection(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['use-token-introspection-endpoint' => true]);
		$this->client->method('introspectToken')->willReturn((object)['error' => 'expired']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Verifying token failed: expired'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	public function testInvalidTokenWithIntrospectionNotActive(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['use-token-introspection-endpoint' => true]);
		$this->client->method('introspectToken')->willReturn((object)['active' => false]);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->logger->method('logException')->with(new OpenIDConnectClientException('Token (as per introspection) is inactive'));

		$return = $this->authModule->auth($request);
		self::assertNull($return);
	}

	public function testValidTokenWithIntrospection(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['use-token-introspection-endpoint' => true]);
		$this->client->method('introspectToken')->willReturn((object)['active' => true, 'exp' => \time() + 3600]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@example.com']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertEquals($user, $return);
	}

	public function testValidTokenWithJWT(): void {
		$this->client->method('getOpenIdConfig')->willReturn(['use-token-introspection-endpoint' => false]);
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => \time() + 3600]);
		$this->client->method('getUserInfo')->willReturn((object)['email' => 'foo@example.com']);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());
		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$return = $this->authModule->auth($request);
		self::assertEquals($user, $return);
	}

	public function testExpiredCachedToken(): void {
		$this->expectException(LoginException::class);
		$this->expectExceptionMessage('OpenID Connect token expired');

		$cache = new ArrayCache();
		$cache->set(1234567890, ['exp' => \time() - 5]);

		$this->client->method('getOpenIdConfig')->willReturn([]);
		$this->cacheFactory->method('create')->willReturn($cache);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');

		$this->authModule->auth($request);
	}

	public function testValidTokenWithAutoUpdate(): void {
		$userInfo = (object)['email' => 'foo@example.com'];
		$openIdConfig = ['auto-provision' => [ 'update' => ['enabled' => true ]]];
		$this->client->method('getOpenIdConfig')->willReturn($openIdConfig);
		$this->client->method('getAutoProvisionConfig')->willReturn($openIdConfig['auto-provision']);
		$this->client->method('getUserInfo')->willReturn($userInfo);
		$this->client->method('verifyJWTsignature')->willReturn(true);
		$this->client->method('getAccessTokenPayload')->willReturn((object)['exp' => time() + 100]);
		$this->autoProvisioningService->method('autoUpdateEnabled')->willReturn(true);
		$this->cacheFactory->method('create')->willReturn(new ArrayCache());

		$user = $this->createMock(IUser::class);
		$this->lookupService->expects(self::once())->method('lookupUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer 1234567890');
		$this->autoProvisioningService->expects(self::once())->method('updateAccountInfo')->with($user, $userInfo)->willReturn(null);
		$this->authModule->auth($request);
	}
}
