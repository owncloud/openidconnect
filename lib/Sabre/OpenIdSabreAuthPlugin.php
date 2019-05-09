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
namespace OCA\OpenIdConnect\Sabre;

use OC\User\Session;
use OCA\DAV\Connector\Sabre\Auth;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Sabre\DAV\Auth\Backend\AbstractBearer;

class OpenIdSabreAuthPlugin extends AbstractBearer {
	const DAV_AUTHENTICATED = Auth::DAV_AUTHENTICATED;

	/**
	 * This is the prefix that will be used to generate principal urls.
	 *
	 * @var string
	 */
	protected $principalPrefix;

	/** @var ISession */
	private $session;

	/** @var Session */
	private $userSession;

	/** @var IRequest */
	private $request;

	/** @var OpenIdConnectAuthModule */
	private $authModule;

	/**
	 * OAuth2 constructor.
	 *
	 * @param ISession $session The session.
	 * @param IUserSession $userSession The user session.
	 * @param IRequest $request The request.
	 * @param OpenIdConnectAuthModule $authModule
	 * @param string $principalPrefix The principal prefix.
	 * @throws \Exception
	 */
	public function __construct(ISession $session,
								IUserSession $userSession,
								IRequest $request,
								OpenIdConnectAuthModule $authModule,
								$principalPrefix = 'principals/users/') {
		if (!$userSession instanceof Session) {
			throw new \Exception('We rely on internal implementation!');
		}

		$this->session = $session;
		$this->userSession = $userSession;
		$this->request = $request;
		$this->authModule = $authModule;
		$this->principalPrefix = $principalPrefix;

		// setup realm
		$defaults = new \OC_Defaults();
		$this->realm = $defaults->getName();
	}

	/**
	 * Checks whether the user has initially authenticated via DAV.
	 *
	 * This is required for WebDAV clients that resent the cookies even when the
	 * account was changed.
	 *
	 * @see https://github.com/owncloud/core/issues/13245
	 *
	 * @param string $username The username.
	 * @return bool True if the user initially authenticated via DAV, false otherwise.
	 */
	private function isDavAuthenticated($username) {
		return $this->session->get(self::DAV_AUTHENTICATED) !== null &&
			$this->session->get(self::DAV_AUTHENTICATED) === $username;
	}

	/**
	 * Validates a Bearer token.
	 *
	 * This method should return the full principal url, or false if the
	 * token was incorrect.
	 *
	 * @param string $bearerToken The Bearer token.
	 * @return string|false The full principal url, if the token is valid, false otherwise.
	 * @throws \OC\User\LoginException
	 */
	protected function validateBearerToken($bearerToken) {
		if ($this->userSession->isLoggedIn() &&
			$this->isDavAuthenticated($this->userSession->getUser()->getUID())) {

			// verify the bearer token
			$tokenUser = $this->authModule->authToken($bearerToken);
			if ($tokenUser === null) {
				return false;
			}

			// setup the user
			$userId = $this->userSession->getUser()->getUID();
			\OC_Util::setupFS($userId);
			$this->session->close();
			return $this->principalPrefix . $userId;
		}

		\OC_Util::setupFS(); //login hooks may need early access to the filesystem

		try {
			if ($this->userSession->tryAuthModuleLogin($this->request)) {
				$userId = $this->userSession->getUser()->getUID();
				\OC_Util::setupFS($userId);
				$this->session->set(self::DAV_AUTHENTICATED, $userId);
				$this->session->close();
				return $this->principalPrefix . $userId;
			}

			$this->session->close();
			return false;
		} catch (\Exception $ex) {
			$this->session->close();
			return false;
		}
	}
}
