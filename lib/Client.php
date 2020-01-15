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
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;

class Client extends OpenIDConnectClient {

	/** @var ISession */
	private $session;
	/** @var IConfig */
	private $config;
	/** @var array */
	private $wellKnownConfig;
	/**
	 * @var IURLGenerator
	 */
	private $generator;

	/**
	 * Client constructor.
	 *
	 * @param IConfig $config
	 * @param IURLGenerator $generator
	 * @param ISession $session
	 */
	public function __construct(IConfig $config,
								IURLGenerator $generator,
								ISession $session
	) {
		$this->session = $session;
		$this->config = $config;
		$this->generator = $generator;

		$openIdConfig = $this->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		parent::__construct(
			$openIdConfig['provider-url'],
			$openIdConfig['client-id'],
			$openIdConfig['client-secret']
		);
		$scopes = $openIdConfig['scopes'] ?? ['openid', 'profile', 'email'];
		$this->addScope($scopes);

		if ($this->config->getSystemValue('debug', false)) {
			$this->setVerifyHost(false);
			$this->setVerifyPeer(false);
		}
		// set config parameters in case well known is not supported
		if (isset($openIdConfig['provider-params'])) {
			$this->providerConfigParam($openIdConfig['provider-params']);
		}
		// set additional auth parameters
		if (isset($openIdConfig['auth-params'])) {
			$this->addAuthParam($openIdConfig['auth-params']);
		}
	}

	/**
	 * @return mixed
	 */
	public function getOpenIdConfig() {
		return $this->config->getSystemValue('openid-connect', null);
	}

	/**
	 * @throws \Jumbojett\OpenIDConnectClientException
	 */
	public function getWellKnownConfig() {
		if (!$this->wellKnownConfig) {
			$well_known_config_url = \rtrim($this->getProviderURL(), '/') . '/.well-known/openid-configuration';
			$this->wellKnownConfig = \json_decode($this->fetchURL($well_known_config_url), false);
		}
		return $this->wellKnownConfig;
	}

	protected function startSession() {
	}

	protected function setSessionKey($key, $value) {
		$this->session->set($key, $value);
	}

	protected function getSessionKey($key) {
		return $this->session->get($key);
	}

	protected function unsetSessionKey($key) {
		$this->session->remove($key);
	}

	protected function commitSession() {
	}

	protected function fetchURL($url, $post_body = null, $headers = []) {
		// TODO: see how to use ownCloud HttpClient ....
		return parent::fetchURL($url, $post_body, $headers);
	}

	public function authenticate() : bool {
		$redirectUrl = $this->generator->linkToRouteAbsolute('openidconnect.loginFlow.login');

		$openIdConfig = $this->getOpenIdConfig();
		if (isset($openIdConfig['redirect-url'])) {
			$redirectUrl = $openIdConfig['redirect-url'];
		}

		$this->setRedirectURL($redirectUrl);
		return parent::authenticate();
	}
}
