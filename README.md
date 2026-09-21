# OpenId Connect for ownCloud

[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=owncloud_openidconnect&metric=alert_status)](https://sonarcloud.io/dashboard?id=owncloud_openidconnect)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=owncloud_openidconnect&metric=security_rating)](https://sonarcloud.io/dashboard?id=owncloud_openidconnect)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=owncloud_openidconnect&metric=coverage)](https://sonarcloud.io/dashboard?id=owncloud_openidconnect)

## Configuration

### General

A distributed memcache setup is required to properly operate this app - like Redis or memcached.
For development purpose APCu is reasonable as well.
Please follow the [documentation on how to set up caching](https://doc.owncloud.com/server/admin_manual/configuration/server/caching_configuration.html#supported-caching-backends).

### Setup

The OpenId integration is established by either entering the parameters below to the
ownCloud configuration file or saving them to the app config database table.

_provider-url_, _client-id_ and _client-secret- are to be taken from the OpenId
Provider setup.
_loginButtonName_ can be chosen freely depending on the installation.

### Settings in database

If you run a clustered setup, the following method is preferred because it is stateless. The OpenID Connect app checks for settings in the database first. If none is found, it falls back to the settings stored in `config.php`. If a malformed JSON string is found, an error is logged. You have to store your settings as a JSON formatted string in the ownCloud database table `oc_appconfig` with the following keys:

| Key         | Value            |
| ----------- | ---------------- |
| appid       | 'openidconnect'  |
| configkey   | 'openid-connect' |
| configvalue | _JSON-String_    |

The _key->value_ pairs are the same as when storing them to the `config.php` file. The preferred method is using the occ command:

```
occ config:app:set openidconnect openid-connect \
--value='{"provider-url":"https://idp.example.net","client-id":"fc9b5c78-ec73-47bf-befc-59d4fe780f6f","client-secret":"e3e5b04a-3c3c-4f4d-b16c-2a6e9fdd3cd1","loginButtonName":"Login via OpenId Connect"}'
```

This task can also be done by opening the database console for your ownCloud database and enter the following example command. Use the database commands `UPDATE` or `DELETE` to change or delete this keys (not recommended).

```
INSERT INTO oc_appconfig (
  appid,
  configkey,
  configvalue
) VALUES (
  'openidconnect',
  'openid-connect',
  '{"provider-url":"https://idp.example.net","client-id":"fc9b5c78-ec73-47bf-befc-59d4fe780f6f","client-secret":"e3e5b04a-3c3c-4f4d-b16c-2a6e9fdd3cd1","loginButtonName":"Login via OpenId Connect"}'
);
```

Note: The app checks for settings in the database first. If none is found it falls back to the config.php. If a malformed JSON string is found an error is thrown to the logger instance.

### Settings in config.php

```php
<?php
$CONFIG = [
  'openid-connect' => [
    'provider-url' => 'https://idp.example.net',
    'client-id' => 'fc9b5c78-ec73-47bf-befc-59d4fe780f6f',
    'client-secret' => 'e3e5b04a-3c3c-4f4d-b16c-2a6e9fdd3cd1',
    'loginButtonName' => 'OpenId Connect',
  ],
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
    'post_logout_redirect_uri' => '...',
    'provider-params' => [
      'authorization_endpoint' => '...',
      'token_endpoint' => '...',
      'token_endpoint_auth_methods_supported' => '...',
      'userinfo_endpoint' => '...',
      'registration_endpoint' => '...',
      'end_session_endpoint' => '...',
      'jwks_uri' => '...',
    ],
  ],
];
```

### Setup auto provisioning mode

The auto provisioning mode will create a user based on the provided user information as returned by the OpenID Connect provider.
The config parameters 'mode' and 'search-attribute' will be used to create a unique user so that the lookup mechanism can find the user again.

```php
<?php
$CONFIG = [
  'openid-connect' => [
    'auto-provision' => [
      // explicit enable the auto provisioning mode
      'enabled' => true,
      // documentation about standard claims: https://openid.net/specs/openid-connect-core-1_0.html#StandardClaims
      // only relevant in userid mode,  defines the claim which holds the email of the user
      'email-claim' => 'email',
      // defines the claim which holds the display name of the user
      'display-name-claim' => 'given_name',
      // defines the claim which holds the picture of the user - must be a URL
      'picture-claim' => 'picture',
      // defines a list of groups to which the newly created user will be added automatically
      'groups' => ['admin', 'guests', 'employees'],
    ],
  ],
];
```

#### Setup auto-update of user account info

The provisioning auto-update mode will update user account info with current information provided by the OpenID Connect provider
upon each log in.

```php
$CONFIG = [
  'openid-connect' => [
    'auto-provision' => [
      'update' => [
        // enable the user info auto-update mode
        'enabled' => true,
      ],
    ],
  ],
];
```

#### All Configuration Values explained

- loginButtonName - the name as displayed on the login screen which is used to redirect to the IdP
- autoRedirectOnLoginPage - if set to true the login page will redirect to the Idp right away
- provider-url - the url where the IdP is living. In some cases (KeyCloak, Azure AD) this holds more than just a domain but also a path
- client-id & client-secret - self-explanatory
- audience - the value the IdP puts into the access token's aud claim. Out of the box an access token is accepted when its aud carries the client-id, or when a claim naming the client the token was issued to does: azp (OpenID Connect Core 1.0 §2), appid (Entra ID v1.0 tokens and AD FS) or client_id (RFC 7662) - an access token's aud belongs to the resource server (RFC 9068 §3), so many IdPs never put the client-id there. Keycloak sends no aud at all unless an audience mapper is configured, Entra ID v1.0 tokens send the App ID URI, ADFS sends microsoft:identityserver:<identifier> (prefixing the application identifier from `Get-AdfsWebApiApplication`, an OpenID Connect application group, or `Get-AdfsRelyingPartyTrust`, a legacy WS-Federation or SAML trust, unless that identifier is already a URL). Set audience to declare what your IdP actually sends and it becomes the only accepted value, on the introspection path too: takes a string or a list of strings, replaces client-id rather than adding to it, and must match exactly, including case. That cuts both ways - a token issued to this client for some other resource is then rejected, which is the reason to set it, but a token issued to a different client of the same IdP is accepted when its aud names us, so pick a value only ownCloud's relying party can be issued for. Leaving it unset still refuses a token an attacker obtained for their own client and addressed at their own client; what it adds is a binding to the resource, so set it if your IdP issues tokens to ownCloud's client for more than one resource (RFC 8707, RFC 8693). It cannot keep another client's tokens out: a token whose aud names ownCloud is accepted whichever client requested it, with or without the key, and a client of the same IdP can often be granted exactly that - an audience mapper of its own, or a resource parameter naming ownCloud - which is a decision to make in the IdP. Independently of the audience, a token that declares itself not to be an access token is refused - typ of Refresh or Offline (Keycloak), token_use of refresh (AWS Cognito). ID tokens are not covered by that rule: an ID token's aud is the client-id by definition, so one presented as a bearer token is accepted. Do not set audience at all if your introspection response omits aud, since every opaque token would then be rejected. With exchange-token-mode-before-introspection the first entry is also what the token exchange asks the IdP for, so list the resource ownCloud should be given first
- scopes - depending on the IdP setup, needs the list of required scopes to be entered here
- insecure - boolean value (true/false), no ssl verification will take place when talking to the IdP - DON'T use in production
- provider-params - additional config depending on the IdP is to be entered here - usually only necessary if the IdP does not support service discovery
- auth-params - additional parameters which are sent to the IdP during the auth requests
- redirect-url - the full url under which the ownCloud OpenId Connect redirect url is reachable - only needed in special setups
- token-introspection-endpoint-client-id & token-introspection-endpoint-client-secret - client id and secret to be used with the token introspection endpoint
- post_logout_redirect_uri - a given url where the IdP should redirect to after logout
- mode - the mode to search for user in ownCloud - either userid or email
- search-attribute - the attribute which is taken from the access token JWT or user info endpoint to identify the user
- allowed-user-backends - limit the users which are allowed to login to a specific user backend - e.g. LDAP
- use-access-token-payload-for-user-info - if set to true any user information will be read from the access token. If set to false the userinfo endpoint is used (starting app version 1.1.0)
- jwt-self-signed-jwk-header-supported - if set to true JWK will be taken from the JWT header instead of the IdP's jwks_uri. Should only be enabled in exceptional cases as this could lead to vulnerabilities https://portswigger.net/kb/issues/00200902_jwt-self-signed-jwk-header-supported

### Setup within the OpenId Provider

When registering ownCloud as OpenId Client use `https://cloud.example.net/index.php/apps/openidconnect/redirect` as redirect url .

In case [OpenID Connect Front-Channel Logout 1.0](https://openid.net/specs/openid-connect-frontchannel-1_0.html)
is supported please enter `https://cloud.example.net/index.php/apps/openidconnect/logout` as logout url within the client registration of the OpenId Provider.
We require `frontchannel_logout_session_required` to be true.

### Setup service discovery

In order to allow other clients to use OpenID Connect when talking to ownCloud please setup
a redirect on the web server to point .well-known/openid-configuration to /index.php/apps/openidconnect/config

This is an .htaccess example

```
  RewriteRule ^\.well-known/openid-configuration /index.php/apps/openidconnect/config [P]
```

The Apache modules proxy and proxy_http need to be enabled. (Debian/Ubuntu: a2enmod proxy proxy_http)

### How to setup an IdP for development and test purpose

There are various Open Source IdPs out there. The one with the most features implemented seems to be [panva/node-oidc-provider](https://github.com/panva/node-oidc-provider).
CAUTION: node-oidc-provider does not accept the redirect URLs we need for owncloud clients. For release testing, use kopano konnectd instead.

To set it up locally do the following:

1. Clone panva/node-oidc-provider
2. yarn install
3. cd example
4. Add client config into <https://github.com/panva/node-oidc-provider/blob/master/example/support/configuration.js#L14>

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

   // Enable introspection
   module.exports.features: {
      devInteractions: { enabled: false },
      introspection: { enabled: true },
      deviceFlow: { enabled: true },
      revocation: { enabled: true },
      issAuthResp: { enabled: true },
   },

   ```

5. Start the IdP via: `node standalone.js`
6. Open in browser: <http://localhost:3000/.well-known/openid-configuration>
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
         // do not verify tls host or peer
         'insecure' => true
     ],
   ];

   ```

8. Clients can now use <http://localhost:3000/.well-known/openid-configuration> to obtain all information which is necessary
   to initiate the OpenId Connect flow. Use the granted access token in any request to ownCloud within a bearer authentication header.
9. You can login with any credentials but you need to make sure that the user with the given user id exists. In a real world deployment the users will come from LDAP.
-  Keep in mind that by default, oidc app will search for the `email` attribute - which is hardcoded to `johndoe@example.com` [ref](https://github.com/panva/node-oidc-provider/blob/master/example/support/account.js#L32)
-  If you wish to map the login name on the oidc-provider with owncloud user ids, you can configure it as following:

```
    $CONFIG = [
      'openid-connect' => [
        'search-attribute' => 'sub',
        'mode' => 'userid',
      ]
```
