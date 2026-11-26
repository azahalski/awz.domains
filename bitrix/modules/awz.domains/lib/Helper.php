<?php
namespace Awz\Domains;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Helper
{
    const MODULE_ID = 'awz.domains';

    const OPT_ENABLED = 'ENABLED';
    const OPT_MODE = 'MODE';
    const OPT_MAIN_HOST = 'MAIN_HOST';
    const OPT_IBLOCK_ID = 'IBLOCK_ID';
    const OPT_DEFAULT_CODE = 'DEFAULT_CODE';
    const OPT_REDIRECT_UNK = 'REDIRECT_UNK';
    const OPT_REDIRECT_STATUS = 'REDIRECT_STATUS';
    const OPT_PROPS = 'PROPS';
    const OPT_EXTRA = 'EXTRA';
    const OPT_REPLACE_TITLE = 'REPLACE_TITLE';
    const OPT_REPLACE_PAGE_PROPS = 'REPLACE_PAGE_PROPS';
    const OPT_REPLACE_CONTENT = 'REPLACE_CONTENT';
    const OPT_URL_REWRITE_REGEXPS = 'URL_REWRITE_REGEXPS';
    const OPT_CACHE_TIME = 'CACHE_TIME';

    /**
     * Текущий сайт (публичная часть) или s1
     */
    public static function getSiteId(): string
    {
        $siteId = '';
        $context = Application::getInstance()->getContext();
        if ($context) {
        try {
            $site = $context->getSite();
            if ($site) {
                if (is_object($site) && method_exists($site, 'getLid')) {
                    $siteId = (string)$site->getLid();
                } else {
                    $siteId = (string)$site;
                }
            }
        } catch (\Throwable $e) {
            $siteId = '';
        }
        }
        if (!$siteId && defined('SITE_ID')) {
            $siteId = SITE_ID;
        }
        if (!$siteId) {
            $siteId = 's1';
        }
        return $siteId;
    }

    /**
     * Записывает конфиг городов для ранней подмены URL из dbconn.php
     * (include/rewrite.php не использует ядро и читает только этот файл)
     */
    public static function writeUrlIncludeConfig(): void
    {
        $cities = [];
        $default = '';
        if (Loader::includeModule('iblock')) {
            foreach (self::getAllSites() as $siteId) {
                $settings = self::getSettings($siteId);
                if (empty($settings['ENABLED']) || $settings['IBLOCK_ID'] <= 0) {
                    continue;
                }
                if ($default === '' && $settings['DEFAULT_CODE'] !== '') {
                    $default = $settings['DEFAULT_CODE'];
                }
                $dbRes = \CIBlockElement::GetList(
                    [],
                    [
                        'IBLOCK_ID' => $settings['IBLOCK_ID'],
                        'ACTIVE' => 'Y',
                        'CHECK_PERMISSIONS' => 'N',
                    ],
                    false,
                    false,
                    ['CODE']
                );
                while ($row = $dbRes->Fetch()) {
                    $code = strtolower(trim((string)$row['CODE']));
                    if ($code !== '' && !in_array($code, $cities, true)) {
                        $cities[] = $code;
                    }
                }
            }
        }
        $config = ['cities' => $cities, 'default' => $default];
        $root = defined('DOCUMENT_ROOT') ? DOCUMENT_ROOT
            : (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '');
        if ($root === '') {
            $root = dirname(__DIR__, 3);
        }
        $file = $root . '/bitrix/php_interface/dbcon_awz_d_cities_config.php';
        \Bitrix\Main\IO\File::putFileContents(
            $file,
            "<?php\nreturn " . var_export($config, true) . ";\n"
        );
    }

    /**
     * Обновление dbcon-конфига, если инфоблок element относится к городам.
     * @return bool true - конфиг обновлен (инфоблок городов)
     */
    public static function refreshUrlIncludeConfig(int $iblockId): bool
    {
        $iblockId = (int)$iblockId;
        if ($iblockId <= 0) {
            return false;
        }
        $ids = [];
        foreach (self::getAllSites() as $siteId) {
            $settings = self::getSettings($siteId);
            if ($settings['IBLOCK_ID'] > 0) {
                $ids[] = $settings['IBLOCK_ID'];
            }
        }
        if (!in_array($iblockId, array_unique($ids), true)) {
            return false;
        }
        self::writeUrlIncludeConfig();
        return true;
    }

