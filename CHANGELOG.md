## [0.10.0](https://github.com/Arcadoolic/maui-api/compare/0.9.0...0.10.0) (2026-10-10)

### Features

* **front:** "Missed Date", the games every cabinet turned down ([743cd89](https://github.com/Arcadoolic/maui-api/commit/743cd892be807bb9a9d295305f119e67a3b81c16))
* **front:** popularity of the games on the front's endpoints ([2c0cfcb](https://github.com/Arcadoolic/maui-api/commit/2c0cfcbc24af34ccc2781b9feac695fcb43a6ca2))
* **popularity:** popularity index and label of a game, from the cabinets' votes and plays ([9b03b68](https://github.com/Arcadoolic/maui-api/commit/9b03b68f164f69a9c860a331dcf43756b9e8105f))

## [0.9.0](https://github.com/Arcadoolic/maui-api/compare/0.8.0...0.9.0) (2026-10-10)

### Features

* **catalog:** flag the games whose hiscores can be read, list only those on the front ([ab6b7ae](https://github.com/Arcadoolic/maui-api/commit/ab6b7ae000bda0f663b65ddf0e65afbad0b68ba5))
* **opinions:** take each cabinet's vote and play count per game ([8558fb4](https://github.com/Arcadoolic/maui-api/commit/8558fb45e32b13162e5082c8f303ec1266e587e2))

## [0.8.0](https://github.com/Arcadoolic/maui-api/compare/0.7.1...0.8.0) (2026-10-09)

### Features

* **front:** give each game's screenshot in the games list ([f1defb4](https://github.com/Arcadoolic/maui-api/commit/f1defb4bc747c90789c40d8e9228a7d6e1cde8a2))

## [0.7.1](https://github.com/Arcadoolic/maui-api/compare/0.7.0...0.7.1) (2026-10-09)

### Bug Fixes

* **deploy:** no web health check on the scheduler container ([50f76a3](https://github.com/Arcadoolic/maui-api/commit/50f76a3475054d40d3fff8407eded7fd21da2227))

## [0.7.0](https://github.com/Arcadoolic/maui-api/compare/0.6.0...0.7.0) (2026-10-09)

### Features

* **admin:** delete a client with its scores ([c6edec9](https://github.com/Arcadoolic/maui-api/commit/c6edec90f887a15485e1043269f4bb6fa5e64c71))
* **api:** tell the cabinet its name and the server's environment ([0ffa4c6](https://github.com/Arcadoolic/maui-api/commit/0ffa4c63a52db467be43c60333b8bb1c95592c99))
* **deploy:** a scheduler container, and the nightly catalog scrape ([f153219](https://github.com/Arcadoolic/maui-api/commit/f15321919fae06452f2ceb2dc1fabeb907a78571))
* **front:** complete the game pages with ScreenScraper ([c486a92](https://github.com/Arcadoolic/maui-api/commit/c486a9296649a73e25d2c494f9c9a3c46f84346d))
* **front:** games, players and events for the hiscores front ([58f008d](https://github.com/Arcadoolic/maui-api/commit/58f008d27864707f56fdb0a5affdd512c3ae209c))
* **front:** global podium weighted by the competition ([9966dd9](https://github.com/Arcadoolic/maui-api/commit/9966dd9f24f441e2e032aa9190233fa8001106e1))
* **front:** members logged in with Discord, on invitation ([787f434](https://github.com/Arcadoolic/maui-api/commit/787f434b8515b46b3c6152a0cc1bf7bf7c1f5ec1))
* **front:** one player per member, and a remember cookie that works ([c3d986e](https://github.com/Arcadoolic/maui-api/commit/c3d986ea9f4f51870ea03946c6871ae7938479fb))
* **front:** player stats from what is already stored ([59b5245](https://github.com/Arcadoolic/maui-api/commit/59b5245f68cd32c46a8f244287645dc05dcc88cc))
* **scores:** mark the scores declared on the cabinet ([518239e](https://github.com/Arcadoolic/maui-api/commit/518239e8313c399bb55b9fb6e443bc9ae20d2834))
* **web:** draw Pac-Man and the ghost as pixel sprites ([f9821b5](https://github.com/Arcadoolic/maui-api/commit/f9821b518faa0e4bf7ef0949f9c67c1d21cf8f88))
* **web:** pixel font and endless pellets on the home page ([caee982](https://github.com/Arcadoolic/maui-api/commit/caee9825cdb9a84f7a5442a5748609d72d2f1d1b))
* **web:** replace Laravel welcome page with a Pac-Man chase animation ([582084c](https://github.com/Arcadoolic/maui-api/commit/582084c053c74fb35538bd301a89055aa7a53f75))

### Bug Fixes

* **front:** genres in English and synopses as plain text ([9159e9b](https://github.com/Arcadoolic/maui-api/commit/9159e9bf7817d47a71d9f3c7e61ecb973fb74a12))
* **front:** no needless instanceof on the member guard ([7e92c0c](https://github.com/Arcadoolic/maui-api/commit/7e92c0c0e7d50211ce3220406136bdfabb303ab5))

## [0.6.0](https://github.com/Arcadoolic/maui-api/compare/0.5.0...0.6.0) (2026-10-08)

### Features

* **scores:** record score events and serve them to the bots ([ea9bf97](https://github.com/Arcadoolic/maui-api/commit/ea9bf97bec12ddfc75dd1a7b1b8ad17ad45cb90f))

### Bug Fixes

* **deploy:** give production its own Compose file ([4850003](https://github.com/Arcadoolic/maui-api/commit/4850003ed819d81a2165ec46f74d0d710f40906a))

## [0.5.0](https://github.com/Arcadoolic/maui-api/compare/0.4.0...0.5.0) (2026-10-08)

### Features

* **leaderboards:** add a bot client type reading the leaderboards ([9cccf87](https://github.com/Arcadoolic/maui-api/commit/9cccf87b758b60255c805cd6f830622a5c38c3f4))

## [0.4.0](https://github.com/Arcadoolic/maui-api/compare/0.3.0...0.4.0) (2026-10-07)

### Features

* **players:** accept an avatar from the cabinet the player was created on only ([c068dff](https://github.com/Arcadoolic/maui-api/commit/c068dff4cac6bdb85627712b4f5d72cdd9992475))

## [0.3.0](https://github.com/Arcadoolic/maui-api/compare/0.2.0...0.3.0) (2026-10-06)

### Features

* **admin:** let admins set the origin cabinet of a player (D54) ([93ffe18](https://github.com/Arcadoolic/maui-api/commit/93ffe1810cffd4ac7bdb7d89c357f48d6218ea12))
* **admin:** MFA labelled after the server, off on demand in development (D55) ([5d1cc89](https://github.com/Arcadoolic/maui-api/commit/5d1cc89d6d703f904518de8d8a57f09e01504506))
* **catalog:** game catalog pushed by service accounts ([6905b65](https://github.com/Arcadoolic/maui-api/commit/6905b656544fada61a449227ac32134c4964c0f3))
* **dev:** php artisan dev:reset-scores ([76d8bb1](https://github.com/Arcadoolic/maui-api/commit/76d8bb13103d212b051e72a7358689433f347b18))
* **leaderboards:** shared leaderboards for the cabinets (Lot 2.4, D52) ([08a4193](https://github.com/Arcadoolic/maui-api/commit/08a4193e6c1e9076ccbd9753a61d36cc860cac88))
* **players:** a new PIN only from the cabinet the player was created on (D54) ([c7b5dde](https://github.com/Arcadoolic/maui-api/commit/c7b5dde80fd8734872a528a946b4c60ef490541b))
* **players:** avatars, PNG on disk with its hash as ETag (Lot 2.4, D53) ([acb272f](https://github.com/Arcadoolic/maui-api/commit/acb272fe0f8a81dfdd61d67b0f71c146e721c433))
* **players:** global players linked to cabinets with a PIN ([96a2143](https://github.com/Arcadoolic/maui-api/commit/96a2143e8cd1a1533e146163d23f727ecf7dc3b8))
* **players:** no new player with the same letter three times (D51) ([ad0586e](https://github.com/Arcadoolic/maui-api/commit/ad0586e5f607604e04503c17cdd5fc980d5f13ff))
* **players:** the avatar hash in the players of the cabinet (D53) ([2487bc1](https://github.com/Arcadoolic/maui-api/commit/2487bc1fc416b539929435dbaa7a9f6df10479cb))
* **scores:** POST /scores, personal bests only (Lot 2.3) ([56b4b0a](https://github.com/Arcadoolic/maui-api/commit/56b4b0a53dc853231210e875de4ba2850837f795))

## [0.2.0](https://github.com/Arcadoolic/maui-api/compare/0.1.0...0.2.0) (2026-10-01)

### Features

* authorize starting-pack repository access through the API ([0d01ac5](https://github.com/Arcadoolic/maui-api/commit/0d01ac59574a29c70899f0b42ec3f4a396312514))

### Bug Fixes

* let an empty MAUI_REPOSITORY_URL turn the repository off in compose ([1b4a768](https://github.com/Arcadoolic/maui-api/commit/1b4a76861fd3d537ebb52147f04bce8b67f60d33))

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
