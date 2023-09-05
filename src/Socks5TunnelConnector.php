<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use AssertionError;
use League\Uri\Uri;
use RuntimeException;

use function Amp\Socket\socketConnector;

/** @api */
final class Socks5TunnelConnector implements SocketConnector
{
    private const REPS = [0 => 'succeeded', 1 => 'general SOCKS server failure', 2 => 'connection not allowed by ruleset', 3 => 'Network unreachable', 4 => 'Host unreachable', 5 => 'Connection refused', 6 => 'TTL expired', 7 => 'Command not supported', 8 => 'Address type not supported'];

    use ForbidCloning;
    use ForbidSerialization;

    public static function tunnel(
        Socket $socket,
        string $target,
        ?string $username,
        ?string $password,
        ?Cancellation $cancellation
    ): Socket {
        if (($username === null) !== ($password === null)) {
            throw new AssertionError("Both or neither username and password must be provided!");
        }
        $uri = Uri::createFromString($target);
        $buffer = '';
        $read = function (int $length) use ($socket, $cancellation, &$buffer): string {
            \assert($length > 0);
            do {
                $res = $socket->read($cancellation, $length - strlen($buffer));
                if ($res === null) {
                    throw new AssertionError("The socket was closed!");
                }
                $buffer .= $res;
            } while (strlen($buffer) !== $length);
            $res = $buffer;
            $buffer = '';
            return $res;
        };

        $methods = \chr(0);
        if (isset($username) && isset($password)) {
            $methods .= \chr(2);
        }
        $socket->write(\chr(5).\chr(\strlen($methods)).$methods);
        $version = \ord($read(1));
        if ($version !== 5) {
            throw new RuntimeException("Wrong SOCKS5 version: {$version}");
        }
        $method = \ord($read(1));
        if ($method === 2) {
            \assert($username !== null && $password !== null);
            $socket->write(
                \chr(1).
                \chr(\strlen($username)).
                $username.
                \chr(\strlen($password)).
                $password
            );
            $version = \ord($read(1));
            if ($version !== 1) {
                throw new RuntimeException("Wrong authorized SOCKS version: {$version}");
            }
            $result = \ord($read(1));
            if ($result !== 0) {
                throw new RuntimeException("Wrong authorization status: {$result}");
            }
        } elseif ($method !== 0) {
            throw new RuntimeException("Wrong method: {$method}");
        }
        $host = $uri->getHost();
        if ($host === null) {
            throw new AssertionError("Host is null!");
        }
        $payload = \pack('C3', 0x5, 0x1, 0x0);
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = \inet_pton($host);
            $payload .= \chr(\strlen($ip) === 4 ? 0x1 : 0x4).$ip;
        } else {
            $payload .= \chr(0x3).\chr(\strlen($host)).$host;
        }
        $payload .= \pack('n', $uri->getPort());
        $socket->write($payload);
        $version = \ord($read(1));
        if ($version !== 5) {
            throw new RuntimeException("Wrong SOCKS5 version: {$version}");
        }
        $rep = \ord($read(1));
        if ($rep !== 0) {
            $rep = self::REPS[$rep] ?? $rep;
            throw new RuntimeException("Wrong SOCKS5 rep: {$rep}");
        }
        $rsv = \ord($read(1));
        if ($rsv !== 0) {
            throw new RuntimeException("Wrong socks5 final RSV: {$rsv}");
        }

        $read(match (\ord($read(1))) {
            0x1 => 6,
            0x4 => 18,
            0x3 => \ord($read(1))+2
        });

        return $socket;
    }

    public function __construct(
        private readonly string $proxyAddress,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly ?SocketConnector $socketConnector = null
    ) {
        if (($username === null) !== ($password === null)) {
            throw new AssertionError("Both or neither username and password must be provided!");
        }
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $connector = $this->socketConnector ?? socketConnector();

        $socket = $connector->connect($this->proxyAddress, $context, $cancellation);

        return self::tunnel($socket, (string) $uri, $this->username, $this->password, $cancellation);
    }
}
