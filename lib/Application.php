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

use Jumbojett\OpenIDConnectClientException;
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

		$client = $this->getClient();
		$openIdConfig = $client->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		// register alternative login
		$loginName = isset($openIdConfig['loginButtonName']) ? $openIdConfig['loginButtonName'] : 'OpenId';
		\OC_App::registerLogIn([
			'name' => $loginName,
			'href' => $server->getURLGenerator()->linkToRoute('openidconnect.loginFlow.login'),
		]);
		// TODO: if configured perform redirect right away if not logged in ....
		if (isset($openIdConfig['autoRedirectOnLoginPage']) && $openIdConfig['autoRedirectOnLoginPage'] === true) {
			if (!$server->getUserSession()->isLoggedIn()) {
				$components = \parse_url($server->getRequest()->getRequestUri());
				$uri = $components['path'];
				if (\substr($uri, -6) === '/login') {
					$loginUrl =  $server->getURLGenerator()->linkToRoute('openidconnect.loginFlow.login');
					header('Location: ' . $loginUrl);
					exit;
				}
			}
		}

		$this->verifySession();
	}

	private function logout() {
		$server = $this->getContainer()->getServer();

		$server->getSession()->remove('oca.openid-connect.access-token');
		$server->getSession()->remove('oca.openid-connect.refresh-token');
		$server->getUserSession()->logout();
	}

	/**
	 * @throws \OC\HintException
	 * @throws \Jumbojett\OpenIDConnectClientException
	 */
	private function verifySession() {
		$server = $this->getContainer()->getServer();

		// verify open id token/session
		$sid = $server->getSession()->get('oca.openid-connect.session-id');
		if ($sid) {
			$sessionValid = $server->getMemCacheFactory()
				->create('oca.openid-connect.sessions')
				->get($sid);
			if (!$sessionValid) {
				\OC::$server->getLogger()->debug("Session $sid is no longer valid -> logout");
				$this->logout();
				return;
			}
		}

		$client = $this->getClient();
		// not configured -> nothing to do ...
		if ($client->getOpenIdConfig() === null) {
			return;
		}

		// verify token as stored in session
		$accessToken = $server->getSession()->get('oca.openid-connect.access-token');
		if ($accessToken === null) {
			return;
		}
		// register logout handler
		$server->getEventDispatcher()->addListener('user.beforelogout', function () use ($client, $accessToken) {
			// only call if access token is still valid
			try {
				if (\OC::$server->getSession()->get('oca.openid-connect.within-logout') === true) {
					\OC::$server->getLogger()->debug('OIDC Logout: revoking token' . $accessToken);
					$revokeData = $client->revokeToken($accessToken);
					\OC::$server->getLogger()->debug('Revocation info: ' . \json_encode($revokeData));
					\OC::$server->getSession()->remove('oca.openid-connect.access-token');
					\OC::$server->getSession()->remove('oca.openid-connect.refresh-token');
				} else {
					\OC::$server->getLogger()->debug('OIDC Logout: ending session ' . $accessToken);
					$client->signOut($accessToken, null);
				}
			} catch (OpenIDConnectClientException $ex) {
				\OC::$server->getLogger()->logException($ex);
			}
		});

		// cache access token information
		$exp = $this->getCache()->get($accessToken);
		if ($exp !== null) {
			$this->refreshToken($exp);
			return;
		}

		$client->setAccessToken($accessToken);
		if ($client->getOpenIdConfig()['use-token-introspection-endpoint']) {
			$introspectionClientId = isset($client->getOpenIdConfig()['token-introspection-endpoint-client-id']) ? $client->getOpenIdConfig()['token-introspection-endpoint-client-id'] : null;
			$introspectionClientSecret = isset($client->getOpenIdConfig()['token-introspection-endpoint-client-secret']) ? $client->getOpenIdConfig()['token-introspection-endpoint-client-secret'] : null;

			$introData = $client->introspectToken($accessToken, '', $introspectionClientId, $introspectionClientSecret);
			\OC::$server->getLogger()->debug('Introspection info: ' . \json_encode($introData));
			if (\property_exists($introData, 'error')) {
				\OC::$server->getLogger()->error('Token introspection failed: ' . \json_encode($introData));
				$this->logout();
				throw new \OC\HintException("Verifying token failed: {$introData->error}");
			}
			if (!$introData->active) {
				\OC::$server->getLogger()->error('Token (as per introspection) is inactive: ' . \json_encode($introData));
				$this->logout();
				return;
			}

			$this->getCache()->set($accessToken, $introData->exp);
			$this->refreshToken($introData->exp);
		} else {
			$client->verifyJWTsignature($accessToken);
			$payload = $client->getAccessTokenPayload();
			\OC::$server->getLogger()->debug('Access token payload: ' . \json_encode($payload));

			$this->getCache()->set($accessToken, $payload->exp);
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			$this->refreshToken($payload->exp);
		}
	}

	/**
	 * @return Client
	 */
	private function getClient(): Client {
		$server = $this->getContainer()->getServer();
		return $server->query(Client::class);
	}

	private function getCache(): \OCP\ICache {
		return $this->getContainer()->getServer()->getMemCacheFactory()->create('oca.openid-connect');
	}

	/**
	 * @param int $exp
	 * @throws \Jumbojett\OpenIDConnectClientException
	 * @throws \OC\HintException
	 */
	private function refreshToken($exp) {
		$server = $this->getContainer()->getServer();
		$client = $this->getClient();

		$expiring = $exp - \time();
		if ($expiring < 5 * 60) {
			$refreshToken = $server->getSession()->get('oca.openid-connect.refresh-token');
			if ($refreshToken) {
				$response = $client->refreshToken($refreshToken);
				if ($response->error) {
					\OC::$server->getLogger()->error("Refresh token failed: {$response->error_description}");
					$this->logout();
					throw new \OC\HintException($response->error_description);
				}
				$server->getSession()->set('oca.openid-connect.access-token', $client->getAccessToken());
				$server->getSession()->set('oca.openid-connect.refresh-token', $client->getRefreshToken());
			} else {
				\OC::$server->getLogger()->debug('No refresh token available -> logout');
				$this->logout();
			}
		}
	}
}
