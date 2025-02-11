<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Tunnel\Internal\TunnelSocket;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use function Amp\now;
use function Amp\Socket\socketConnector;

final class Http1TunnelConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly string $proxyAddress,
        private readonly array $customHeaders = [],
        private readonly ?SocketConnector $socketConnector = null,
    ) {
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null,
    ): Socket {
        $connector = $this->socketConnector ?? socketConnector();

        $start = now();

        $socket = $connector->connect($this->proxyAddress, $context, $cancellation);

        return TunnelSocket::tunnel(
            socket: $socket,
            connectDuration: now() - $start,
            tlsHandshakeDuration: null,
            target: (string) $uri,
            customHeaders: $this->customHeaders,
            cancellation: $cancellation ?? new NullCancellation(),
        );
    }
}
