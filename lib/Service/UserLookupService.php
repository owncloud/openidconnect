<?php
/**
 * ownCloud
 *
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @copyright (C) 2019 ownCloud GmbH
 * @license ownCloud Commercial License
 *
 * This code is covered by the ownCloud Commercial License.
 *
 * You should have received a copy of the ownCloud Commercial License
 * along with this program. If not, see
 * <https://owncloud.com/licenses/owncloud-commercial/>.
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
