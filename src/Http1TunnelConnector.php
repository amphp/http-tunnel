<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Connection\Http1Connection;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use function Amp\Socket\socketConnector;

/** @api */
final class Http1TunnelConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public static function tunnel(
        Socket $socket,
        string $target,
        array $customHeaders,
        Cancellation $cancellation
    ): Socket {
        $request = new Request('http://' . \str_replace('tcp://', '', $target), 'CONNECT');
        $request->setHeaders($customHeaders);

        $request->setUpgradeHandler(static function (Socket $socket) use (&$upgradedSocket) {
            $upgradedSocket = $socket;
        });

        $connection = new Http1Connection($socket, 1000);

        /** @var Stream $stream */
        $stream = $connection->getStream($request);
        $response = $stream->request($request, $cancellation);

        if ($response->getStatus() !== 200) {
            throw new ConnectException('Failed to connect to proxy: Received a bad status code (' . $response->getStatus() . ')');
        }

        \assert($upgradedSocket !== null);

        return $upgradedSocket;
    }

    public function __construct(
        private string $proxyAddress,
        private array $customHeaders = [],
        private ?SocketConnector $socketConnector = null
    ) {
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $connector = $this->socketConnector ?? socketConnector();

        $socket = $connector->connect($this->proxyAddress, $context, $cancellation);

        return self::tunnel($socket, (string) $uri, $this->customHeaders, $cancellation ?? new NullCancellation());
    }
}
