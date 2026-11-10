<?php
// app/Models/ClinicCache.php — ключи кеша ветеринарной клиники и их инвалидация в одном месте.
//
// Кешируем (cache-aside, App\Core\Cache::remember):
//   doctors:all                         — справочник врачей, 300 с
//   animals:all                         — справочник животных с именами владельцев, 300 с
//   appointments:item:r<R>:<id>         — карточка приёма, 60 с
//   appointments:list:v<V>:r<R>:<md5>   — список приёмов: ключ из всех параметров запроса, 60 с
//   report:doctors                      — отчёт «загрузка и выручка врачей», 120 с
//
// Два номера версии (Cache::version / Cache::bump), без времени жизни:
//   appointments:version (V) — растёт при любом изменении приёмов: все списки разом устаревают;
//   refs:version (R)         — растёт при изменении врача, животного или владельца: их имена показываются
//                              в карточках и списках приёмов, поэтому устаревают и карточки, и списки.
// Старые ключи никто не удаляет вручную: их удалит Redis по истечении времени жизни.

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;

final class ClinicCache
{
    public const TTL_DICTIONARY = 300;                     // справочники врачей и животных
    public const TTL_ITEM = 60;                            // карточка приёма
    public const TTL_LIST = 60;                            // список приёмов
    public const TTL_REPORT = 120;                         // отчёт по врачам

    public static function itemKey(int $id): string
    {
        return 'appointments:item:r' . Cache::version('refs:version') . ':' . $id;
    }

    public static function listKey(array $params): string
    {
        return 'appointments:list:v' . Cache::version('appointments:version') . ':r' . Cache::version('refs:version')
            . ':' . md5(json_encode($params));
    }

    // Изменился приём: устарели все списки, карточка этого приёма и отчёт по врачам
    public static function appointmentsChanged(?int $id = null): void
    {
        Cache::bump('appointments:version');
        if ($id !== null) {
            Cache::forget(self::itemKey($id));
        }
        Cache::forget('report:doctors');
    }

    // Изменились врачи: справочник, отчёт (в нём имена) и имена врачей в карточках и списках приёмов
    public static function doctorsChanged(): void
    {
        Cache::forget('doctors:all', 'report:doctors');
        Cache::bump('refs:version');
    }

    // Изменились животные или владельцы: справочник животных (в нём клички и имена владельцев)
    // и имена в карточках и списках приёмов
    public static function animalsChanged(): void
    {
        Cache::forget('animals:all');
        Cache::bump('refs:version');
    }
}
