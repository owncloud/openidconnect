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

use OCA\OpenIdConnect\Sabre\OpenIdSabreAuthPlugin;
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

	public function __construct(EventDispatcherInterface $dispatcher,
								IRequest $request,
								IUserSession $userSession,
								ISession $session) {
		$this->dispatcher = $dispatcher;
		$this->request = $request;
		$this->userSession = $userSession;
		$this->session = $session;
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
				$authPlugin->addBackend($this->createPlugin());
			}
		});
	}

	/**
	 * @return OpenIdSabreAuthPlugin
	 * @throws \OCP\AppFramework\QueryException
	 * @codeCoverageIgnore
	 */
	protected function createPlugin(): OpenIdSabreAuthPlugin {
		$module = \OC::$server->query(OpenIdConnectAuthModule::class);
		return new OpenIdSabreAuthPlugin($this->session,
			$this->userSession,
			$this->request,
			$module,
			'principals/');
	}
}
