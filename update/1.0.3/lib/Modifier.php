<?php
namespace Awz\Domains;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Modifier
{
    /**
     * Реестр модификаторов: name => [callable, hasParam]
     * Расширяется событием onAwzDomainsCollectModifiers.
     */
    public static function get(): array
    {
        static $cache = null;
        if (!is_null($cache)) {
            return $cache;
        }

        $cache = [
            'DEFAULT' => [static fn($v, $p) => self::isBlank($v) ? $p : $v, true],
            'DEF' => [static fn($v, $p) => self::isBlank($v) ? $p : $v, true],
            'UPPER' => [static fn($v) => mb_strtoupper($v), false],
            'LOWER' => [static fn($v) => mb_strtolower($v), false],
            'UCFIRST' => [static fn($v) => mb_strlen($v) > 1
                ? mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1) : mb_strtoupper($v), false],
            'LCFIRST' => [static fn($v) => mb_strlen($v) > 1
                ? mb_strtolower(mb_substr($v, 0, 1)) . mb_substr($v, 1) : mb_strtolower($v), false],
            'TRIM' => [static fn($v) => trim($v), false],
            'TRANSLIT' => [static fn($v) => self::translit($v), false],
            'PHONE' => [static fn($v) => self::phone($v), false],
            'TEL' => [static fn($v) => self::tel($v), false],
            'PLURAL' => [static fn($v, $p) => self::plural($v, $p), true],
            'TRUNCATE' => [static fn($v, $p) => self::truncate($v, $p), true],
            'DATE' => [static fn($v, $p) => self::dateFormat($v, $p), true],
            'NUMBER' => [static fn($v, $p) => self::number($v, $p), true],
            'REPLACE' => [static fn($v, $p) => self::replace($v, $p), true],
            'WRAP' => [static fn($v, $p) => self::wrap($v, $p), true],
            'PART' => [static fn($v, $p) => self::part($v, $p), true],
            'HTML' => [static fn($v) => htmlspecialcharsbx($v), false],
            'URLCODE' => [static fn($v) => rawurlencode($v), false],
            'URLENCODE' => [static fn($v) => rawurlencode($v), false],
            'SEP' => [static fn($v, $p) => $v, true],
        ];

        $event = new Event(Helper::MODULE_ID, 'onCollectModifiers');
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() == EventResult::SUCCESS && is_array($result->getParameters())) {
                foreach ($result->getParameters() as $name => $def) {
                    if (is_string($name) && $name !== '' && is_callable($def[0] ?? null)) {
                        $cache[strtoupper($name)] = [$def[0], (bool)($def[1] ?? false)];
                    }
                }
            }
        }

        return $cache;
    }

    /**
     * Применение цепочки модификаторов к значению
     * @param mixed $value значение (может быть массивом)
     * @param array $mods список модификаторов ['name', 'name:param']
     */
    public static function apply($value, array $mods)
    {
        if (is_array($value)) {
            $join = ', ';
            foreach ($mods as $mod) {
                $parsed = self::parse($mod);
                if ($parsed['name'] === 'sep' && $parsed['param'] !== '') {
                    $join = $parsed['param'];
                    break;
                }
            }
            $value = implode($join, $value);
        }
        $value = (string)$value;

        foreach ($mods as $mod) {
            $parsed = self::parse($mod);
            $name = strtoupper($parsed['name']);
            if ($parsed['name'] === 'sep') {
                continue;
            }
            $registry = self::get();
            if (!isset($registry[$name])) {
                continue;
            }
            $param = $parsed['param'];
            if (!$registry[$name][1]) {
                $param = '';
            }
            try {
                $value = (string)call_user_func($registry[$name][0], $value, $param);
            } catch (\Throwable $e) {
                // при ошибке модификатора значение не меняется
            }
        }
        return $value;
    }

    private static function parse(string $mod): array
    {
        $pos = mb_strpos($mod, ':');
        if ($pos === false) {
            return ['name' => trim($mod), 'param' => ''];
        }
        return [
            'name' => trim(mb_substr($mod, 0, $pos)),
            'param' => mb_substr($mod, $pos + 1),
        ];
    }

    private static function isBlank($v): bool
    {
        return is_array($v) ? (count($v) === 0) : (trim((string)$v) === '');
    }

    private static function translit(string $v): string
    {
        $trans = \CUtil::translit($v, 'ru', [
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
            'delete_repeat_replace' => true,
        ]);
        return (string)$trans;
    }

    private static function phone(string $v): string
    {
        $d = preg_replace('~\D~', '', $v);
        if ($d === '') {
            return $v;
        }
        if (mb_strlen($d) === 11 && ($d[0] === '8' || $d[0] === '7')) {
            $d = '7' . mb_substr($d, 1);
            return '+' . $d[0] . ' (' . mb_substr($d, 1, 3) . ') ' . mb_substr($d, 4, 3)
                . '-' . mb_substr($d, 7, 2) . '-' . mb_substr($d, 9, 2);
        }
        return '+' . $d;
    }

    private static function tel(string $v): string
    {
        $d = preg_replace('~\D~', '', $v);
        if ($d === '') {
            return '';
        }
        if (mb_strlen($d) === 11 && $d[0] === '8') {
            $d = '7' . mb_substr($d, 1);
        }
        return '+' . $d;
    }

    private static function plural(string $v, string $param): string
    {
        $forms = explode('|', $param);
        $n = (int)preg_replace('~\D~', '', $v);
        if (count($forms) < 2) {
            return $v;
        }
        if (count($forms) === 2) {
            return $n % 10 === 1 && $n % 100 !== 11 ? $forms[0] : $forms[1];
        }
        if ($n % 10 === 1 && $n % 100 !== 11) {
            return $forms[0];
        }
        if ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) {
            return $forms[1];
        }
        return $forms[2] ?? $forms[1];
    }

    private static function truncate(string $v, string $param): string
    {
        $len = (int)$param;
        if ($len <= 0 || mb_strlen($v) <= $len) {
            return $v;
        }
        return rtrim(mb_substr($v, 0, $len)) . '…';
    }

    private static function dateFormat(string $v, string $param): string
    {
        $ts = strtotime($v);
        if ($ts === false) {
            return $v;
        }
        return date($param !== '' ? $param : 'd.m.Y', $ts);
    }

    private static function number(string $v, string $param): string
    {
        $dec = (int)$param;
        return number_format((float)str_replace([' ', ','], ['.', '.'], $v), $dec, ',', ' ');
    }

    private static function replace(string $v, string $param): string
    {
        $sepPos = mb_strpos($param, '|');
        if ($sepPos === false) {
            return str_replace($param, '', $v);
        }
        $from = mb_substr($param, 0, $sepPos);
        $to = mb_substr($param, $sepPos + 1);
        return str_replace($from, $to, $v);
    }

    private static function wrap(string $v, string $param): string
    {
        if (trim($v) === '') {
            return $v;
        }
        $sepPos = mb_strpos($param, '|');
        if ($sepPos === false) {
            return $param . $v;
        }
        return mb_substr($param, 0, $sepPos) . $v . mb_substr($param, $sepPos + 1);
    }

    private static function part(string $v, string $param): string
    {
        $sepPos = mb_strpos($param, '|');
        $sep = $sepPos === false ? ',' : mb_substr($param, $sepPos + 1);
        $idx = (int)($sepPos === false ? $param : mb_substr($param, 0, $sepPos));
        $parts = explode($sep, $v);
        return isset($parts[$idx]) ? trim($parts[$idx]) : '';
    }
}
