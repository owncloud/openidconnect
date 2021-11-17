# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [Unreleased] - XXXX-XX-XX



## [2.1.0] - 2021-10-29

- chore: jumbojett/openid-connect-php seems unmaintained - we move to juliuspc/openid-connect-php [#183](https://github.com/owncloud/openidconnect/pull/183)
- [Enhancement] Add db as additional settings storage backend [167](https://github.com/owncloud/openidconnect/pull/167)
- PKCE Flow challenge was not used - [#170](https://github.com/owncloud/openidconnect/pull/170)
- Use random_bytes to generate auto-provisioning user-id and password - [#154](https://github.com/owncloud/openidconnect/issues/154)
- Provision accounts based on auto-provisioning claim - [#149](https://github.com/owncloud/openidconnect/issues/149)
- Add app db table as additional, optional config storage - [#67](https://github.com/owncloud/openidconnect/pull/167)


## [2.0.0] - 2021-01-10

### Added

- Import user from openid provider: Auto provisioning mode - [#85](https://github.com/owncloud/openidconnect/issues/85)
- Azure AD: Use access token payload instead of user info endpoint - [#103](https://github.com/owncloud/openidconnect/issues/103)
- Limit OpenID Connect logins to users of specific user backend - [#100](https://github.com/owncloud/openidconnect/issues/100)

### Changed

- Message: Object of class OCA\OpenIdConnect\Application could not be converted to string - [#112](https://github.com/owncloud/openidconnect/issues/112)
- Properly handle token expiry in the sabre dav auth backend - [#106](https://github.com/owncloud/openidconnect/issues/106)
- Properly evaluate the config setting use-token-introspection-endpoint [#98](https://github.com/owncloud/openidconnect/issues/98)
- Use built-in session functions of the OpenID Connect Library - [#97](https://github.com/owncloud/openidconnect/issues/97)
- Bump libraries

## [1.0.0] - 2020-10-16

### Added

- Add configurable post_logout_redirect_uri - [#90](https://github.com/owncloud/openidconnect/issues/90)

### Changed

- Properly handle token expiry in the sabre dav auth backend - [#108](https://github.com/owncloud/openidconnect/pull/108)
- Limit OpenID Connect logins to users of specific user backend - [#100](https://github.com/owncloud/openidconnect/issues/100)
- Properly evaluate the config setting use-token-introspection-endpoint - [#98](https://github.com/owncloud/openidconnect/issues/98)
- Bump libraries


## [0.2.0] - 2020-02-11

### Changed

- Drop Support for PHP7.0 - [#40](https://github.com/owncloud/openidconnect/pull/40)
- Perform local logout before calling idp - [#45](https://github.com/owncloud/openidconnect/pull/45)
- Introduce LoginPageBehaviour - [#53](https://github.com/owncloud/openidconnect/pull/53)
- Re-license under GPLv2 - [#57](https://github.com/owncloud/openidconnect/pull/57)

## 0.1.0 - 2019-11-13

- Initial Release

[Unreleased]: https://github.com/owncloud/openidconnect/compare/v2.1.0...master
[2.1.0]: https://github.com/owncloud/openidconnect/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/owncloud/openidconnect/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/owncloud/openidconnect/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/owncloud/openidconnect/compare/0.1.0...v0.2.0
