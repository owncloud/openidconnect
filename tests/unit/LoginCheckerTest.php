<?php
/**
 * @author Juan Pablo Villafañez Ramos <jvillafanez@owncloud.com>
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

use OCA\OpenIdConnect\LoginChecker;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IConfig;
use OC\Helper\UserTypeHelper;
use OC\User\LoginException;
use Test\TestCase;

class LoginCheckerTest extends TestCase {
	/** @var UserTypeHelper */
	private $userTypeHelper;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IConfig */
	private $config;
	/** @var IL10N */
	private $l10n;
	/** @var LoginChecker */
	private $loginChecker;

	protected function setUp(): void {
		parent::setUp();
		$this->userTypeHelper = $this->createMock(UserTypeHelper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->loginChecker = new LoginChecker($this->userTypeHelper, $this->groupManager, $this->config, $this->l10n);
	}

	public function ensurePasswordLoginJustForGuestDataProvider() {
		return [
			[true, 'password'],
			[true, 'token'],
			[true, 'apache'],
			[true, null],
			[false, 'token'],
			[false, 'apache'],
			[false, 'null'],
		];
	}

	/**
	 * @dataProvider ensurePasswordLoginJustForGuestDataProvider
	 */
	public function testEnsurePasswordLoginJustForGuest($isGuest, $loginType) {
		$this->config->method('getSystemValue')
			->with('openid-connect.basic_auth_guest_only.exclude_groups', [])
			->willReturn([]);

		$this->userTypeHelper->method('isGuestUser')
			->willReturn($isGuest);
		$this->assertNull($this->loginChecker->ensurePasswordLoginJustForGuest($loginType, 'myUser'));
	}

	public function testEnsurePasswordLoginJustForGuestNotGuest() {
		$this->expectException(LoginException::class);

		$this->config->method('getSystemValue')
			->with('openid-connect.basic_auth_guest_only.exclude_groups', [])
			->willReturn([]);
		$this->userTypeHelper->method('isGuestUser')->willReturn(false);
		$this->loginChecker->ensurePasswordLoginJustForGuest('password', 'muUser');
	}

	public function testEnsurePasswordLoginJustForGuestNotGuestButInGroup() {
		$this->config->method('getSystemValue')
			->with('openid-connect.basic_auth_guest_only.exclude_groups', [])
			->willReturn(['myAdminGroup']);
		$this->groupManager->method('isInGroup')
			->with('muUser', 'myAdminGroup')
			->willReturn(true);
		$this->userTypeHelper->method('isGuestUser')->willReturn(false);
		$this->assertNull($this->loginChecker->ensurePasswordLoginJustForGuest('password', 'muUser'));
	}
}
