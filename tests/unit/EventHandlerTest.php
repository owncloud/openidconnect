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

namespace OCA\OpenIdConnect\Tests\Unit;

use OCA\OpenIdConnect\EventHandler;
use OCA\OpenIdConnect\LoginChecker;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Sabre\DAV\Auth\Plugin;
use Sabre\DAV\Server;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

class EventHandlerTest extends TestCase {

	/**
	 * @var MockObject | EventHandler
	 */
	private $eventHandler;
	/**
	 * @var MockObject | EventDispatcherInterface
	 */
	private $dispatcher;
	/** @var LoginChecker */
	private $loginChecker;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);
		$request = $this->createMock(IRequest::class);
		$session = $this->createMock(ISession::class);
		$userSession = $this->createMock(IUserSession::class);
		$this->loginChecker = $this->createMock(LoginChecker::class);

		$this->eventHandler = $this->getMockBuilder(EventHandler::class)
			->setConstructorArgs([$this->dispatcher, $request, $userSession, $session, $this->loginChecker])
			->onlyMethods(['createAuthBackend'])
			->getMock();
	}

	public function testAddListener(): void {
		$this->dispatcher->expects(self::once())->method('addListener')->with('OCA\DAV\Connector\Sabre::authInit');
		$this->eventHandler->registerEventHandler();
	}

	public function testListenerDifferentEvent(): void {
		$event = $this->createMock(Event::class);
		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->expects(self::never())->method('createAuthBackend');
		$this->eventHandler->registerEventHandler();
	}

	public function testListenerNullServer(): void {
		$event = $this->createMock(SabrePluginEvent::class);
		$event->expects(self::once())->method('getServer');
		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->expects(self::never())->method('createAuthBackend');
		$this->eventHandler->registerEventHandler();
	}

	public function testListener(): void {
		$plugin = $this->createMock(Plugin::class);
		$plugin->expects(self::once())->method('addBackend');
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method('getPlugin')->willReturn($plugin);
		$event = new SabrePluginEvent($server);
		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->expects(self::once())->method('createAuthBackend');
		$this->eventHandler->registerEventHandler();
	}

	public function testAfterLoginHook(): void {
		$this->loginChecker->expects($this->once())
			->method('ensurePasswordLoginJustForGuest')
			->with('customLoginType', 'myUid');

		$user = $this->createMock(IUser::class);
		$event = new GenericEvent(null, ['loginType' => 'customLoginType', 'user' => $user, 'uid' => 'myUid', 'password' => 'mypassword']);

		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->registerLoginHook();
	}

	public function testAfterLoginHookNoLoginType(): void {
		$this->loginChecker->expects($this->once())
			->method('ensurePasswordLoginJustForGuest')
			->with(null, 'myUid');

		$user = $this->createMock(IUser::class);
		$event = new GenericEvent(null, ['user' => $user, 'uid' => 'myUid', 'password' => 'mypassword']);

		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->registerLoginHook();
	}
}
