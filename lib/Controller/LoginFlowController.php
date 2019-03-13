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

namespace OCA\OpenIdConnect\Controller;

use OC\HintException;
use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;

class LoginFlowController extends \OCP\AppFramework\Controller {

	/**
	 * @var ISession
	 */
	private $session;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var Session
	 */
	private $userSession;
	/**
	 * @var Client
	 */
	private $client;

	public function __construct(string $appName,
								IRequest $request,
								IUserManager $userManager,
								IUserSession $userSession,
								ISession $session,
								Client $client) {
		parent::__construct($appName, $request);

		if (!$userSession instanceof Session) {
			throw new \Exception('We rely on internal implementation!');
		}

		$this->session = $session;
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->client = $client;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 */
	public function config() {
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			return new JSONResponse([]);
		}
		$wellKonwData = $openid->getWellKnownConfig();
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 *
	 * @throws HintException
	 * @throws \Jumbojett\OpenIDConnectClientException
	 */
	public function redirect() {
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			throw new HintException('Configuration issue in openidconnect app');
		}
		$openid->authenticate();
		$userInfo = $openid->requestUserInfo();
		if (!$userInfo) {
			throw new LoginException('No user information available.');
		}

		// TODO: which attribute to take?
		$user = $this->userManager->getByEmail($userInfo->email);
		if (!$user) {
			throw new LoginException("User with {$userInfo->email} is not known.");
		}
		if (\count($user) !== 1) {
			throw new LoginException("{$userInfo->email} is not unique.");
		}

		// trigger login process
		if ($this->userSession->loginUser($user[0], '')) {
			$this->session->set('oca.openid-connect.access-token', $openid->getAccessToken());
			$this->session->set('oca.openid-connect.refresh-token', $openid->getRefreshToken());
			/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
			if (isset($openid->getIdTokenPayload()->sid)) {
				/* @phan-suppress-next-line PhanTypeExpectedObjectPropAccess */
				$sid = $openid->getIdTokenPayload()->sid;
				$this->session->set('oca.openid-connect.session-id', $sid);
				\OC::$server->getMemCacheFactory()
					->create('oca.openid-connect.sessions')
					->set($sid, true);
			}
			return new RedirectResponse($this->getDefaultUrl());
		}
		return new RedirectResponse('/');
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @UseSession
	 */
	public function logout($iss = null, $sid = null) {
		// TODO: verify issuer
		\OC::$server->getMemCacheFactory()
			->create('oca.openid-connect.sessions')
			->remove($sid);

		$resp = new Response();
		$resp->setHeaders([
			'Cache-Control' =>  'no-cache, no-store',
			'Pragma' => 'no-cache'
		]);
		return $resp;
	}

	/**
	 * @return string
	 */
	protected function getDefaultUrl() {
		return \OC_Util::getDefaultPageUrl();
	}

	/**
	 * @return Client|null
	 */
	private function getOpenIdConnectClient() {
		if ($this->client->getOpenIdConfig() === null) {
			return null;
		}
		return $this->client;
	}
}
