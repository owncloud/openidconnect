<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Ilja Neumann <ineumann@owncloud.com>
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
namespace OCA\OpenIdConnect\Service;

use OCA\OpenIdConnect\Client;
use OC\User\LoginException;
use OCP\ILogger;
use OCP\IUser;

class AccountUpdateService {

	/**
	 * @var ILogger
	 */
	private $logger;
	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @param ILogger $logger
	 * @param Client $client
	 */
	public function __construct(
		ILogger $logger,
		Client $client
	) {
		$this->logger = $logger;
		$this->client = $client;
	}

	/**
	 * @param IUser $user IUser user to be updated
	 * @param $userInfo curent user info
	 * @param bool $force force all account attributes to be updated
	 * @return void
	 */
	public function updateAccountInfo(IUser $user, $userInfo, bool $force = false) {
		if (!($this->enabled() || $force)) {
			throw new LoginException('Account auto-update is disabled.');
		}
		$autoupdated = $this->autoupdatedAttributes();

		if ($force || (\in_array('email', $autoupdated) &&
				(!\method_exists('canChangeMailAddress') || $user->canChangeMailAddress()))) {
			$currentEmail = $this->client->getUserEmail($userInfo);
			if ($currentEmail && $currentEmail !== $user->getEMailAddress()) {
				$this->logger->debug('AccountUpdateService: updating e-mail to ' . $currentEmail);
				$user->setEMailAddress($currentEmail);
			}
		}

		if ($force || (\in_array('display-name', $autoupdated) && $user->canChangeDisplayName())) {
			$currentDN = $this->client->getUserDisplayName($userInfo);
			if ($currentDN && $currentDN !== $user->getDisplayName()) {
				$this->logger->debug('AccountUpdateService: updating display name to ' . $currentDN);
				$user->setDisplayName($currentDN);
			}
		}
	}

	private function autoupdatedAttributes(): array {
		return $this->client->getOpenIdConfiguration()['auto-update']['attributes'] ?? ['email', 'display-name'];
	}

	public function enabled(): bool {
		return $this->client->getOpenIdConfiguration()['auto-update']['enabled'] ?? false;
	}
}
