<?php
$CONFIG = [
	'openid-connect' => [
		'provider-url' => 'http://localhost:3000',
		'client-id' => 'ownCloud',
		'client-secret' => 'ownCloud',
		'provider-params' => [
			'authorization_endpoint' => 'http://localhost:3000/auth',
			'token_endpoint' => 'http://oidc:3000/token',
			'token_endpoint_auth_methods_supported' => [
				"none",
				"client_secret_basic",
				"client_secret_jwt",
				"client_secret_post",
				"private_key_jwt"
			],
			'userinfo_endpoint' => 'http://oidc:3000/me',
			'registration_endpoint' => 'http://oidc:3000/reg',
			'end_session_endpoint' => 'http://oidc:3000/session/end',
			'jwks_uri' => 'http://oidc:3000/certs'
		],
	],
];
