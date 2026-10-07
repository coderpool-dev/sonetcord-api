<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

/**
 * Сборка веб-клиента (время сборки фронта), с которой начинают или принимают звонок.
 *
 * Вкладка, открытая до обновления сайта, может звонить по-старому: 2026-10-04 вкладка от сборки
 * без LiveKit «позвонила» в группу мимо комнаты, и её никто бы не услышал. Такие сборки не
 * присылают client_build. CLIENT_MIN_BUILD отсекает и более новые, если сломалась совместимость.
 */
final class ClientBuild
{
    public static function assertSupported(Request $request): void
    {
        if (! config('services.client_build.required')) {
            return;
        }

        $build = $request->input('client_build');
        $minimum = config('services.client_build.min');
        $outdated = ! is_string($build) || $build === ''
            || (is_string($minimum) && $minimum !== '' && strcmp($build, $minimum) < 0);

        if ($outdated) {
            throw new ApiException(
                'Открыта устаревшая версия SonetCord — обновите страницу (Ctrl+F5) или перезапустите приложение, чтобы звонить',
                426,
                ['code' => 'CLIENT_OUTDATED'],
            );
        }
    }
}
