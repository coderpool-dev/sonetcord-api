<?php

namespace App\Services\Http;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class BoundedResponseBody
{
    public static function create(int $limit): StreamInterface
    {
        $stream = Utils::streamFor();
        $bytes = 0;

        return FnStream::decorate($stream, [
            'write' => function (string $data) use ($stream, &$bytes, $limit): int {
                if ($bytes + strlen($data) > $limit) {
                    throw new RuntimeException('HTTP response too large');
                }
                $written = $stream->write($data);
                $bytes += $written;

                return $written;
            },
        ]);
    }
}
