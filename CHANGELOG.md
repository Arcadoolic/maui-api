## [0.1.0](https://github.com/Arcadoolic/maui-api/compare/0.0.0...0.1.0) (2026-09-25)

### Features

* **admin:** give service accounts a descriptive name ([9082da6](https://github.com/Arcadoolic/maui-api/commit/9082da623c7fae550dc210afefde4424c2f91942))
* **admin:** let one owner have several cabinets ([32030c6](https://github.com/Arcadoolic/maui-api/commit/32030c6cbec441f4f13aab9c85a853b05d795743))
* **admin:** manage clients from the Filament back office ([406d458](https://github.com/Arcadoolic/maui-api/commit/406d45893e6cbf75085c5289afe4ef60d415da95))
* **admin:** show back office dates in the admin's timezone ([e3754dc](https://github.com/Arcadoolic/maui-api/commit/e3754dced47e7aec9538a615f9e21d3fa29c651e))
* **api:** authenticate cabinets with machine binding ([744f3f1](https://github.com/Arcadoolic/maui-api/commit/744f3f1bc30bfc28aecdd6c816c81ef9ac30ce20))
* **api:** store the readable OS name sent at startup ([22fe817](https://github.com/Arcadoolic/maui-api/commit/22fe8173a35f74cdf9bedae4aa1224d050098a31))
* **invitations:** let cabinet owners claim credentials once ([45dd3d3](https://github.com/Arcadoolic/maui-api/commit/45dd3d3b56807a51ba57e38cde41d682f3accf59))
* **invitations:** let owners draw their cabinet name before claiming ([b835c71](https://github.com/Arcadoolic/maui-api/commit/b835c71cef7a7bcc0c93952d04fe4dd2190ed6a2))
* trust X-Forwarded-* headers from TRUSTED_PROXIES ([18bb39e](https://github.com/Arcadoolic/maui-api/commit/18bb39e59ef4ca8cb68dabc8d7a0e7262552bc2f))

### Bug Fixes

* **admin:** render the MFA setup QR code ([01e355a](https://github.com/Arcadoolic/maui-api/commit/01e355a029bae2826b58cc1889e34ebb0a9295bd))
* **tests:** never run the test suite on the dev database ([6d673cb](https://github.com/Arcadoolic/maui-api/commit/6d673cb37c1ce248c4fa37961c596ba222855ae1))

### Reverts

* drop TRUSTED_PROXIES, FrankenPHP terminates TLS on staging ([b3002cb](https://github.com/Arcadoolic/maui-api/commit/b3002cbe3549e75aa71c3c178e7cabbcc9056c77))
