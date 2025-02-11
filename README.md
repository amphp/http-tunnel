# http-tunnel

![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

This package provides an HTTP and HTTPS `CONNECT` tunnel for PHP based on [Amp](https://github.com/amphp/amp).

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/http-tunnel
```

## Examples

More extensive code examples reside in the [`examples`](./examples) directory.

```php
use Amp\Http\Tunnel\Http1TunnelConnector;

$connector = new Http1TunnelConnector('127.0.0.1:5512');

// $connector may now be used anywhere requiring an instance of Amp\Socket\SocketConnector.
```

## Versioning

`amphp/http-tunnel` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
