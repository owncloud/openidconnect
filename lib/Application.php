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

namespace OCA\OpenIdConnect;

require_once __DIR__ . '/../vendor/autoload.php';

use OCP\AppFramework\App;

class Application extends App {
	public function __construct(array $urlParams = []) {
		parent::__construct('openidconnect', $urlParams);
	}

	/**
	 * @throws \OC\HintException
	 */
	public function boot() {
		$server = $this->getContainer()->getServer();
		if (!\OC::$CLI && !$server->getMemCacheFactory()->isAvailable()) {
			throw new \OC\HintException('A real distributed mem cache setup is required');
		}

		/**
		 * @var Client $module
		 */
		$client = $server->query(Client::class);
		if ($client->getOpenIdConfig() === null) {
			return;
		}
		// register alternative login
		\OC_App::registerLogIn([
			'name' => \ucfirst('OpenId'),
			'href' => $server->getURLGenerator()->linkToRoute('openidconnect.loginFlow.login'),
		]);
		// TODO: if configured perform redirect right away if not logged in ....

		// verify open id token/session
		$sid = $server->getSession()->get('oca.openid-connect.session-id');
		if ($sid) {
			$sessionValid = $server->getMemCacheFactory()
				->create('oca.openid-connect.sessions')
				->get($sid);
			if (!$sessionValid) {
				$this->logout();
				return;
			}
		}

		// verify token as stored in session
		$accessToken = $server->getSession()->get('oca.openid-connect.access-token');
		if ($accessToken !== null) {
			// register logout handler
			$server->getEventDispatcher()->addListener('‌user.afterlogout', function () use ($client, $accessToken, $server) {
				// only call if access token is still valid
				$client->signOut($accessToken,
					$server->getURLGenerator()->getAbsoluteURL('/'));
			});

			// TODO: cache this ...
			$client->verifyJWTsignature($accessToken);
			$client->setAccessToken($accessToken);
			$payload = $client->getAccessTokenPayload();

			// refresh the tokens
			$expiring = $payload->exp - \time();
			if ($expiring < 5*60) {
				$refreshToken = $server->getSession()->get('oca.openid-connect.access-token');
				if ($refreshToken) {
					$response = $client->refreshToken($refreshToken);
					if ($response->error) {
						$this->logout();
						throw new \OC\HintException($response->error_description);
					}
					$server->getSession()->set('oca.openid-connect.access-token', $client->getAccessToken());
					$server->getSession()->set('oca.openid-connect.refresh-token', $client->getRefreshToken());
				} else {
					$this->logout();
				}
			}
		}
	}

	private function logout() {
		$server = $this->getContainer()->getServer();

		$server->getSession()->remove('oca.openid-connect.access-token');
		$server->getSession()->remove('oca.openid-connect.refresh-token');
		$server->getUserSession()->logout();
	}
}
