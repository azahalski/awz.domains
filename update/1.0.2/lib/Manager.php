<?php
namespace Awz\Domains;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Manager
{
    /** @var string Последняя ошибка создания инфоблока */
    private static string $lastError = '';

    /**
     * Текст последней ошибки createIblock
     */
    public static function getLastError(): string
    {
        return self::$lastError;
    }

    /**
     * Создание инфоблока городов.
     * $siteId = '*' - инфоблок на все сайты (общие настройки).
     * Тип инфоблока выбирается на странице настроек.
     * @return int ID инфоблока (0 при ошибке)
     */
    public static function createIblock(string $siteId, string $iblockType, string $name = ''): int
    {
        self::$lastError = '';
        if (!Loader::includeModule('iblock')) {
            self::$lastError = Loc::getMessage('AWZ_DOMAINS_IBM_ERR_NO_MODULE') ?: 'iblock module not installed';
            return 0;
        }
        $iblockType = trim($iblockType);
        if ($iblockType === '') {
            self::$lastError = Loc::getMessage('AWZ_DOMAINS_IBM_ERR_NO_TYPE') ?: 'iblock type is not specified';
            return 0;
        }

        // тип инфоблока должен существовать
        $typeExists = false;
        $dbTypes = \CIBlockType::GetList([], ['CHECK_PERMISSIONS' => 'N']);
        while ($t = $dbTypes->Fetch()) {
            if ((string)$t['ID'] === $iblockType) {
                $typeExists = true;
                break;
            }
        }
        if (!$typeExists) {
            self::$lastError = str_replace('#TYPE#', htmlspecialcharsbx($iblockType),
                Loc::getMessage('AWZ_DOMAINS_IBM_ERR_BAD_TYPE') ?: 'unknown iblock type #TYPE#');
            return 0;
        }

        $siteIds = [];
        if ($siteId === '*') {
            $dbSites = \Bitrix\Main\SiteTable::getList(['select' => ['LID']]);
            while ($site = $dbSites->fetch()) {
                $siteIds[] = $site['LID'];
            }
            if (empty($siteIds)) {
                $siteIds = ['s1'];
            }
            $code = 'awz_domains_cities';
        } else {
            $siteId = trim($siteId);
            if ($siteId === '') {
                return 0;
            }
            $siteIds = [$siteId];
            $code = 'awz_domains_cities_' . strtolower($siteId);
        }

        // уже есть инфоблок с таким кодом
        $dbRes = \CIBlock::GetList(
            ['ID' => 'ASC'],
            ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N']
        );
        if ($row = $dbRes->Fetch()) {
            return (int)$row['ID'];
        }

        $ib = new \CIBlock;
        $arFields = [
            'ACTIVE' => 'Y',
            'NAME' => trim($name) !== ''
                ? trim($name)
                : (Loc::getMessage('AWZ_DOMAINS_IBM_CITIES_NAME')
                    . ($siteId === '*' ? '' : ' [' . $siteId . ']')),
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => $iblockType,
            'SITE_ID' => $siteIds,
            'SORT' => 500,
            'GROUP_ID' => ['2' => 'R'],
            'WORKFLOW' => 'N',
            'BIZPROC' => 'N',
            'INDEX_ELEMENT' => 'N',
            'INDEX_SECTION' => 'N',
        ];
        $iblockId = (int)$ib->Add($arFields);
        if ($iblockId <= 0) {
            self::$lastError = $ib->LAST_ERROR !== ''
                ? $ib->LAST_ERROR
                : (Loc::getMessage('AWZ_DOMAINS_IBM_ERR_UNKNOWN') ?: 'unknown iblock create error');
            return 0;
        }

        // стандартные свойства
        $defProps = [
            'PHONE' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_PHONE'), 'SORT' => 100],
            'PHONE2' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_PHONE2'), 'SORT' => 110],
            'EMAIL' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_EMAIL'), 'SORT' => 200],
            'ADDRESS' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_ADRESS'), 'SORT' => 300],
            'WORK_TIME' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_WORK_TIME'), 'SORT' => 400],
            'CURRENCY' => ['NAME' => Loc::getMessage('AWZ_DOMAINS_PROP_CURRENCY'), 'SORT' => 500],
        ];
        foreach ($defProps as $pCode => $p) {
            $dbProp = \CIBlockProperty::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'CODE' => $pCode, 'CHECK_PERMISSIONS' => 'N']
            );
            if ($dbProp->Fetch()) {
                continue;
            }
            $prop = new \CIBlockProperty;
            $prop->Add([
                'IBLOCK_ID' => $iblockId,
                'ACTIVE' => 'Y',
                'SORT' => $p['SORT'],
                'CODE' => $pCode,
                'NAME' => $p['NAME'],
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N',
            ]);
        }

        return $iblockId;
    }

    /**
     * Список типов инфоблоков для выбора
     */
    public static function getIblockTypes(): array
    {
        $res = [];
        if (Loader::includeModule('iblock')) {
            $dbRes = \CIBlockType::GetList([], ['CHECK_PERMISSIONS' => 'N']);
            while ($row = $dbRes->Fetch()) {
                $id = (string)$row['ID'];
                $name = Loc::getMessage('AWZ_DOMAINS_IBT_' . strtoupper($id));
                if (!$name) {
                    $dbLang = \CIBlockType::GetByID($id);
                    if ($lang = $dbLang->Fetch()) {
                        $name = (string)($lang['NAME'] ?: $id);
                    } else {
                        $name = $id;
                    }
                }
                $res[$id] = $name;
            }
        }
        return $res;
    }
}
