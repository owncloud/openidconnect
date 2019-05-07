<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\OpenIdConnect\Service;

use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCP\IUserManager;

class UserLookupService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var Client
	 */
	private $client;

	public function __construct(IUserManager $userManager,
								Client $client) {
		$this->userManager = $userManager;
		$this->client = $client;
	}

	/**
	 * @param mixed $userInfo
	 * @return \OCP\IUser
	 * @throws LoginException
	 */
	public function lookupUser($userInfo) {
		$openIdConfig = $this->client->getOpenIdConfig();
		$searchByEmail = true;
		if (isset($openIdConfig['mode']) && $openIdConfig['mode'] === 'userid') {
			$searchByEmail = false;
		}
		$attribute = 'email';
		if (isset($openIdConfig['search-attribute'])) {
			$attribute = $openIdConfig['search-attribute'];
		}

		if ($searchByEmail) {
			$user = $this->userManager->getByEmail($userInfo->$attribute);
			if (!$user) {
				throw new LoginException("User with {$userInfo->$attribute} is not known.");
			}
			if (\count($user) !== 1) {
				throw new LoginException("{$userInfo->$attribute} is not unique.");
			}
			return $user[0];
		}
		$user = $this->userManager->get($userInfo->$attribute);
		if (!$user) {
			throw new LoginException("User {$userInfo->$attribute} is not known.");
		}
		return $user;
	}
}
