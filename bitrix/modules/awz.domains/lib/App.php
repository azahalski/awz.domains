<?php
namespace Awz\Domains;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class App
{
    /** @var bool инициализация выполнена */
    private static $inited = false;

    /**
     * OnAfterIBlockElementAdd: сброс кеша + обновление dbcon-конфига городов
     */
    public static function onAfterIblockElementAdd($arFields): void
    {
        self::refreshCityCacheAndConfig((int)($arFields['IBLOCK_ID'] ?? 0));
    }

    /**
     * OnAfterIBlockElementUpdate: сброс кеша + обновление dbcon-конфига городов
     */
    public static function onAfterIblockElementUpdate($arFields): void
    {
        self::refreshCityCacheAndConfig((int)($arFields['IBLOCK_ID'] ?? 0));
    }

    /**
     * OnAfterIBlockElementDelete: сброс кеша + обновление dbcon-конфига городов
     */
    public static function onAfterIblockElementDelete($arFields): void
    {
        self::refreshCityCacheAndConfig((int)($arFields['IBLOCK_ID'] ?? 0));
    }

    private static function refreshCityCacheAndConfig(int $iblockId): void
    {
        if (!Loader::includeModule(Helper::MODULE_ID)) {
            return;
        }
        if (!Helper::refreshUrlIncludeConfig($iblockId)) {
            return;
        }
        City::clearCache('*');
    }

    /**
     * OnProlog: определить город, при необходимости редирект
     */
    public static function onProlog(): void
    {
        if (!Loader::includeModule(Helper::MODULE_ID)) {
            return;
        }
        $request = Application::getInstance()->getContext()->getRequest();
        if ($request->isAdminSection()) {
            return;
        }
        self::$inited = true;
        City::getInstance()->init();
    }

    /**
     * OnEpilog: замена в заголовке и свойствах страницы
     */
    public static function onEpilog(): void
    {
        global $APPLICATION;
        if (!Loader::includeModule(Helper::MODULE_ID) || !self::$inited) {
            return;
        }
        $request = Application::getInstance()->getContext()->getRequest();
        if ($request->isAdminSection()) {
            return;
        }
        $siteId = Helper::getSiteId();
        $settings = Helper::getSettings($siteId);
        if (!$settings['ENABLED']) {
            return;
        }

        if ($settings['REPLACE_PAGE_PROPS']) {
            foreach ($APPLICATION->GetPagePropertyList() as $k => $v) {
                $APPLICATION->SetPageProperty($k, Macros::replace((string)$v, $siteId));
            }
        }
        if ($settings['REPLACE_TITLE']) {
            $APPLICATION->SetTitle(Macros::replace((string)$APPLICATION->GetTitle(), $siteId));
            foreach (['H1', 'h1', 'TITLE_H1'] as $h1Key) {
                $h1Val = $APPLICATION->GetPageProperty($h1Key);
                if ($h1Val !== null && (string)$h1Val !== '') {
                    $APPLICATION->SetPageProperty($h1Key, Macros::replace((string)$h1Val, $siteId));
                }
            }
        }
    }

    /**
     * OnEndBufferContent: замена макросов в финальном контенте
     */
    public static function onEndBufferContent(&$content): void
    {

        if (!is_string($content)) {
            return;
        }
        if (!Loader::includeModule(Helper::MODULE_ID) || !self::$inited) {
            return;
        }
        $request = Application::getInstance()->getContext()->getRequest();
        if ($request->isAdminSection()) {
            return;
        }
        $siteId = Helper::getSiteId();
        $settings = Helper::getSettings($siteId);
        if (!$settings['ENABLED'] || !$settings['REPLACE_CONTENT']) {
            return;
        }

        $content = Macros::replace($content, $siteId);
        if (!$settings['REPLACE_CONTENT'] && $settings['REPLACE_TITLE']) {
            // h1 в шаблонах/компонентах, не заданный через свойства страницы
            $content = preg_replace_callback(
                '~(<h1[^>]*>)(.*?)(</h1>)~isu',
                function ($m) use ($siteId) {
                    return $m[1] . Macros::replace($m[2], $siteId) . $m[3];
                },
                $content
            );
        }
    }
}
