<?php
namespace Awz\Domains;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;


Loc::loadMessages(__FILE__);

class Macros
{
    const MACROS_PREFIX = 'AWZ_S_';

    /**
     * Полный список значений для макросов сайта:
     * данные города + доп. макросы из настроек + макросы разработчиков.
     * Значения разработчиков приоритетнее системных.
     */
    public static function getValues(string $siteId = ''): array
    {
        $values = City::getInstance()->getValues();
        $values['CODE'] = City::getInstance()->getCode();

        $settings = Helper::getSettings($siteId);
        $values = array_merge($values, Helper::getExtraMacros($settings));

        // макросы разработчиков - приоритетнее системных
        $event = new Event(Helper::MODULE_ID, 'onCollectMacros', [
            'siteId' => $siteId ?: Helper::getSiteId(),
            'city' => City::getInstance()->getCity(),
            'values' => $values,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() == EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (is_array($params)) {
                    foreach ($params as $name => $val) {
                        if (is_string($name) && $name !== '' && is_scalar($val)) {
                            $values[strtoupper($name)] = (string)$val;
                        }
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Замена всех макросов #AWZ_S_XXX~mod1~mod2:param# в строке
     */
    public static function replace(string $str, string $siteId = ''): string
    {
        if (mb_strpos($str, '#AWZ_S_') === false) {
            return $str;
        }
        $values = self::getValues($siteId);
        $values = self::normalizeKeys($values);

        return preg_replace_callback(
            '~#AWZ_S_([A-Za-z0-9_]+)((?:~[^#~]*)*)#~u',
            static function ($m) use ($values) {
                $key = strtoupper($m[1]);
                $mods = [];
                if (isset($m[2]) && $m[2] !== '') {
                    $mods = array_values(array_filter(explode('~', mb_substr($m[2], 1)), 'trim'));
                }
                if (array_key_exists($key, $values)) {
                    return Modifier::apply($values[$key], $mods);
                }
                // неизвестный макрос: работает только модификатор default
                foreach ($mods as $mod) {
                    $name = mb_strtolower(trim(explode(':', $mod)[0]));
                    if ($name === 'default' || $name === 'def') {
                        return Modifier::apply('', $mods);
                    }
                }
                return '';
            },
            $str
        );
    }

    private static function normalizeKeys(array $values): array
    {
        $res = [];
        foreach ($values as $k => $v) {
            $k = strtoupper((string)$k);
            if (mb_strpos($k, self::MACROS_PREFIX) === 0) {
                $k = mb_substr($k, mb_strlen(self::MACROS_PREFIX));
            }
            if ($k === '' || !is_scalar($v)) {
                continue;
            }
            $res[$k] = (string)$v;
        }
        return $res;
    }
}
