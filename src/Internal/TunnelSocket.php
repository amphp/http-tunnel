<?php declare(strict_types=1);

namespace Amp\Http\Tunnel\Internal;

use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Http\Client\Connection\Http1Connection;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\Request;
use Amp\Http\HttpMessage;
use Amp\Http\HttpStatus;
use Amp\Socket\ConnectException;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;
use function Amp\Http\Client\processRequest;

/**
 * @internal
 *
 * @implements \IteratorAggregate<int, string>
 *
 * @psalm-import-type HeaderParamArrayType from HttpMessage
 */
final class TunnelSocket implements Socket, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    /**
     * @internal
     *
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

        $upgradedSocket = null;
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
        private readonly Socket $localSocket,
        private readonly Socket $remoteSocket,
    ) {
    }

    public function setupTls(?Cancellation $cancellation = null): void
    {
        $this->localSocket->setupTls($cancellation);
    }

    public function shutdownTls(?Cancellation $cancellation = null): void
    {
        $this->localSocket->shutdownTls($cancellation);
    }

    public function getTlsState(): TlsState
    {
        return $this->localSocket->getTlsState();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->localSocket->getTlsInfo();
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
    {
        return $this->localSocket->read($cancellation);
    }

    public function write(string $bytes): void
    {
        $this->localSocket->write($bytes);
    }

    public function end(): void
    {
        $this->localSocket->end();
    }

    public function reference(): void
    {
        \assert($this->localSocket instanceof ResourceStream);
        \assert($this->remoteSocket instanceof ResourceStream);
        $this->localSocket->reference();
        $this->remoteSocket->reference();
    }

    public function unreference(): void
    {
        \assert($this->localSocket instanceof ResourceStream);
        \assert($this->remoteSocket instanceof ResourceStream);
        $this->localSocket->unreference();
        $this->remoteSocket->unreference();
    }

    public function close(): void
    {
        // Don't close remote socket here, as there might still be pending data in flight there
        $this->localSocket->close();
    }

    public function isClosed(): bool
    {
        return $this->localSocket->isClosed();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->localSocket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteSocket->getRemoteAddress();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->localSocket->onClose($onClose);
    }

    public function isReadable(): bool
    {
        return $this->localSocket->isReadable();
    }

    public function isTlsConfigurationAvailable(): bool
    {
        return $this->localSocket->isTlsConfigurationAvailable();
    }

    public function isWritable(): bool
    {
        return $this->localSocket->isWritable();
    }
}
