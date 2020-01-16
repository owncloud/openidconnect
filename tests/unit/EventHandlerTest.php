<?php

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
			->setMethods(['createPlugin'])
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
		$this->eventHandler->expects(self::never())->method('createPlugin');
		$this->eventHandler->registerEventHandler();
	}

	public function testListenerNullServer(): void {
		$event = $this->createMock(SabrePluginEvent::class);
		$event->expects(self::once())->method('getServer');
		$this->dispatcher->method('addListener')->willReturnCallback(static function ($name, $callback) use ($event) {
			$callback($event);
		});
		$this->eventHandler->expects(self::never())->method('createPlugin');
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
		$this->eventHandler->expects(self::once())->method('createPlugin');
		$this->eventHandler->registerEventHandler();
	}
}
