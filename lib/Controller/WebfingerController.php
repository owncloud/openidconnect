<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2023, ownCloud GmbH
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

use Jumbojett\OpenIDConnectClientException;
use OCA\OpenIdConnect\Client;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class WebfingerController extends Controller {
	private Client $client;
	private IURLGenerator $generator;

	public function __construct(
		string        $appName,
		IRequest      $request,
		Client        $client,
		IURLGenerator $generator
	) {
		parent::__construct($appName, $request);
		$this->client = $client;
		$this->generator = $generator;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @CORS
	 * @throws OpenIDConnectClientException
	 */
	public function index(): JSONResponse {
		if ($this->client->getOpenIdConfig() === null) {
			return new JSONResponse([]);
		}
		$openIdConfig = $this->client->getOpenIdConfig();
		$subject = $this->generator->getAbsoluteURL('');
		$issuer = $this->client->getProviderURL();

		$webfinger_properties = $openIdConfig['webfinger']['properties'] ?? [];

		$wellKnownData = [
			"subject" => $subject,
			"links" => [
				[
					"rel" => "http://openid.net/specs/connect/1.0/issuer",
					"href" => $issuer,
					"properties" => $webfinger_properties
				]
			]
		];
		return new JSONResponse($wellKnownData);
	}
}