    private static function getAllSites(): array
    {
        $ids = [];
        $dbSites = \Bitrix\Main\SiteTable::getList(['select' => ['LID']]);
        while ($site = $dbSites->fetch()) {
            $ids[] = $site['LID'];
        }
        return $ids ?: ['s1'];
    }

    /**
     * Значение опции именно для сайта (без наследования), '' - не задано для сайта
     */
    public static function getRaw(string $code, string $siteId): string
    {
        if ($siteId === '') {
            return (string)Option::get(self::MODULE_ID, $code, '', '');
        }
        return (string)Option::get(self::MODULE_ID, $code, '', $siteId);
    }

    /**
     * Значение опции для сайта. Пустое значение сайта -> общее значение.
     */
    public static function get(string $code, string $siteId = '', $default = '')
    {
        if (!$siteId) {
            $siteId = self::getSiteId();
        }
        $val = Option::get(self::MODULE_ID, $code, $default, $siteId);
        if ($val === '' || $val === null) {
            $val = Option::get(self::MODULE_ID, $code, $default, '');
        }
        return $val;
    }

    public static function set(string $code, $value, string $siteId = ''): void
    {
        Option::set(self::MODULE_ID, $code, (string)$value, $siteId);
    }

    /**
     * Все настройки сайта одним массивом (с нормализацией типов)
     */
    public static function getSettings(string $siteId = ''): array
    {
        if (!$siteId) {
            $siteId = self::getSiteId();
        }
        $props = (string)self::get(self::OPT_PROPS, $siteId);
        $arProps = [];
        foreach (explode(',', $props) as $p) {
            $p = trim(strtoupper($p));
            if ($p !== '') {
                $arProps[] = $p;
            }
        }
        $cacheTime = (int)self::get(self::OPT_CACHE_TIME, $siteId, '86400');
        if ($cacheTime < 60) {
            $cacheTime = 86400;
        }
        $mainHostRaw = strtolower(trim((string)self::get(self::OPT_MAIN_HOST, $siteId)));
        $mainHosts = [];
        foreach (preg_split('~[\s,;]+~u', $mainHostRaw) as $h) {
            $h = trim($h);
            if ($h !== '' && !in_array($h, $mainHosts, true)) {
                $mainHosts[] = $h;
            }
        }
        $urlRegexps = [];
        foreach (explode("\n", str_replace("\r", '', (string)self::get(self::OPT_URL_REWRITE_REGEXPS, $siteId))) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $urlRegexps[] = $line;
            }
        }
        return [
            'SITE_ID' => $siteId,
            'ENABLED' => self::get(self::OPT_ENABLED, $siteId, 'N') === 'Y',
            'MODE' => (string)self::get(self::OPT_MODE, $siteId, 'both'),
            'MAIN_HOST' => $mainHostRaw,
            'MAIN_HOSTS' => $mainHosts,
            'URL_SEGMENT' => 1,
            'IBLOCK_ID' => (int)self::get(self::OPT_IBLOCK_ID, $siteId, '0'),
            'DEFAULT_CODE' => trim((string)self::get(self::OPT_DEFAULT_CODE, $siteId)),
            'REDIRECT_UNK' => self::get(self::OPT_REDIRECT_UNK, $siteId, 'N') === 'Y',
            'REDIRECT_STATUS' => self::get(self::OPT_REDIRECT_STATUS, $siteId, '302') === '301' ? '301' : '302',
            'PROPS' => $arProps,
            'EXTRA' => (string)self::get(self::OPT_EXTRA, $siteId),
            'URL_REWRITE_REGEXPS' => $urlRegexps,
            'REPLACE_TITLE' => self::get(self::OPT_REPLACE_TITLE, $siteId, 'Y') === 'Y',
            'REPLACE_PAGE_PROPS' => self::get(self::OPT_REPLACE_PAGE_PROPS, $siteId, 'Y') === 'Y',
            'REPLACE_CONTENT' => self::get(self::OPT_REPLACE_CONTENT, $siteId, 'Y') === 'Y',
            'CACHE_TIME' => $cacheTime,
        ];
    }

    /**
     * Доп. макросы из настроек: строки CODE=VALUE
     */
    public static function getExtraMacros(array $settings): array
    {
        $res = [];
        foreach (explode("\n", str_replace("\r", '', (string)$settings['EXTRA'])) as $line) {
            $line = trim($line);
            if ($line === '' || mb_strpos($line, '=') === false) {
                continue;
            }
            [$code, $val] = explode('=', $line, 2);
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $res[$code] = trim($val);
            }
        }
        return $res;
    }
}
