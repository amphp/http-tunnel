<?php

namespace Amp\Http\Tunnel;

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\InternetAddress;
use LeProxy\LeProxy\LeProxyServer;
use React\EventLoop\Loop;

class Http1TunnelConnectorTest extends AsyncTestCase
{
    public function test(): void
    {
        $proxy = new LeProxyServer(Loop::get());
        $socket = $proxy->listen('127.0.0.1:0', false);

        $socketConnector = new Http1TunnelConnector(InternetAddress::fromString(\str_replace('tcp://', '', $socket->getAddress())));

        $client = (new HttpClientBuilder)
            ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($socketConnector)))
            ->build();

        $request = new Request('https://httpbin.org/headers');
        $request->setHeader('connection', 'close');

        $response = $client->request($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertJson($response->getBody()->buffer());

        $socket->close();
    }
}
