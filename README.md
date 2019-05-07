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
  RewriteRule ^\.well-known/openid-configuration /index.php/apps/openidconnect/config [R=301,L]
```

Please note that service discovery is not mandatory at the moment since no client is supporting this yet.

### How to setup an IdP for development and test purpose

There are various Open Source IdPs out there. The one with the most features implemented seems to be [panva/node-oidc-provider](https://github.com/panva/node-oidc-provider).

To set it up locally do the following:
1. Clone panva/node-oidc-provider
2. yarn install
3. cd example
4. Add client config into https://github.com/panva/node-oidc-provider/blob/d47ef0ee05cdf888325eef0902883a9e49899b21/example/support/configuration.js#L84
    ```
    module.exports.clients = [
      {
        client_id: 'ownCloud',
        client_secret: 'ownCloud',
        grant_types: ['refresh_token', 'authorization_code'],
        redirect_uris: ['http://localhost:8080/index.php/apps/openidconnect/redirect'],
        frontchannel_logout_uri: 'http://localhost:8080/index.php/apps/openidconnect/logout'
      }
    ];
    ```
5. Start the IdP via: ```node standalone.js```
6. Open in browser: http://localhost:3000/.well-known/openid-configuration
7. ownCloud configuration looks as follows:
    ```
    $CONFIG = [
      'openid-connect' => [
          'provider-url' => 'http://localhost:3000',
          'client-id' => 'ownCloud',
          'client-secret' => 'ownCloud',
          'loginButtonName' => 'node-oidc-provider',
          'mode' => 'userid',
          'search-attribute' => 'sub',
          'use-token-introspection-endpoint' => true
      ],
    ];
    
    ```
8. Clients can now use http://localhost:3000/.well-known/openid-configuration to obtain all information which is necessary
to initiate the OpenId Connect flow. Use the granted access token in any request to ownCloud within a bearer authentication header.
9. You can login with any credentials but you need to make sure that the user with the given user id exists. In a real world deployment the users will come from LDAP.
