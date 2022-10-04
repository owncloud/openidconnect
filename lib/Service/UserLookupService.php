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
namespace OCA\OpenIdConnect\Service;

use OC\HintException;
use OC\User\LoginException;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\NotPermittedActionException;
use function property_exists;

class UserLookupService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var AutoProvisioningService
	 */
	private $autoProvisioningService;
	/**
	 * @var IL10N
	 */
	private $l10N;
	/**
	 * @var ILogger
	 */
	private $logger;

	public function __construct(
		IUserManager $userManager,
		Client $client,
		AutoProvisioningService $autoProvisioningService,
		IL10N $l0N,
		ILogger $logger
	) {
		$this->userManager = $userManager;
		$this->client = $client;
		$this->autoProvisioningService = $autoProvisioningService;
		$this->l10N = $l0N;
		$this->logger = new Logger($logger);
	}

	/**
	 * @param mixed $userInfo
	 * @return IUser
	 * @throws LoginException
	 * @throws HintException
	 * @throws NotPermittedActionException
	 */
	public function lookupUser($userInfo): IUser {
		$openIdConfig = $this->client->getOpenIdConfig();
		if ($openIdConfig === null) {
			throw new HintException($this->l10N->t('OpenIdConnect: Missing configuration'));
		}
		$searchByEmail = true;
		if ($this->client->mode() === 'userid') {
			$searchByEmail = false;
		}
		$attribute = $this->client->getIdentityClaim();
		if (!property_exists($userInfo, $attribute)) {
			throw new LoginException($this->l10N->t("OpenIdConnect: Configured attribute %s is not known.", [$attribute]));
		}

		if ($searchByEmail) {
			$user = $this->userManager->getByEmail($userInfo->$attribute);
			if (!$user) {
				$user = $this->autoProvisioningService->createUser($userInfo);
				if ($user) {
					return $user;
				}

				throw new LoginException($this->l10N->t("OpenIdConnect: User with %s is not known.", [$userInfo->$attribute]));
			}
			if (\count($user) !== 1) {
				throw new LoginException($this->l10N->t("OpenIdConnect: %s is not unique.", [$userInfo->$attribute]));
			}
			$this->validUser($user[0]);
			return $user[0];
		}
		$user = $this->userManager->get($userInfo->$attribute);
		if (!$user) {
			$user = $this->autoProvisioningService->createUser($userInfo);
			if (!$user) {
				throw new LoginException($this->l10N->t("OpenIdConnect: User %s is not known.", [$userInfo->$attribute]));
			}
		}
		$this->validUser($user);
		return $user;
	}

	/**
	 * @throws LoginException
	 */
	private function validUser(IUser $user): void {
		$openIdConfig = $this->client->getOpenIdConfig();
		$allowedUserBackEnds = $openIdConfig['allowed-user-backends'] ?? null;
		if ($allowedUserBackEnds === null) {
			return;
		}
		if (\in_array($user->getBackendClassName(), $allowedUserBackEnds, true)) {
			return;
		}

		$this->logger->error("User <{$user->getUID()}> is from wrong user backend <{$user->getBackendClassName()}>");
		throw new LoginException($this->l10N->t("OpenIdConnect: login denied."));
	}
}
