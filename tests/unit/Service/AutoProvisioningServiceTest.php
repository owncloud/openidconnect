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

namespace OCA\OpenIdConnect\Tests\Unit\Service;

use OC\User\LoginException;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class AutoProvisioningServiceTest extends TestCase {

	/**
	 * @var IUserManager|MockObject
	 */
	private $userManager;
	/**
	 * @var IGroupManager|MockObject
	 */
	private $groupManager;
	/**
	 * @var IAvatarManager|MockObject
	 */
	private $avatarManager;
	/**
	 * @var ILogger|MockObject
	 */
	private $logger;
	/**
	 * @var IConfig|MockObject
	 */
	private $config;
	/**
	 * @var AutoProvisioningService
	 */
	private $autoProvisioningService;
	/**
	 * @var IClientService|MockObject
	 */
	private $clientService;

	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->avatarManager = $this->createMock(IAvatarManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->logger = $this->createMock(ILogger::class);
		$this->config = $this->createMock(IConfig::class);

		$this->autoProvisioningService = new AutoProvisioningService(
			$this->userManager,
			$this->groupManager,
			$this->avatarManager,
			$this->clientService,
			$this->logger,
			$this->config
		);
	}

	/**
	 * @dataProvider providesConfig
	 * @param bool $expected
	 * @param array|null $config
	 */
	public function testEnabled(bool $expected, array $config = null): void {
		$this->config->method('getSystemValue')->willReturn($config);
		self::assertEquals($expected, $this->autoProvisioningService->enabled());
	}

	public function providesConfig(): array {
		return [
			[false, null],
			[false, []],
			[false, ['auto-provision' => []]],
			[false, ['auto-provision' => ['enabled' => false]]],
			[true, ['auto-provision' => ['enabled' => true]]],
		];
	}

	/**
	 * @dataProvider providesProvisioningData
	 * @param bool $expectsUserToBeCreated
	 * @param bool $expectEmailToBeSet
	 * @param bool $expectDisplayName
	 * @param bool $expectsAvatar
	 * @param bool $expectsGroupMembership
	 * @param array $config
	 * @param object $userInfo
	 * @throws LoginException
	 */
	public function testCreateUser(
		bool $expectsUserToBeCreated,
		bool $expectEmailToBeSet,
		bool $expectDisplayName,
		bool $expectsAvatar,
		bool $expectsGroupMembership,
		array $config,
		object $userInfo
	): void {
		if ($expectsUserToBeCreated) {
			$user = $this->createMock(IUser::class);
			$user->expects($expectEmailToBeSet ? self::once() : self::never())->method('setEMailAddress');
			$user->expects($expectDisplayName ? self::once() : self::never())->method('setDisplayName');
			$this->userManager->expects(self::once())->method('createUser')->willReturn($user);
			if ($expectsAvatar) {
				$resp = $this->createMock(IResponse::class);
				$resp->expects(self::once())->method('getBody')->willReturn('123456');
				$client = $this->createMock(IClient::class);
				$client->expects(self::once())->method('get')->willReturn($resp);
				$this->clientService->expects(self::once())->method('newClient')->willReturn($client);

				$avatar = $this->createMock(IAvatar::class);
				$avatar->expects(self::once())->method('set')->with('123456');
				$this->avatarManager->expects(self::once())->method('getAvatar')->willReturn($avatar);
			}
			if ($expectsGroupMembership) {
				$group = $this->createMock(IGroup::class);
				$group->expects(self::once())->method('addUser');
				$this->groupManager->expects(self::once())->method('get')->with('oidc-group')->willReturn($group);
			}
		} else {
			$this->expectException(LoginException::class);
		}
		$this->config->method('getSystemValue')->willReturn($config);
		$this->autoProvisioningService->createUser($userInfo);
	}

	public function providesProvisioningData(): array {
		return [
			[false, false, false, false, false, [], (object)['email' => 'alice@example.net']],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true]], (object)['email' => 'alice@example.net']],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true]], (object)[]],
			[true, false, false, false, false, ['auto-provision' => ['enabled' => true]], (object)['email' => 'alice@example.net']],
			[true, true, false, false, false, ['auto-provision' => ['enabled' => true, 'email-claim' => 'email']], (object)['email' => 'alice@example.net']],
			[true, true, false, false, false, ['mode' => 'email', 'auto-provision' => ['enabled' => true]], (object)['email' => 'alice@example.net']],
			[true, false, true, false, false, ['mode' => 'userid', 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name']], (object)['email' => 'alice@example.net', 'name' => 'Alice']],
			[true, false, false, true, false, ['mode' => 'userid', 'auto-provision' => ['enabled' => true, 'picture-claim' => 'picture']], (object)['email' => 'alice@example.net', 'picture' => 'http://']],
			[true, false, false, false, true, ['mode' => 'userid', 'auto-provision' => ['enabled' => true, 'groups' => ['oidc-group']]], (object)['email' => 'alice@example.net', 'picture' => 'http://']],
			[true, false, false, false, false, ['auto-provision' => ['enabled' => true, 'provisioning-claim' => 'foo', 'provisioning-attribute' => 'bar']], (object)['email' => 'alice@example.net', 'foo' => ['bar']]],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true, 'provisioning-claim' => 'foo']], (object)['email' => 'alice@example.net', 'foo' => ['bar']]],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true, 'provisioning-claim' => 'foo']], (object)['email' => 'alice@example.net', 'foo' => 'must-be-array']],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true, 'provisioning-claim' => 'foo']], (object)['email' => 'alice@example.net', 'foo' => null]],
			[false, false, false, false, false, ['auto-provision' => ['enabled' => true, 'provisioning-claim' => 'foo']], (object)['email' => 'alice@example.net']],
		];
	}
}
