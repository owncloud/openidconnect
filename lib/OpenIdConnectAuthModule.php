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

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use OC\User\LoginException;
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

	/**
	 * OpenIdConnectAuthModule constructor.
	 *
	 * @param IUserManager $manager
	 * @param ILogger $logger
	 * @param ICacheFactory $cacheFactory
	 * @param Client $client
	 */
	public function __construct(IUserManager $manager,
								   ILogger $logger,
								   ICacheFactory $cacheFactory,
								   Client $client) {
		$this->manager = $manager;
		$this->logger = $logger;
		$this->cacheFactory = $cacheFactory;
		$this->client = $client;
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
		try {
			$openId = $this->getOpenIdConnectClient();
			if (!$openId) {
				return null;
			}
			// 1. verify JWT signature
			$this->verifyJWT($bearerToken);

			// 2. verify expiry
			$payload = $openId->getAccessTokenPayload();
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			$expiring = $payload->exp - \time();
			if ($expiring < 0) {
				throw new LoginException('OpenID Connect token expired');
			}

			// 3. get user id
			$userIdentifier = $this->getUserResource($bearerToken);
			$user = $this->manager->get($userIdentifier);
			if ($user) {
				$this->updateCache($bearerToken, $user);
				return $user;
			}
			// TODO: log something and maybe throw an exception
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
	 * @return OpenIDConnectClient|null
	 */
	public function getOpenIdConnectClient() {
		if ($this->client->getOpenIdConfig() === null) {
			return null;
		}
		return $this->client;
	}

	/**
	 * @param string $bearerToken
	 * @throws OpenIDConnectClientException
	 */
	private function verifyJWT($bearerToken) {
		$cache = $this->getCache();
		$userInfo = $cache->get($bearerToken);
		if ($userInfo) {
			return;
		}
		$openId = $this->getOpenIdConnectClient();
		if ($openId) {
			$openId->verifyJWTsignature($bearerToken);
		}
	}

	/**
	 * @return \OCP\ICache
	 */
	private function getCache(): \OCP\ICache {
		return $this->cacheFactory->create('oca.openid-connect');
	}

	private function getUserResource($bearerToken) {
		// TODO: hide caching in client class
		$cache = $this->getCache();
		$userInfo = $cache->get($bearerToken);
		if ($userInfo) {
			return $userInfo['userId'];
		}

		$openId = $this->getOpenIdConnectClient();
		if (!$openId) {
			return null;
		}
		$openId->setAccessToken($bearerToken);
		$payload = $openId->getAccessTokenPayload();

		// kopano special integration
		/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
		if ($payload->{'kc.identity'}->{'kc.i.un'}) {
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			return $payload->{'kc.identity'}->{'kc.i.un'};
		}

		$userInfo = $openId->requestUserInfo();
		if ($userInfo === null) {
			return null;
		}

		// TODO: add config value
		// TODO: search in user manager with the email address and return the one match ing user - see login flow controller
		// for now use 'email'
		return $userInfo['email'];
	}

	/**
	 * @param string $bearerToken
	 * @param IUser $user
	 */
	private function updateCache($bearerToken, IUser $user) {
		$cache = $this->getCache();
		$cache->set($bearerToken, [
			'uid' => $user->getUID()
		]);
	}
}
