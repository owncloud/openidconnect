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

use OC_App;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class LoginPageBehaviour {
	/** @var Logger */
	private $logger;
	/** @var IUserSession */
	private $userSession;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IRequest */
	private $request;

	public function __construct(Logger $logger,
								IUserSession $userSession,
								IURLGenerator $urlGenerator,
								IRequest $request) {
		$this->logger = $logger;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->request = $request;
	}

	public function handleLoginPageBehaviour(array $openIdConfig): void {
		// logged in? nothing to do
		if ($this->userSession->isLoggedIn()) {
			return;
		}

		// register alternative login
		$loginName = $openIdConfig['loginButtonName'] ?? 'OpenID Connect';
		$this->registerAlternativeLogin($loginName);

		// if configured perform redirect right away if not logged in ....
		$autoRedirectOnLoginPage = $openIdConfig['autoRedirectOnLoginPage'] ?? false;
		if (!$autoRedirectOnLoginPage) {
			return;
		}
		$components = \parse_url($this->request->getRequestUri());
		$uri = $components['path'];
		if (\substr($uri, -6) === '/login') {
			$req = $this->request->getRequestUri();
			$this->logger->debug("Redirecting to IdP - request url: $req");
			$loginUrl = $this->urlGenerator->linkToRoute('openidconnect.loginFlow.login');
			$this->redirect($loginUrl);
		}
	}

	/**
	 * @param string $loginUrl
	 * @codeCoverageIgnore
	 */
	public function redirect(string $loginUrl): void {
		\header('Location: ' . $loginUrl);
		exit;
	}

	/**
	 * @param string $loginName
	 * @codeCoverageIgnore
	 */
	public function registerAlternativeLogin(string $loginName): void {
		OC_App::registerLogIn([
			'name' => $loginName,
			'href' => $this->urlGenerator->linkToRoute('openidconnect.loginFlow.login'),
		]);
	}
}
