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
use OCP\IConfig;
use OCP\ILogger;
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
	 * @var ILogger
	 */
	private $logger;

	/**
	 * Client constructor.
	 *
	 * @param IConfig $config
	 * @param IURLGenerator $generator
	 * @param ISession $session
	 * @param ILogger $logger
	 */
	public function __construct(IConfig $config,
								IURLGenerator $generator,
								ISession $session,
								ILogger $logger) {
		$this->session = $session;
		$this->config = $config;
		$this->generator = $generator;
		$this->logger = $logger;

		$openIdConfig = $this->getOpenIdConfig();
		if ($openIdConfig === null) {
			return;
		}
		parent::__construct(
			$openIdConfig['provider-url'],
			$openIdConfig['client-id'],
			$openIdConfig['client-secret']
		);
		$this->addScope(['openid', 'profile', 'email']);

		if ($this->config->getSystemValue('debug', false)) {
			$this->setVerifyHost(false);
			$this->setVerifyPeer(false);
		}
		// set config parameters in case well known is not supported
		if (isset($openIdConfig['provider-params'])) {
			$dump = \json_encode($openIdConfig['provider-params']);
			$logger->info("Manual configuration of endpoints: {$dump}", ['app' => 'OpenID']);
			$this->providerConfigParam($openIdConfig['provider-params']);
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
			$this->wellKnownConfig = \json_decode($this->fetchURL($well_known_config_url));
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

	public function authenticate() {
		$redirectUrl = $this->generator->linkToRouteAbsolute('openidconnect.loginFlow.login');
		$this->setRedirectURL($redirectUrl);
		return parent::authenticate();
	}
}
