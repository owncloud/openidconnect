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
