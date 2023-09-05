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

/** @api */
final class Https1TunnelConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    private string $proxyAddress;
    private ClientTlsContext $proxyTlsContext;
    private array $customHeaders;
    private ?SocketConnector $socketConnector;

    public function __construct(string $proxyAddress, ClientTlsContext $proxyTls, array $customHeaders = [], ?SocketConnector $socketConnector = null)
    {
        $this->proxyAddress = $proxyAddress;
        $this->proxyTlsContext = $proxyTls;
        $this->customHeaders = $customHeaders;
        $this->socketConnector = $socketConnector;
    }

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket
    {
        $socketConnector = $this->socketConnector ?? socketConnector();
        $context ??= new ConnectContext;

        $start = now();

        $remoteSocket = $socketConnector->connect($this->proxyAddress, $context->withTlsContext($this->proxyTlsContext), $cancellation);

        $tlsStart = now();

        $remoteSocket->setupTls($cancellation);

        $end = now();

        $remoteSocket = Http1TunnelConnector::tunnel($remoteSocket, $end - $start, $end - $tlsStart, (string) $uri, $this->customHeaders, $cancellation ?? new NullCancellation());

        [$serverSocket, $clientSocket] = $this->createPair((new ConnectContext)->withTlsContext($context->getTlsContext()));

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

    /** @return list{Socket, Socket} */
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
