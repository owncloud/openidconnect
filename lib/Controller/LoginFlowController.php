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
namespace OCA\OpenIdConnect\Controller;

use OC\HintException;
use OC\User\LoginException;
use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Util;

class LoginFlowController extends Controller {

	/**
	 * @var ISession
	 */
	private $session;
	/**
	 * @var UserLookupService
	 */
	private $userLookup;
	/**
	 * @var Session
	 */
	private $userSession;
	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var ILogger
	 */
	private $logger;

	public function __construct(string $appName,
								IRequest $request,
								UserLookupService $userLookup,
								IUserSession $userSession,
								ISession $session,
								ILogger $logger,
								Client $client) {
		parent::__construct($appName, $request);

		if (!$userSession instanceof Session) {
			throw new \Exception('We rely on internal implementation!');
		}

		$this->session = $session;
		$this->userLookup = $userLookup;
		$this->userSession = $userSession;
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @CORS
	 */
	public function config() {
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			return new JSONResponse([]);
		}
		$wellKnownData = $openid->getWellKnownConfig();
		return new JSONResponse($wellKnownData);
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
	public function login() {
		$openid = $this->getOpenIdConnectClient();
		if (!$openid) {
			throw new HintException('Configuration issue in openidconnect app');
		}
		$openid->authenticate();
		$this->logger->debug('Access token: ' . $openid->getAccessToken());
		$this->logger->debug('Refresh token: ' . $openid->getRefreshToken());
		$userInfo = $openid->requestUserInfo();
		$this->logger->debug('User info: ' . \json_encode($userInfo));
		if (!$userInfo) {
			throw new LoginException('No user information available.');
		}
		$user = $this->userLookup->lookupUser($userInfo);

		// trigger login process

		if ($this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID()) &&
			$this->userSession->loginUser($user, null)) {
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
			} else {
				\OC::$server->getLogger()->debug('Id token holds no sid: ' . \json_encode($openid->getIdTokenPayload()));
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
	 * @param string|null $iss
	 * @param string|null $sid
	 * @return Response
	 */
	public function logout($iss = null, $sid = null) {
		// fail fast if not configured
		$openIdConfig = $this->client->getOpenIdConfig();
		if ($openIdConfig === null) {
			$this->logger->warning('OpenID::logout: OpenID is not properly configured', ['app' => 'OpenId']);
			return new Response();
		}
		// there is an active session -> logout
		if ($this->userSession->isLoggedIn()) {
			$this->logger->debug('OpenID::logout: There is an active session -> performing logout', ['app' => 'OpenId']);
			// complete logout
			$this->session->set('oca.openid-connect.within-logout', true);
			$this->userSession->logout();
		}
		if ($iss === null || $sid === null) {
			if (!$this->userSession->isLoggedIn()) {
				$this->logger->warning("OpenID::logout: missing parameters: iss={$iss} and sid={$sid} and no active session", ['app' => 'OpenId']);
			}
			return new Response();
		}
		if (isset($openIdConfig['provider-url'])) {
			if (!Util::isSameDomain($openIdConfig['provider-url'], $iss)) {
				$this->logger->warning("OpenID::logout: iss {$iss} !== provider-url {$openIdConfig['provider-url']}", ['app' => 'OpenId']);
				return new Response();
			}
		}

		\OC::$server->getMemCacheFactory()
			->create('oca.openid-connect.sessions')
			->remove($sid);

		$this->logger->warning("OpenID::logout: session terminated: iss={$iss} and sid={$sid}", ['app' => 'OpenId']);

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
