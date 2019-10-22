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
namespace OCA\OpenIdConnect;

require_once __DIR__ . '/../vendor/autoload.php';

use Jumbojett\OpenIDConnectClientException;
use OCA\OpenIdConnect\Sabre\OpenIdSabreAuthPlugin;
use OCP\AppFramework\App;
use OCP\SabrePluginEvent;
use Sabre\DAV\Auth\Plugin;

class Application extends App {
	public function __construct(array $urlParams = []) {
		parent::__construct('openidconnect', $urlParams);
	}

	/**
	 * @throws OpenIDConnectClientException
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
					\header('Location: ' . $loginUrl);
					exit;
				}
			}
		}
		// Add event listener
		$dispatcher = $server->getEventDispatcher();
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::authInit', function ($event) use ($server) {
			if ($event instanceof SabrePluginEvent) {
				$authPlugin = $event->getServer()->getPlugin('auth');
				if ($authPlugin instanceof Plugin) {
					$authPlugin->addBackend(
						new OpenIdSabreAuthPlugin($server->getSession(),
							$server->getUserSession(),
							$server->getRequest(),
							$server->query(OpenIdConnectAuthModule::class),
							'principals/')
					);
				}
			}
		});

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
		$idToken = $server->getSession()->get('oca.openid-connect.id-token');

		// register logout handler
		$server->getEventDispatcher()->addListener('user.beforelogout', static function () use ($client, $accessToken, $idToken) {
			// only call if access token is still valid
			try {
				if (\OC::$server->getSession()->get('oca.openid-connect.within-logout') === true) {
					\OC::$server->getLogger()->debug('OIDC Logout: revoking token' . $accessToken);
					$revokeData = $client->revokeToken($accessToken);
					\OC::$server->getLogger()->debug('Revocation info: ' . \json_encode($revokeData));
					\OC::$server->getSession()->remove('oca.openid-connect.access-token');
					\OC::$server->getSession()->remove('oca.openid-connect.refresh-token');
				} else {
					\OC::$server->getLogger()->debug('OIDC Logout: ending session ' . $accessToken . ' id: ' . $idToken);
					$client->signOut($idToken, null);
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
			\OC::$server->getLogger()->debug('Introspection info: ' . \json_encode($introData) . ' for access token:' . $accessToken);
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
			\OC::$server->getLogger()->debug('Access token payload: ' . \json_encode($payload) . ' for access token:' . $accessToken);

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
				$server->getSession()->set('oca.openid-connect.id-token', $client->getIdToken());
				$server->getSession()->set('oca.openid-connect.access-token', $client->getAccessToken());
				$server->getSession()->set('oca.openid-connect.refresh-token', $client->getRefreshToken());
			} else {
				\OC::$server->getLogger()->debug('No refresh token available -> nothing to do. We will be kicked out as soon as the access token expires.');
			}
		}
	}
}
