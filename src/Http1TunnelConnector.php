<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Connection\Http1Connection;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Request;
use Amp\Http\HttpMessage;
use Amp\Http\HttpStatus;
use Amp\NullCancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use function Amp\Http\Client\processRequest;
use function Amp\now;
use function Amp\Socket\socketConnector;

/**
 * @psalm-import-type HeaderParamArrayType from HttpMessage
 */
final class Http1TunnelConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * @param HeaderParamArrayType $customHeaders
     */
    public static function tunnel(
        Socket $socket,
        float $connectDuration,
        ?float $tlsHandshakeDuration,
        string $target,
        array $customHeaders,
        Cancellation $cancellation,
    ): Socket {
        $request = new Request('http://' . \str_replace('tcp://', '', $target), 'CONNECT');
        $request->setHeaders($customHeaders);

        $request->setUpgradeHandler(static function (Socket $socket) use (&$upgradedSocket): void {
            $upgradedSocket = $socket;
        });

        $connection = new Http1Connection($socket, $connectDuration, $tlsHandshakeDuration, 1);

        $response = processRequest($request, [], function (Request $request) use ($connection, $cancellation) {
            /** @var Stream $stream */
            $stream = $connection->getStream($request);

            return $stream->request($request, $cancellation);
        });

        if ($response->getStatus() !== HttpStatus::OK) {
            throw new ConnectException('Failed to connect to proxy: Received a bad status code (' . $response->getStatus() . ')');
        }

        \assert($upgradedSocket !== null);

        return $upgradedSocket;
    }

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

        return self::tunnel(
            socket: $socket,
            connectDuration: now() - $start,
            tlsHandshakeDuration: null,
            target: (string) $uri,
            customHeaders: $this->customHeaders,
            cancellation: $cancellation ?? new NullCancellation(),
        );
    }
}
