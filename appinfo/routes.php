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

return [
	'routes' => [
		// openid config endpoint
		['name' => 'loginFlow#config', 'url' => '/config', 'verb' => 'GET'],
		// auth flow
		['name' => 'loginFlow#login', 'url' => '/login', 'verb' => 'GET'],
		['name' => 'loginFlow#login', 'url' => '/redirect', 'verb' => 'GET'],
		// front channel logout url
		['name' => 'loginFlow#logout', 'url' => '/logout', 'verb' => 'GET'],
	]
];
