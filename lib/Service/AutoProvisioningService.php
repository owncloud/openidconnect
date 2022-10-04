<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Ilja Neumann <ineumann@owncloud.com>
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
namespace OCA\OpenIdConnect\Service;

use OCA\OpenIdConnect\Client;
use OC\User\LoginException;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\NotPermittedActionException;

class AutoProvisioningService {

	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IGroupManager
	 */
	private $groupManager;
	/**
	 * @var IAvatarManager
	 */
	private $avatarManager;
	/**
	 * @var ILogger
	 */
	private $logger;
	/**
	 * @var IClientService
	 */
	private $clientService;
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var IL10N
	 */
	private $l10n;

	public function __construct(
		IUserManager $userManager,
		IGroupManager $groupManager,
		IAvatarManager $avatarManager,
		IClientService $clientService,
		ILogger $logger,
		Client $client,
		IL10N $l10n
	) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->avatarManager = $avatarManager;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->client = $client;
		$this->l10n = $l10n;
	}

	/**
	 * @throws LoginException
	 * @throws NotPermittedActionException
	 * @throws \Exception
	 */
	public function createUser($userInfo): ?IUser {
		if (!$this->autoProvisioningEnabled()) {
			return null;
		}
		$attribute = $this->client->getIdentityClaim();
		$emailOrUserId = $userInfo->$attribute ?? null;
		if (!$emailOrUserId) {
			throw new LoginException($this->l10n->t("OpenIdConnect: Configured attribute %s is not known.", [$attribute]));
		}
		$userId = $this->client->mode() === 'email' ? $this->generateUserId() : $emailOrUserId;

		$config = $this->client->getAutoProvisionConfig();
		$provisioningClaim = $config['provisioning-claim'] ?? null;
		if ($provisioningClaim) {
			$this->logger->debug('ProvisioningClaim is defined for auto-provision', ['claim' => $provisioningClaim]);
			$provisioningAttribute = $config['provisioning-attribute'] ?? null;

			if (!\property_exists($userInfo, $provisioningClaim) || !\is_array($userInfo->$provisioningClaim)) {
				throw new LoginException($this->l10n->t('OpenIdConnect: Required provisioning attribute is not found.'));
			}

			if (!\in_array($provisioningAttribute, $userInfo->$provisioningClaim, true)) {
				throw new LoginException($this->l10n->t('OpenIdConnect: Required provisioning attribute is not found.'));
			}
		}

		$user = $this->userManager->createUser($userId, $this->generatePassword());
		if (!$user) {
			throw new LoginException($this->l10n->t("OpenIdConnect: Unable to create user %s", $userId));
		}
		$user->setEnabled(true);

		$groups = $config['groups'] ?? [];
		foreach ($groups as $group) {
			$g = $this->groupManager->get($group);
			if ($g) {
				$g->addUser($user);
			}
		}

		$this->updateAccountInfo($user, $userInfo, true);

		$pictureUrl = $this->client->getUserPicture($userInfo);
		if ($pictureUrl) {
			try {
				$resource = $this->downloadPicture($pictureUrl);
				$av = $this->avatarManager->getAvatar($user->getUID());
				$av->set($resource);
			} catch (\Exception $ex) {
				$this->logger->logException($ex, [
					'message' => "Error setting profile picture $pictureUrl"
				]);
			}
		}

		return $user;
	}

	/**
	 * @throws NotPermittedActionException
	 */
	public function updateAccountInfo(IUser $user, $userInfo, bool $force = false): void {
		if (!($this->autoUpdateEnabled() || $force)) {
			return;
		}
		if ($force || $user->canChangeMailAddress()) {
			$currentEmail = $this->client->getUserEmail($userInfo);
			if ($currentEmail && $currentEmail !== $user->getEMailAddress()) {
				$this->logger->debug('AutoProvisioningService: setting e-mail to ' . $currentEmail);
				$user->setEMailAddress($currentEmail);
			}
		}

		if ($force || $user->canChangeDisplayName()) {
			$currentDN = $this->client->getUserDisplayName($userInfo);
			if ($currentDN && $currentDN !== $user->getDisplayName()) {
				$this->logger->debug('AutoProvisioningService: setting display name to ' . $currentDN);
				$user->setDisplayName($currentDN);
			}
		}
	}
	
	public function autoProvisioningEnabled(): bool {
		return (bool)($this->client->getAutoProvisionConfig()['enabled'] ?? false);
	}

	public function autoUpdateEnabled(): bool {
		return (bool)($this->client->getAutoUpdateConfig()['enabled'] ?? false);
	}

	protected function downloadPicture(string $pictureUrl): string {
		return $this->clientService->newClient()->get($pictureUrl)->getBody();
	}

	/**
	 * @throws \Exception
	 */
	private function generateUserId(): string {
		return 'oidc-user-'.\bin2hex(\random_bytes(16));
	}

	/**
	 * @throws \Exception
	 */
	private function generatePassword(): string {
		return \bin2hex(\random_bytes(32));
	}
}
