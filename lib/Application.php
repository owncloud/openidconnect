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
use OC;
use OC\HintException;
use OCA\OpenIdConnect\Sabre\OpenIdSabreAuthPlugin;
use OCP\AppFramework\App;
use OCP\ICache;
use OCP\IServerContainer;
use OCP\SabrePluginEvent;
use Sabre\DAV\Auth\Plugin;

class Application extends App {
	/** @var Logger */
	private $logger;

	public function __construct(array $urlParams = []) {
		parent::__construct('openidconnect', $urlParams);
	}

	/**
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 */
	public function boot(): void {
		$server = $this->getContainer()->getServer();
		$this->logger = new Logger($server->getLogger());
		if (!OC::$CLI && !$server->getMemCacheFactory()->isAvailable()) {
			throw new HintException('A real distributed mem cache setup is required');
		}

		$client = $this->getClient();
		$openIdConfig = $client->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		$userSession = $server->getUserSession();
		$urlGenerator = $server->getURLGenerator();
		$request = $server->getRequest();
		$loginPage = new LoginPageBehaviour($this->logger, $userSession, $urlGenerator, $request);
		$loginPage->handleLoginPageBehaviour($openIdConfig);

		// Add event listener
		$this->registerEventHandler($server);

		$this->verifySession();
	}

	private function logout(): void {
		$this->logger->error('Calling Application:logout');
		$server = $this->getContainer()->getServer();

		$server->getSession()->remove('oca.openid-connect.access-token');
		$server->getSession()->remove('oca.openid-connect.refresh-token');
		$server->getUserSession()->logout();
	}

	/**
	 * @throws HintException
	 * @throws OpenIDConnectClientException
	 */
	private function verifySession(): void {
		$server = $this->getContainer()->getServer();

		// verify open id token/session
		$sid = $server->getSession()->get('oca.openid-connect.session-id');
		if ($sid) {
			$sessionValid = $server->getMemCacheFactory()
				->create('oca.openid-connect.sessions')
				->get($sid);
			if (!$sessionValid) {
				$this->logger->debug("Session $sid is no longer valid -> logout");
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
		$logger = $this->logger;
		$server->getEventDispatcher()->addListener('user.afterlogout', static function () use ($client, $accessToken, $idToken, $logger) {
			// only call if access token is still valid
			try {
				$logger->debug('OIDC Logout: revoking token' . $accessToken);
				$revokeData = $client->revokeToken($accessToken);
				$logger->debug('Revocation info: ' . \json_encode($revokeData));
			} catch (OpenIDConnectClientException $ex) {
				$logger->logException($ex);
			}
			try {
				OC::$server->getSession()->remove('oca.openid-connect.access-token');
				OC::$server->getSession()->remove('oca.openid-connect.refresh-token');
				OC::$server->getSession()->remove('oca.openid-connect.id-token');
				$logger->debug('OIDC Logout: ending session ' . $accessToken . ' id: ' . $idToken);
				$client->signOut($idToken, null);
			} catch (OpenIDConnectClientException $ex) {
				$logger->logException($ex);
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
			$introspectionClientId = $client->getOpenIdConfig()['token-introspection-endpoint-client-id'] ?? null;
			$introspectionClientSecret = $client->getOpenIdConfig()['token-introspection-endpoint-client-secret'] ?? null;

			$introData = $client->introspectToken($accessToken, '', $introspectionClientId, $introspectionClientSecret);
			$this->logger->debug('Introspection info: ' . \json_encode($introData) . ' for access token:' . $accessToken);
			if (\property_exists($introData, 'error')) {
				$this->logger->error('Token introspection failed: ' . \json_encode($introData));
				$this->logout();
				throw new HintException("Verifying token failed: {$introData->error}");
			}
			if (!$introData->active) {
				$this->logger->error('Token (as per introspection) is inactive: ' . \json_encode($introData));
				$this->logout();
				return;
			}

			$this->getCache()->set($accessToken, $introData->exp);
			$this->refreshToken($introData->exp);
		} else {
			$client->verifyJWTsignature($accessToken);
			$payload = $client->getAccessTokenPayload();
			$this->logger->debug('Access token payload: ' . \json_encode($payload) . ' for access token:' . $accessToken);

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

	private function getCache(): ICache {
		return $this->getContainer()->getServer()->getMemCacheFactory()->create('oca.openid-connect');
	}

	/**
	 * @param int $exp
	 * @throws OpenIDConnectClientException
	 * @throws HintException
	 */
	private function refreshToken($exp): void {
		$server = $this->getContainer()->getServer();
		$client = $this->getClient();

		$expiring = $exp - \time();
		if ($expiring < 5 * 60) {
			$refreshToken = $server->getSession()->get('oca.openid-connect.refresh-token');
			if ($refreshToken) {
				$response = $client->refreshToken($refreshToken);
				if ($response->error) {
					$this->logger->error("Refresh token failed: {$response->error_description}");
					$this->logout();
					throw new HintException($response->error_description);
				}
				$server->getSession()->set('oca.openid-connect.id-token', $client->getIdToken());
				$server->getSession()->set('oca.openid-connect.access-token', $client->getAccessToken());
				$server->getSession()->set('oca.openid-connect.refresh-token', $client->getRefreshToken());
			} else {
				$this->logger->debug('No refresh token available -> nothing to do. We will be kicked out as soon as the access token expires.');
			}
		}
	}

	/**
	 * @param IServerContainer $server
	 */
	protected function registerEventHandler(IServerContainer $server): void {
		$dispatcher = $server->getEventDispatcher();
		$dispatcher->addListener('OCA\DAV\Connector\Sabre::authInit', static function ($event) use ($server) {
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
	}
}
