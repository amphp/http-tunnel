<?php declare(strict_types=1);

use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\Http\Http1\Rfc7230;
use Amp\Http\Tunnel\Http1TunnelConnector;
use Amp\Http\Tunnel\Https1TunnelConnector;
use Amp\Socket\ClientTlsContext;

require __DIR__ . '/../vendor/autoload.php';

try {
    $useHttps = (bool) ($argv[1] ?? false);
    $peerName = $argv[1] ?? '';

    // If you need authentication, you can set a custom header (using Basic auth here)
    // $connector = new Http1TunnelConnector(new SocketAddress('127.0.0.1', 5512), [
    //     'proxy-authorization' => 'Basic ' . \base64_encode('user:pass'),
    // ]);

    // If you have a proxy accepting HTTPS connections, the Https1TunnelConnector must be used, providing the
    // peer name to the instance of ClientTlsContext.
    $socketConnector = $useHttps
        ? new Https1TunnelConnector('127.0.0.1:5512', new ClientTlsContext($peerName))
        : new Http1TunnelConnector('127.0.0.1:5512');

    $client = (new HttpClientBuilder)
        ->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory($socketConnector)))
        ->build();

    $request = new Request('http://amphp.org/');

    $response = $client->request($request);

    $request = $response->getRequest();

    printf(
        "%s %s HTTP/%s\r\n",
        $request->getMethod(),
        (string) $request->getUri(),
        implode('+', $request->getProtocolVersions())
    );

    print Rfc7230::formatHeaders($request->getHeaders()) . "\r\n\r\n";

    printf(
        "HTTP/%s %d %s\r\n",
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason()
    );

    print Rfc7230::formatHeaders($response->getHeaders()) . "\r\n\r\n";

    $body = $response->getBody()->buffer();
    $bodyLength = strlen($body);

    if ($bodyLength < 250) {
        print $body . "\r\n";
    } else {
        print substr($body, 0, 250) . "\r\n\r\n";
        print($bodyLength - 250) . " more bytes\r\n";
    }
} catch (HttpException $error) {
    // If something goes wrong Amp will throw the exception where the promise was yielded.
    // The HttpClient::request() method itself will never throw directly, but returns a promise.
    echo $error;
}
