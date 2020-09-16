# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [1.0.0] - 2020-09-16

### Added

- Add configurable post_logout_redirect_uri - [#90](https://github.com/owncloud/openidconnect/issues/90)

### Changed

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

[1.0.0]: https://github.com/owncloud/openidconnect/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/owncloud/openidconnect/compare/0.1.0...v0.2.0
