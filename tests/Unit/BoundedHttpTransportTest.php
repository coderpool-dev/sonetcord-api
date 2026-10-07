<?php

namespace Tests\Unit;

use App\Services\Http\BoundedResponseBody;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Throwable;

class BoundedHttpTransportTest extends TestCase
{
    public function test_curl_aborts_an_unknown_sized_response_before_buffering_it(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, dirname(__DIR__).'/Fixtures/large-http-response.php']);
        $server->start();
        $body = BoundedResponseBody::create(16_384);
        try {
            $deadline = microtime(true) + 5;
            do {
                $connection = @stream_socket_client('tcp://'.$address, timeout: 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertNotFalse($connection, $server->getErrorOutput());
            $aborted = false;
            try {
                (new Client(['handler' => new CurlHandler]))->get('http://'.$address, [
                    'sink' => $body, 'timeout' => 3, 'proxy' => '', 'decode_content' => false,
                ]);
            } catch (Throwable) {
                $aborted = true;
            }
            $this->assertTrue($aborted, 'Transport accepted an oversized response');
            $this->assertLessThanOrEqual(16_384, $body->getSize());
            $this->assertGreaterThan(0, $body->getSize());
        } finally {
            $server->stop(1);
        }
    }
}
