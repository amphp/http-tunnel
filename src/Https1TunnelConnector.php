<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Tunnel\Internal\TunnelSocket;
use Amp\NullCancellation;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\Future\awaitAll;
use function Amp\now;
use function Amp\Socket\connect;
use function Amp\Socket\listen;
use function Amp\Socket\socketConnector;

final class Https1TunnelConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly string $proxyAddress,
        private readonly ClientTlsContext $proxyTlsContext,
        private readonly array $customHeaders = [],
        private readonly ?SocketConnector $socketConnector = null,
    ) {
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        $socketConnector = $this->socketConnector ?? socketConnector();
        $context ??= new ConnectContext();

        $start = now();

        $remoteSocket = $socketConnector->connect(
            $this->proxyAddress,
            $context->withTlsContext($this->proxyTlsContext),
            $cancellation,
        );

        $tlsStart = now();

        $remoteSocket->setupTls($cancellation);

        $end = now();

        $remoteSocket = Http1TunnelConnector::tunnel(
            socket: $remoteSocket,
            connectDuration: $end - $start,
            tlsHandshakeDuration: $end - $tlsStart,
            target: (string) $uri,
            customHeaders: $this->customHeaders,
            cancellation: $cancellation ?? new NullCancellation(),
        );

        [
            $serverSocket,
            $clientSocket,
        ] = $this->createPair((new ConnectContext())->withTlsContext($context->getTlsContext()));

        async(static function () use ($serverSocket, $remoteSocket) {
            try {
                $futures = [
                    async(fn () => pipe($serverSocket, $remoteSocket)),
                    async(fn () => pipe($remoteSocket, $serverSocket)),
                ];

                awaitAll($futures);
            } catch (\Throwable) {
                // ignore
            } finally {
                $serverSocket->close();
                $remoteSocket->close();
            }
        });

        return new TunnelSocket($clientSocket, $remoteSocket);
    }

    /**
     * @return array{Socket, Socket}
     */
    private function createPair(ConnectContext $connectContext): array
    {
        do {
            $server = listen('127.0.0.1:0');
            $clientSocketFuture = async(fn () => connect($server->getAddress(), $connectContext));

            try {
                $serverSocket = $server->accept();
                $clientSocket = $clientSocketFuture->await();
            } finally {
                $server->close();
            }
        } while (!$serverSocket || (string) $serverSocket->getRemoteAddress() !== (string) $clientSocket->getLocalAddress());

        return [$serverSocket, $clientSocket];
    }
}
