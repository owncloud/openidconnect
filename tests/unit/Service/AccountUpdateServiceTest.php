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
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Service\AccountUpdateService;
use OCP\ILogger;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class AccountUpdateServiceTest extends TestCase {

	/**
	 * @var Client|MockObject
	 */
	private $client;
	/**
	 * @var AccountUpdateService
	 */
	private $accountUpdateService;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(ILogger::class);
		$this->client = $this->createMock(Client::class);

		$this->accountUpdateService = new AccountUpdateService(
			$logger,
			$this->client
		);
	}

	/**
	 * @dataProvider providesConfig
	 * @param bool $expected
	 * @param array|null $config
	 */
	public function testEnabled(bool $expected, array $config = null): void {
		$this->client->method('getOpenIdConfig')->willReturn($config);
		self::assertEquals($expected, $this->accountUpdateService->enabled());
	}

	public function providesConfig(): array {
		return [
			[false, null],
			[false, []],
			[false, ['auto-update' => []]],
			[false, ['auto-update' => ['enabled' => false]]],
			[true, ['auto-update' => ['enabled' => true]]],
		];
	}

	/**
	 * @dataProvider providesDataUpdates
	 * @param bool $expectException
	 * @param bool $force
	 * @param bool $expectEmailToBeSet
	 * @param bool $expectDisplayName
	 * @param bool $canChangeEmail
	 * @param bool $canChangeDN
	 * @param string $currentEmail
	 * @param string $currentDN
	 * @param array $config
	 * @param object $userInfo
	 * @return void
	 */
	public function testUpdateAccountInfo(
		bool $expectException,
		bool $force,
		bool $expectEmailToBeSet,
		bool $expectDisplayName,
		bool $canChangeEmail,
		bool $canChangeDN,
		string $currentEmail,
		string $currentDN,
		array $config,
		object $userInfo
	): void {
		if ($expectException) {
			$this->expectException(LoginException::class);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('canChangeMailAddress')->willReturn($canChangeEmail);
			$user->method('canChangeDisplayName')->willReturn($canChangeDN);
			$user->method('getEMailAddress')->willReturn($currentEmail);
			$user->method('getDisplayName')->willReturn($currentDN);

			$user->expects($expectEmailToBeSet ? self::once() : self::never())->method('setEMailAddress')->with($userInfo['email']);
			$user->expects($expectDisplayName ? self::once() : self::never())->method('setDisplayName')->with($userInfo['name']);
			$this->userManager->expects(self::once())->method('createUser')->willReturn($user);
		}
		$this->client->method('getOpenIdConfig')->willReturn($config);
		$this->accountUpdateService->updateAccountInfo($user, $userInfo, $force);
	}

	public function providesDataUpdates(): array {
		return [
			# 1. update disabled, not forced
			[true, false, false, false, false, false, '', '', [], (object)['email' => 'alice@example.net']],
			# 2. update disabled by config, but forced on a newly provisioned account
			[false, true, true, true, false, false, '', '', ['auto-update' => ['enabled' => false ], 'auto-provision' => ['email-claim' => 'email', 'display-name-claim' => 'name']], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 3. update enabled, but missing claims in configuration
			[false, false, false, false, true, true, '', '', ['auto-update' => ['enabled' => true]], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 4. update enabled, used together with auto-provisioning mode
			[false, false, true, true, true, true, '', '', ['auto-provision' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email'], 'auto-update' => ['enabled' => true]], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 5. update enabled, used without auto-provisioning mode
			[false, false, true, true, true, true, '', '', ['auto-provision' => ['enabled' => false], 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email']], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 6. configured to update display name only
			[false, false, false, true, true, true, '', '', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'attributes' => ['display-name']]], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 7. configured to update e-mail only
			[false, false, true, false, true, true, '', '', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'attributes' => ['email']]], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 8. not updating if attributes are missing in userInfo
			[false, false, false, false, true, true, 'alice@example.net', 'John', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email', 'attributes' => ['email']]], (object)[]],
			# 9. not updating email if not allowed by user's backend
			[false, false, false, true, false, true, '', '', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email']], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 10. not updating display name if not allowed by user's backend
			[false, false, true, false, true, false, '', '', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email']], (object)['email' => 'alice@example.net', 'name' => 'John']],
			# 10. not updating if no change
			[false, false, false, false, true, true, 'alice@example.net', 'John', [ 'auto-update' => ['enabled' => true, 'display-name-claim' => 'name', 'email-claim' => 'email']], (object)['email' => 'alice@example.net', 'name' => 'John']]
		];
	}
}
