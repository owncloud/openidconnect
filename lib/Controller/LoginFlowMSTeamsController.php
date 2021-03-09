<?php
/**
 * @author Florian Schade <f.schade@icloud.com>
 *
 * @copyright Copyright (c) 2021, ownCloud GmbH
 * @license GPL-2.0
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OpenIdConnect\Controller;

use OC\User\Session;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Controller\LoginFlowController as OIDCLoginFlowController;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

class LoginFlowMSTeamsController extends OIDCLoginFlowController {
	/**
	 * @var Session
	 */
	private $userSession;

	/**
	 * @var IURLGenerator
	 */
	private $generator;

	/** @var IConfig */
	private $config;

	public function __construct(string $appName,
								IRequest $request,
								UserLookupService $userLookup,
								IUserSession $userSession,
								ISession $session,
								ILogger $logger,
								Client $client,
								ICacheFactory $memCacheFactory,
								IURLGenerator $generator,
								IConfig $config
	) {
		parent::__construct($appName, $request, $userLookup, $userSession, $session, $logger, $client, $memCacheFactory, $config, $generator);

		$this->userSession = $userSession;
		$this->generator = $generator;
		$this->config = $config;

		$client->setRedirectURL($generator->linkToRouteAbsolute('openidconnect.loginFlowMSTeams.login'));
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @CORS
	 */
	public function index() {
		if ($this->userSession->isLoggedIn()) {
			return new RedirectResponse(\OC_Util::getDefaultPageUrl());
		}

		$config = $this->config->getSystemValue('openid-connect', null);
		$msTeamsConfig = $config['ms-teams'];

		return new TemplateResponse(
			'openidconnect',
			'teams/index',
			[
				'url' => $this->generator->linkToRouteAbsolute('openidconnect.loginFlowMSTeams.login'),
				'loginButtonName' => $msTeamsConfig['loginButtonName'] ?? 'Login with Azure AD',
			],
			'login'
		);
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @CORS
	 */
	public function finalize(): TemplateResponse {
		return new TemplateResponse(
			'openidconnect',
			'teams/finalize',
			[
				'url' => \OC_Util::getDefaultPageUrl()
			],
			'custom'
		);
	}

	/**
	 * @return string
	 */
	protected function getDefaultUrl(): string {
		return $this->generator->linkToRouteAbsolute('openidconnect.loginFlowMSTeams.finalize');
	}
}
