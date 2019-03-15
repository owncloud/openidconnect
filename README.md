# OpenId Connect for ownCloud

## Configuration

### General
A distributed memcache setup is required to properly operate this app - like Redis or memcached.
For development purpose APCu is reasonable as well.
Please follow the [documentation on how to set up caching](https://doc.owncloud.org/server/admin_manual/configuration/server/caching_configuration.html#supported-caching-backends).

### Setup config.php
The OpenId integration is established by entering the parameters below to the 
ownCloud configuration file.
_provider-url_, _client-id_ and _client-secret- are to be taken from the OpenId 
Provider setup.
_loginButtonName_ can be chosen freely depending on the installation.

```php
<?php
$CONFIG = [
  'openid-connect' => [
	'provider-url' => 'https://idp.example.net',
	'client-id' => 'fc9b5c78-ec73-47bf-befc-59d4fe780f6f',
	'client-secret' => 'e3e5b04a-3c3c-4f4d-b16c-2a6e9fdd3cd1',
	'loginButtonName' => 'OpenId Connect'
  ]
];

```

The above configuration assumes that the OpenId Provider is supporting service discovery.
If not the endpoint configuration has to be done manually as follows:
```php
<?php
$CONFIG = [
  'openid-connect' => [
    'provider-url' => 'https://idp.example.net',
    'client-id' => 'fc9b5c78-ec73-47bf-befc-59d4fe780f6f',
    'client-secret' => 'e3e5b04a-3c3c-4f4d-b16c-2a6e9fdd3cd1',
    'loginButtonName' => 'OpenId Connect',
    'provider-params' => [
      'authorization_endpoint' => '...',
      'token_endpoint' => '...',
      'token_endpoint_auth_methods_supported' => '...',
      'userinfo_endpoint' => '...',
      'registration_endpoint' => '...',
      'end_session_endpoint' => '...',
      'jwks_uri' => '...'
    ]
  ]
];


```

### Setup within the OpenId Provider
When registering ownCloud as OpenId Client use ```https://cloud.example.net/index.php/apps/openidconnect/redirect``` as redirect url .

In case [OpenID Connect Front-Channel Logout 1.0](https://openid.net/specs/openid-connect-frontchannel-1_0.html) 
is supported please enter ```https://cloud.example.net/index.php/apps/openidconnect/logout``` as logout url within the client registration of the OpenId Provider.
We require ```frontchannel_logout_session_required``` to be true.

### Setup service discovery
In order to allow other clients to use OpenID Connect when talking to ownCloud please setup 
a redirect on the web server to point .well-known/openid-configuration to /index.php/apps/openidconnect/config

This is an .htaccess example
```
  RewriteRule ^\.well-known/openid-configuration /index.php/apps/openidconnect/config [QSA,L]
```

Please note that service discovery is not mandatory at the moment since no client is supporting this yet.

