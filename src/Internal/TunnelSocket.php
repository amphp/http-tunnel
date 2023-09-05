<?php declare(strict_types=1);

namespace Amp\Http\Tunnel\Internal;

use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;

/** @internal */
final class TunnelSocket implements Socket
{
    use ForbidCloning;
    use ForbidSerialization;

    private Socket $localSocket;
    private Socket $remoteSocket;

    public function __construct(Socket $local, Socket $remote)
    {
        $this->localSocket = $local;
        $this->remoteSocket = $remote;
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

    /** @api */
    public function reference(): void
    {
        \assert($this->localSocket instanceof ResourceStream);
        \assert($this->remoteSocket instanceof ResourceStream);
        $this->localSocket->reference();
        $this->remoteSocket->reference();
    }

    /** @api */
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
