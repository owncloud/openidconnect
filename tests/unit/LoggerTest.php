<?php

namespace OCA\OpenIdConnect\Tests\Unit;

use Jumbojett\OpenIDConnectClientException;
use OC\HintException;
use OC\Memcache\ArrayCache;
use OCA\OpenIdConnect\Client;
use OCA\OpenIdConnect\Logger;
use OCA\OpenIdConnect\OpenIdConnectAuthModule;
use OCA\OpenIdConnect\Service\UserLookupService;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LoggerTest extends TestCase {

	/**
	 * @var MockObject | ILogger
	 */
	private $innerLogger;
	/**
	 * @var Logger
	 */
	private $logger;

	public function providesMethods() {
		return [
			['alert'],
			['critical'],
			['error'],
			['warning'],
			['notice'],
			['info'],
			['debug'],
		];
	}

	protected function setUp(): void {
		parent::setUp();
		$this->innerLogger = $this->createMock(ILogger::class);
		$this->logger = new Logger($this->innerLogger);
	}

	/**
	 * @dataProvider providesMethods
	 */
	public function testAlert($method): void {
		$this->innerLogger->expects(self::once())->method($method)->with('alert message', ['app' => 'OpenID']);
		$this->logger->$method('alert message');
	}

	public function testLog(): void {
		$this->innerLogger->expects(self::once())->method('log')->with(3, 'alert message', ['app' => 'OpenID']);
		$this->logger->log(3, 'alert message');
	}

	public function testLogException(): void {
		$ex = new \InvalidArgumentException();
		$this->innerLogger->expects(self::once())->method('logException')->with($ex, ['app' => 'OpenID']);
		$this->logger->logException($ex);
	}
}
