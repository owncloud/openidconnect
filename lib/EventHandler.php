<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2020, ownCloud GmbH
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
namespace OCA\OpenIdConnect;

use OCA\OpenIdConnect\Sabre\OpenIdSabreAuthBackend;
use OCA\OpenIdConnect\LoginChecker;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use Sabre\DAV\Auth\Plugin;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EventHandler {

	/** @var EventDispatcherInterface */
	private $dispatcher;
	/** @var IRequest */
	private $request;
	/** @var IUserSession */
	private $userSession;
	/** @var ISession */
	private $session;
	/** @var LoginChecker */
	private $loginChecker;

	public function __construct(
		EventDispatcherInterface $dispatcher,
		IRequest $request,
		IUserSession $userSession,
		ISession $session,
		LoginChecker $loginChecker
	) {
		$this->dispatcher = $dispatcher;
		$this->request = $request;
		$this->userSession = $userSession;
		$this->session = $session;
		$this->loginChecker = $loginChecker;
	}

	public function registerEventHandler(): void {
		$this->dispatcher->addListener('OCA\DAV\Connector\Sabre::authInit', function ($event) {
			if (!$event instanceof SabrePluginEvent) {
				return;
			}
			if ($event->getServer() === null) {
				return;
			}
			$authPlugin = $event->getServer()->getPlugin('auth');
			if ($authPlugin instanceof Plugin) {
				$authPlugin->addBackend($this->createAuthBackend());
			}
		});
	}

	public function registerLoginHook(): void {
		$this->dispatcher->addListener('user.afterlogin', function ($event) {
			$eventArgs = $event->getArguments();
			if (!isset($eventArgs['loginType'])) {
				$eventArgs['loginType'] = null;  // auth modules (oidc) don't have loginType associated
			}
			$this->loginChecker->ensurePasswordLoginJustForGuest($eventArgs['loginType'], $eventArgs['uid']);  // will throw a LoginException if not guest
		});
	}

	/**
	 * @return OpenIdSabreAuthBackend
	 * @throws \OCP\AppFramework\QueryException
	 * @codeCoverageIgnore
	 */
	protected function createAuthBackend(): OpenIdSabreAuthBackend {
		$module = \OC::$server->query(OpenIdConnectAuthModule::class);
		return new OpenIdSabreAuthBackend(
			$this->session,
			$this->userSession,
			$this->request,
			$module,
			'principals/users/'
		);
	}
}
