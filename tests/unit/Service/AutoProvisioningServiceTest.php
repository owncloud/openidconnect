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

namespace OCA\OpenIdConnect\Tests\Unit\Service;

use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Service\AutoProvisioningService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAvatar;
use OCP\IAvatarManager;
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
	 * @var Client|MockObject
	 */
	private $client;
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
		$logger = $this->createMock(ILogger::class);
		$this->client = $this->createMock(Client::class);

		$this->autoProvisioningService = new AutoProvisioningService(
			$this->userManager,
			$this->groupManager,
			$this->avatarManager,
			$this->clientService,
			$logger,
			$this->client,
		);
	}

	/**
	 * @dataProvider providesConfig
	 * @param bool $expected
	 * @param array|null $config
	 */
	public function testEnabled(bool $expected, array $config = null): void {
		$this->client->method('getOpenIdConfig')->willReturn($config);
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
		$this->client->method('getOpenIdConfig')->willReturn($config);
		$this->autoProvisioningService->createUser($userInfo);
	}

	/**
	 * @dataProvider providesAutoUpdateConfig
	 * @param bool $expected
	 * @param array|null $config
	 */
	public function testAutoUpdateEnabled(bool $expected, array $config = null): void {
		$this->client->method('getOpenIdConfig')->willReturn($config);
		self::assertEquals($expected, $this->autoProvisioningService->autoUpdateEnabled());
	}

	public function providesAutoUpdateConfig(): array {
		return [
			[false, null],
			[false, []],
			[false, ['auto-provision' => []]],
			[false, ['auto-provision' => ['update' => []]]],
			[false, ['auto-provision' => ['update' => ['enabled' => false]]]],
			[false, ['auto-provision' => ['update' => ['enabled' => true]]]],
		];
	}

	/**
	 * @dataProvider providesAttributeUpdates
	 * @param bool $expectException
	 * @param bool $force
	 * @param bool $expectEmailToBeSet
	 * @param bool $expectDisplayName
	 * @param bool $canChangeEmail
	 * @param bool $canChangeDN
	 * @param string $currentEmail
	 * @param string $currentDN
	 * @param array $config
	 * @param array $userInfo
	 * @return void
	 */
	public function testAutoUpdate(
		bool $expectException,
		bool $force,
		bool $expectEmailToBeSet,
		bool $expectDisplayName,
		bool $canChangeEmail,
		bool $canChangeDN,
		string $currentEmail,
		string $currentDN,
		array $config,
		array $userInfo
	): void {
		$user = $this->createMock(IUser::class);
		$this->client->method('getOpenIdConfig')->willReturn($config);

		if ($expectException) {
			$this->expectException(LoginException::class);
		} else {
			$user->method('canChangeMailAddress')->willReturn($canChangeEmail);
			$user->method('canChangeDisplayName')->willReturn($canChangeDN);
			$user->method('getEMailAddress')->willReturn($currentEmail);
			$user->method('getDisplayName')->willReturn($currentDN);

			$user->expects($expectEmailToBeSet ? self::once() : self::never())->method('setEMailAddress')->with($userInfo['email']);
			$user->expects($expectDisplayName ? self::once() : self::never())->method('setDisplayName')->with($userInfo['name']);
			$this->userManager->expects(self::once())->method('createUser')->willReturn($user);
		}
		$this->autoProvisioningService->updateAccountInfo($user, $userInfo, $force);
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

	public function providesAttributeUpdates(): array {
		return [
			# 1. update disabled, not forced
			[true, false, false, false, false, false, '', '', [], ['email' => 'alice@example.net']],
			# 2. update disabled by config, but forced on a newly provisioned account
			[false, true, true, true, false, false, '', '', ['auto-provision' => ['enabled' => false, 'update' => ['enabled' => false], 'email-claim' => 'email', 'display-name-claim' => 'name']], ['email' => 'alice@example.net', 'name' => 'John']],
			# 3. update enabled, but missing claims in configuration
			[false, false, false, false, true, true, '', '', ['auto-provision' => ['enabled' => false, 'update'=> ['enabled'=> true]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 4. update enabled, used together with auto-provisioning mode
			[false, false, true, true, true, true, '', '', ['auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 5. update enabled, used without auto-provisioning mode
			[false, false, true, true, true, true, '', '', ['auto-provision' => ['enabled' => false, 'update' => ['enabled' => true], 'display-name-claim' => 'name', 'email-claim' => 'email']], ['email' => 'alice@example.net', 'name' => 'John']],
			# 6. configured to update display name only
			[false, false, false, true, true, true, '', '', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true, 'attributes' => ['display-name']]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 7. configured to update e-mail only
			[false, false, true, false, true, true, '', '', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true, 'attributes' => ['email']]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 8. not updating if attributes are missing in userInfo
			[false, false, false, false, true, true, 'alice@example.net', 'John', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true, 'attributes' => ['email']]]], []],
			# 9. not updating email if not allowed by user's backend
			[false, false, false, true, false, true, '', '', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 10. not updating display name if not allowed by user's backend
			[false, false, true, false, true, false, '', '', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true]]], ['email' => 'alice@example.net', 'name' => 'John']],
			# 11. not updating if nothing changed
			[false, false, false, false, true, true, 'alice@example.net', 'John', [ 'auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'update' => ['enabled' => true]]], ['email' => 'alice@example.net', 'name' => 'John']]
		];
	}
}
