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

use InvalidArgumentException;
use OCA\OpenIdConnect\Logger;
use OCP\ILogger;
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

	public function providesMethods(): array {
		return [
			['alert'],
			['critical'],
			['emergency'],
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
		$ex = new InvalidArgumentException();
		$this->innerLogger->expects(self::once())->method('logException')->with($ex, ['app' => 'OpenID']);
		$this->logger->logException($ex);
	}
}
