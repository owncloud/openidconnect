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
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Sabre\DAV\Auth\Plugin;
use Sabre\DAV\Server;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

class EventHandlerTest extends TestCase {

	/**
	 * @var MockObject | ISession
	 */
	private $session;
	/**
	 * @var MockObject | EventHandler
	 */
	private $eventHandler;
	/**
	 * @var MockObject | IUserSession
	 */
	private $userSession;
	/**
	 * @var MockObject | EventDispatcherInterface
	 */
	private $dispatcher;
	/**
	 * @var MockObject | IRequest
	 */
	private $request;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(EventDispatcherInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->eventHandler = $this->getMockBuilder(EventHandler::class)
			->setConstructorArgs([$this->dispatcher, $this->request, $this->userSession, $this->session])
			->setMethods(['createAuthBackend'])
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
}
