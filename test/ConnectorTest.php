<?php declare(strict_types=1);

namespace Amp\Http\Tunnel;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketConnector;
use LeProxy\LeProxy\LeProxyServer;
use React\EventLoop\Loop;

class ConnectorTest extends AsyncTestCase
{
    /**
     * @dataProvider provideProxy
     *
     * @param class-string<SocketConnector> $class
     */
    public function test(string $class): void
    {
        $proxy = new LeProxyServer(Loop::get());
        $socket = $proxy->listen('127.0.0.1:0', false);

        $socketConnector = new $class((string) InternetAddress::fromString(\str_replace('tcp://', '', $socket->getAddress())));

        $client = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($socketConnector)))
            ->build();

        $request = new Request('https://example.com/');
        $request->setHeader('connection', 'close');

        $response = $client->request($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('Example Domain', $response->getBody()->buffer());

        $socket->close();
    }

    public static function provideProxy(): array
    {
        return [
            [Http1TunnelConnector::class],
            [Socks5TunnelConnector::class],
        ];
    }
}
