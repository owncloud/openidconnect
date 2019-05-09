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

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OC\User\LoginException;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\Authentication\IAuthModule;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Class OpenIdConnectAuthModule - used in case ownCloud acts as relying party.
 * Mobile clients, desktop clients and phoenix will send an access token which
 * has been issued by the connected OpenID Connect Provider
 *
 * @package OCA\OpenIdConnect
 */
class OpenIdConnectAuthModule implements IAuthModule {

	/** @var IUserManager */
	private $manager;
	/** @var ILogger */
	private $logger;
	/** @var ICacheFactory */
	private $cacheFactory;
	/** @var Client */
	private $client;
	/** @var UserLookupService */
	private $lookupService;

	/**
	 * OpenIdConnectAuthModule constructor.
	 *
	 * @param IUserManager $manager
	 * @param ILogger $logger
	 * @param ICacheFactory $cacheFactory
	 * @param UserLookupService $lookupService
	 * @param Client $client
	 */
	public function __construct(IUserManager $manager,
								   ILogger $logger,
								   ICacheFactory $cacheFactory,
								   UserLookupService $lookupService,
								   Client $client) {
		$this->manager = $manager;
		$this->logger = $logger;
		$this->cacheFactory = $cacheFactory;
		$this->client = $client;
		$this->lookupService = $lookupService;
	}

	/**
	 * @inheritdoc
	 */
	public function auth(IRequest $request) {
		$authHeader = $request->getHeader('Authorization');
		if (\strpos($authHeader, 'Bearer ') === false) {
			return null;
		}
		$bearerToken = \substr($authHeader, 7);
		return $this->authToken($bearerToken);
	}

	public function authToken(string $bearerToken) {
		try {
			if ($this->client->getOpenIdConfig() === null) {
				return null;
			}
			// 1. verify JWT signature
			$expiry = $this->verifyJWT($bearerToken);

			// 2. verify expiry
			if ($expiry) {
				$expiring = $expiry - \time();
				if ($expiring < 0) {
					throw new LoginException('OpenID Connect token expired');
				}
			}

			// 3. get user
			$user = $this->getUserResource($bearerToken);
			if ($user) {
				$this->updateCache($bearerToken, $user, $expiry);
				return $user;
			}
			return null;
		} catch (OpenIDConnectClientException $ex) {
			$this->logger->logException($ex, ['app' => __CLASS__]);
			return null;
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getUserPassword(IRequest $request) {
		return '';
	}

	/**
	 * @param string $bearerToken
	 * @throws OpenIDConnectClientException
	 */
	private function verifyJWT($bearerToken) {
		$cache = $this->getCache();
		$userInfo = $cache->get($bearerToken);
		if ($userInfo) {
			return $userInfo['exp'];
		}
		if ($this->client->getOpenIdConfig()['use-token-introspection-endpoint']) {
			$introspectionClientId = isset($this->client->getOpenIdConfig()['token-introspection-endpoint-client-id']) ? $this->client->getOpenIdConfig()['token-introspection-endpoint-client-id'] : null;
			$introspectionClientSecret = isset($this->client->getOpenIdConfig()['token-introspection-endpoint-client-secret']) ? $this->client->getOpenIdConfig()['token-introspection-endpoint-client-secret'] : null;

			$introData = $this->client->introspectToken($bearerToken, '', $introspectionClientId, $introspectionClientSecret);
			$this->logger->debug('Introspection info: ' . \json_encode($introData));
			if (\property_exists($introData, 'error')) {
				$this->logger->error('Token introspection failed: ' . \json_encode($introData));
				throw new OpenIDConnectClientException("Verifying token failed: {$introData->error}");
			}
			if (!$introData->active) {
				$this->logger->error('Token (as per introspection) is inactive: ' . \json_encode($introData));
				throw new OpenIDConnectClientException('Token (as per introspection) is inactive');
			}
			return $introData->exp;
		}
		$this->client->verifyJWTsignature($bearerToken);
		$payload = $this->client->getAccessTokenPayload();
		$this->logger->debug('Access token payload: ' . \json_encode($payload));
		/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
		return $payload->exp;
	}

	/**
	 * @return \OCP\ICache
	 */
	private function getCache(): \OCP\ICache {
		return $this->cacheFactory->create('oca.openid-connect');
	}

	private function getUserResource($bearerToken) {
		$cache = $this->getCache();
		$userInfo = $cache->get($bearerToken);
		if ($userInfo) {
			return $this->manager->get($userInfo['uid']);
		}

		$this->client->setAccessToken($bearerToken);

		$userInfo = $this->client->requestUserInfo();
		$this->logger->debug('User info: ' . \json_encode($userInfo));
		if ($userInfo === null) {
			return null;
		}

		return $this->lookupService->lookupUser($userInfo);
	}

	/**
	 * @param string $bearerToken
	 * @param IUser $user
	 * @param int $expiry
	 */
	private function updateCache($bearerToken, IUser $user, $expiry) {
		$cache = $this->getCache();
		$cache->set($bearerToken, [
			'uid' => $user->getUID(),
			'exp' => $expiry
		]);
	}
}
