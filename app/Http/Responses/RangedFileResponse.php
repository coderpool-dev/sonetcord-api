<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Отдача файла с поддержкой Range: перемотка в плеере и докачка. */
final class RangedFileResponse
{
    // Отдаём файл блоками по 1 МБ, чтобы не держать большой файл в памяти целиком.
    private const STREAM_BUFFER = 1024 * 1024;

    /** Файл с диска, читается потоком. */
    public static function fromFile(Request $request, string $absolutePath, string $mime, array $headers = []): StreamedResponse
    {
        $total = (int) filesize($absolutePath);
        [$start, $end, $status] = self::resolveRange($request, $total);
        $length = $end - $start + 1;

        return response()->stream(function () use ($absolutePath, $start, $length): void {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }

            fseek($handle, $start);
            $remaining = $length;

            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, (int) min(self::STREAM_BUFFER, $remaining));
                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                flush();
                $remaining -= strlen($chunk);
            }

            fclose($handle);
        }, $status, self::headers($mime, $length, $headers, $status, $start, $end, $total));
    }

    /**
     * Файл отдаёт nginx (X-Accel-Redirect на внутренний location), Range он разбирает сам.
     * Иначе каждая загрузка большого файла держала воркер php-fpm до конца скачивания.
     */
    public static function viaNginx(string $prefix, string $diskPath, string $mime, array $headers = []): Response
    {
        $uri = rtrim($prefix, '/').'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($diskPath, '/'))));

        return new Response('', 200, [
            'Content-Type' => $mime,
            'X-Accel-Redirect' => $uri,
            ...$headers,
        ]);
    }

    /** Содержимое, уже загруженное в память (например, расшифрованный файл). */
    public static function fromContents(Request $request, string $contents, string $mime, array $headers = []): StreamedResponse
    {
        $total = strlen($contents);
        [$start, $end, $status] = self::resolveRange($request, $total);
        $slice = substr($contents, $start, $end - $start + 1);

        return response()->stream(function () use ($slice): void {
            echo $slice;
        }, $status, self::headers($mime, strlen($slice), $headers, $status, $start, $end, $total));
    }

    /**
     * Разбирает заголовок Range.
     *
     * @return array{0: int, 1: int, 2: int} [начало, конец, HTTP-статус]
     */
    private static function resolveRange(Request $request, int $total): array
    {
        if (! $request->headers->has('Range') || $total === 0) {
            return [0, max(0, $total - 1), 200];
        }

        $range = (string) $request->headers->get('Range');

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            abort(416);
        }

        // bytes=-500 — последние 500 байт.
        $start = $matches[1] === ''
            ? $total - min((int) $matches[2], $total)
            : (int) $matches[1];

        $end = $matches[2] === '' ? $total - 1 : min((int) $matches[2], $total - 1);

        if ($start < 0 || $start >= $total || $end < $start) {
            abort(416);
        }

        return [$start, $end, 206];
    }

    private static function headers(string $mime, int $length, array $extra, int $status, int $start, int $end, int $total): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            ...$extra,
            ...($status === 206 ? ['Content-Range' => "bytes {$start}-{$end}/{$total}"] : []),
        ];
    }
}
