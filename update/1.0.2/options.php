<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\SiteTable;
use Awz\Domains\Access\AccessController;
use Awz\Domains\Helper;
use Awz\Domains\Manager;
use Awz\Domains\City;

Loc::loadMessages(__FILE__);
global $APPLICATION;
$module_id = "awz.domains";
if(!Loader::includeModule($module_id)) return;
Extension::load('ui.sidepanel-content');
$request = Application::getInstance()->getContext()->getRequest();
$APPLICATION->SetTitle(Loc::getMessage('AWZ_DOMAINS_OPT_TITLE'));

if($request->get('IFRAME_TYPE')==='SIDE_SLIDER'){
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
    require_once('lib/access/include/moduleright.php');
    CMain::finalActions();
    die();
}

if(!AccessController::isViewSettings())
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

$canEdit = AccessController::isEditSettings();
$saveUrl = $APPLICATION->GetCurPage(false).'?mid='.htmlspecialcharsbx($module_id).'&lang='.LANGUAGE_ID.'&mid_menu=1';

$boolFields = [
    Helper::OPT_ENABLED,
    Helper::OPT_REDIRECT_UNK,
    Helper::OPT_REPLACE_TITLE,
    Helper::OPT_REPLACE_PAGE_PROPS,
    Helper::OPT_REPLACE_CONTENT,
];
$selectFields = [
    Helper::OPT_MODE => [
        'sub' => Loc::getMessage('AWZ_DOMAINS_OPT_MODE_SUB'),
        'url' => Loc::getMessage('AWZ_DOMAINS_OPT_MODE_URL'),
        'both' => Loc::getMessage('AWZ_DOMAINS_OPT_MODE_BOTH'),
    ],
    Helper::OPT_REDIRECT_STATUS => [
        '302' => '302 — '.Loc::getMessage('AWZ_DOMAINS_OPT_REDIRECT_TEMP'),
        '301' => '301 — '.Loc::getMessage('AWZ_DOMAINS_OPT_REDIRECT_PERM'),
    ],
];
$textFields = [
    Helper::OPT_MAIN_HOST, Helper::OPT_IBLOCK_ID,
    Helper::OPT_DEFAULT_CODE, Helper::OPT_PROPS, Helper::OPT_CACHE_TIME, Helper::OPT_URL_REWRITE_REGEXPS,
];
$allFields = array_merge($boolFields, array_keys($selectFields), $textFields);

// сохранение настроек
$ibCreateErr = '';
if ($request->getRequestMethod()==='POST' && $canEdit && $request->get('Update')==='Y') {
    $siteIds = [];
    $dbSites = SiteTable::getList(['select'=>['LID']]);
    while($site = $dbSites->fetch()){
        $siteIds[] = $site['LID'];
    }

    foreach($allFields as $f){
        if(in_array($f, $boolFields, true)){
            // общие настройки: чекбокс
            $val = ($request->get($f)==='Y') ? 'Y' : 'N';
            Option::set($module_id, $f, $val, '');
            // настройки по сайтам: три состояния ('' - как в общих)
            foreach($siteIds as $lid){
                $sval = (string)$request->get($f.'_'.$lid);
                $sval = ($sval==='Y') ? 'Y' : (($sval==='N') ? 'N' : '');
                if($sval === ''){
                    Option::delete($module_id, ['name'=>$f, 'site_id'=>$lid]);
                }else{
                    Option::set($module_id, $f, $sval, $lid);
                }
            }
        }elseif(isset($selectFields[$f])){
            $val = (string)$request->get($f);
            Option::set($module_id, $f, $val, '');
            foreach($siteIds as $lid){
                $sval = (string)$request->get($f.'_'.$lid);
                if($sval === ''){
                    Option::delete($module_id, ['name'=>$f, 'site_id'=>$lid]);
                }else{
                    Option::set($module_id, $f, $sval, $lid);
                }
            }
        }elseif($f === Helper::OPT_IBLOCK_ID){
            $ibHandle = function(string $ctxSite, string $suffix) use ($request, &$ibCreateErr){
                $mode = trim((string)$request->get('IBM_IBLOCK'.$suffix));
                if($ctxSite !== '' && $mode === '') return '';
                if($mode === 'new'){
                    $type = trim((string)$request->get('IBM_TYPE'.$suffix));
                    $iname = trim((string)$request->get('IBM_NAME'.$suffix));
                    if($iname === '') $iname = (string)Loc::getMessage('AWZ_DOMAINS_OPT_IBM_NEW_DEFAULT');
                    $id = Manager::createIblock($ctxSite === '' ? '*' : $ctxSite, $type, $iname);
                    if($id <= 0){
                        $ibCreateErr = Manager::getLastError();
                        return '';
                    }
                    return (string)$id;
                }
                $id = (int)$mode;
                return $id > 0 ? (string)$id : '';
            };
            Option::set($module_id, $f, (string)$ibHandle('', ''), '');
            foreach($siteIds as $lid){
                $sval = $ibHandle($lid, '_'.$lid);
                if($sval === ''){
                    Option::delete($module_id, ['name'=>$f, 'site_id'=>$lid]);
                }else{
                    Option::set($module_id, $f, $sval, $lid);
                }
            }
        }elseif($f === Helper::OPT_PROPS){
            $toCodes = function($v){
                $out = [];
                foreach((array)$v as $p){
                    $p = trim(strtoupper((string)$p));
                    if($p !== '' && !in_array($p, $out, true)) $out[] = $p;
                }
                return implode(',', $out);
            };
            $val = $toCodes($request->get($f));
            Option::set($module_id, $f, $val, '');
            foreach($siteIds as $lid){
                $sval = $toCodes($request->get($f.'_'.$lid));
                if($sval === ''){
                    Option::delete($module_id, ['name'=>$f, 'site_id'=>$lid]);
                }else{
                    Option::set($module_id, $f, $sval, $lid);
                }
            }
        }else{
            $val = (string)$request->get($f);
            Option::set($module_id, $f, $val, '');
            foreach($siteIds as $lid){
                $sval = trim((string)$request->get($f.'_'.$lid));
                if($sval === ''){
                    Option::delete($module_id, ['name'=>$f, 'site_id'=>$lid]);
                }else{
                    Option::set($module_id, $f, $sval, $lid);
                }
            }
        }
    }
    City::clearCache('*');
    Helper::writeUrlIncludeConfig();
    $resParams = '&SAVED=Y';
    if($ibCreateErr !== ''){
        $resParams .= '&IBM_RES=0&IBM_ERR='.urlencode($ibCreateErr);
    }
    LocalRedirect($saveUrl.$resParams);
}

$sites = [];
$dbSites = SiteTable::getList(['select'=>['LID','SITE_NAME','ACTIVE'],'order'=>['SORT'=>'ASC']]);
while($site = $dbSites->fetch()){
    $sites[] = $site;
}
if(empty($sites)){
    $sites[] = ['LID'=>'s1','SITE_NAME'=>'s1','ACTIVE'=>'Y'];
}

$aTabs = [];
$aTabs[] = [
    'DIV' => 'common',
    'TAB' => Loc::getMessage('AWZ_DOMAINS_OPT_TAB_COMMON'),
    'ICON' => 'vote_settings',
    'TITLE' => Loc::getMessage('AWZ_DOMAINS_OPT_TAB_COMMON_T'),
];
foreach($sites as $site){
    $aTabs[] = [
        'DIV' => 'site_'.$site['LID'],
        'TAB' => ($site['SITE_NAME'] ?: $site['LID']).' ['.$site['LID'].']',
        'ICON' => 'vote_settings',
        'TITLE' => Loc::getMessage('AWZ_DOMAINS_OPT_SITE_TAB', ['#SITE#' => ($site['SITE_NAME'] ?: $site['LID'])]),
    ];
}
$aTabs[] = [
    'DIV' => 'macros_help',
    'TAB' => Loc::getMessage('AWZ_DOMAINS_OPT_TAB_HELP'),
    'ICON' => 'vote_settings',
    'TITLE' => Loc::getMessage('AWZ_DOMAINS_OPT_TAB_HELP'),
];

$tabControl = new CAdminTabControl("tabControl", $aTabs);

// сообщения над формой (стандартные)
$adminMsg = '';
if ($request->get('SAVED')==='Y') {
    $adminMsg .= CAdminMessage::ShowMessage([
        'TYPE' => 'OK',
        'MESSAGE' => Loc::getMessage('AWZ_DOMAINS_OPT_SAVED'),
        'HTML' => true,
    ]);
}
if ($request->get('IBM_RES')==='1') {
    $adminMsg .= CAdminMessage::ShowMessage([
        'TYPE' => 'OK',
        'MESSAGE' => Loc::getMessage('AWZ_DOMAINS_OPT_IBM_CREATED'),
        'HTML' => true,
    ]);
} elseif ($request->get('IBM_RES')==='0') {
    $adminMsg .= CAdminMessage::ShowMessage([
        'TYPE' => 'ERROR',
        'MESSAGE' => Loc::getMessage('AWZ_DOMAINS_OPT_IBM_ERROR'),
        'DETAILS' => htmlspecialcharsbx((string)$request->get('IBM_ERR')),
        'HTML' => true,
    ]);
}
if ($adminMsg !== '') {
    echo '<div class="awz-domains-admin-msg" style="margin:10px 0;">'.$adminMsg.'</div>';
}

$tabControl->Begin();

/**
 * Вывод строки поля
 */
function awzDomainsOptRow($f, $suffix, $siteId, $canEdit, $boolFields, $selectFields, $titleKey, $descKey = '')
{
    $raw = Helper::getRaw($f, $siteId);
    $isCommon = ($siteId === '');
    $name = $f.$suffix;
    if(in_array($f, $boolFields, true)){
        $val = $isCommon ? $raw : $raw;
        if($isCommon){
            $chk = ($val === 'Y');
            echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
            echo '<input type="checkbox" value="Y" name="'.htmlspecialcharsbx($name).'" '.($canEdit?'':'disabled').' '.($chk?'checked':'').'>';
            if($descKey) echo '<div style="color:#666;font-size:11px;">'.Loc::getMessage($descKey).'</div>';
            echo '</td></tr>';
        }else{
            echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
            echo '<select name="'.htmlspecialcharsbx($name).'" '.($canEdit?'':'disabled').'>';
            echo '<option value="" '.($raw===''?'selected':'').'>'.Loc::getMessage('AWZ_DOMAINS_OPT_INHERIT').'</option>';
            echo '<option value="Y" '.($raw==='Y'?'selected':'').'>'.Loc::getMessage('AWZ_DOMAINS_OPT_VAL_YES').'</option>';
            echo '<option value="N" '.($raw==='N'?'selected':'').'>'.Loc::getMessage('AWZ_DOMAINS_OPT_VAL_NO').'</option>';
            echo '</select></td></tr>';
        }
        return;
    }
    if(isset($selectFields[$f])){
        echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
        echo '<select name="'.htmlspecialcharsbx($name).'" '.($canEdit?'':'disabled').'>';
        if(!$isCommon){
            echo '<option value="" '.($raw===''?'selected':'').'>'.Loc::getMessage('AWZ_DOMAINS_OPT_INHERIT').'</option>';
        }
        foreach($selectFields[$f] as $optVal=>$optName){
            echo '<option value="'.htmlspecialcharsbx($optVal).'" '.($raw===(string)$optVal?'selected':'').'>'.htmlspecialcharsbx($optName).'</option>';
        }
        echo '</select></td></tr>';
        return;
    }
    if($f === Helper::OPT_PROPS){
        $iblockId = (int)($isCommon ? Helper::getRaw(Helper::OPT_IBLOCK_ID, '') : Helper::get(Helper::OPT_IBLOCK_ID, $siteId));
        echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
        if($iblockId <= 0){
            echo '<input type="text" size="50" name="'.htmlspecialcharsbx($name).'" value="'.htmlspecialcharsbx($raw).'" placeholder="'.($isCommon?'':htmlspecialcharsbx(Helper::getRaw($f, ''))).'" '.($canEdit?'':'disabled').'>';
            echo '<div style="color:#666;font-size:11px;">'.Loc::getMessage('AWZ_DOMAINS_OPT_PROPS_NO_IBM').'</div>';
        }else{
            $rawCodes = [];
            foreach(explode(',', $raw) as $p){
                $p = trim(strtoupper($p));
                if($p !== '') $rawCodes[] = $p;
            }
            $propsList = [];
            $dbProps = \CIBlockProperty::GetList(
                ['SORT' => 'ASC', 'NAME' => 'ASC'],
                ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']
            );
            while($prop = $dbProps->Fetch()){
                $propsList[(string)$prop['CODE']] = $prop['NAME'];
            }
            foreach($rawCodes as $c){
                if($c !== '' && !isset($propsList[$c])) $propsList[$c] = $c;
            }
            echo '<select name="'.htmlspecialcharsbx($name).'[]" multiple size="6" '.($canEdit?'':'disabled').' style="min-width:250px;">';
            foreach($propsList as $pCode => $pName){
                $sel = in_array($pCode, $rawCodes, true) ? ' selected' : '';
                echo '<option value="'.htmlspecialcharsbx($pCode).'"'.$sel.'>'.htmlspecialcharsbx($pCode.' — '.$pName).'</option>';
            }
            echo '</select>';
        }
        if($descKey) echo '<div style="color:#666;font-size:11px;">'.Loc::getMessage($descKey).'</div>';
        echo '</td></tr>';
        return;
    }
    if($f === Helper::OPT_URL_REWRITE_REGEXPS || $f === Helper::OPT_EXTRA){
        echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
        echo '<textarea name="'.htmlspecialcharsbx($name).'" rows="5" cols="70" placeholder="'.($isCommon?'':htmlspecialcharsbx(Helper::getRaw($f, ''))).'" '.($canEdit?'':'disabled').'>'.htmlspecialcharsbx($raw).'</textarea>';
        if($descKey) echo '<div style="color:#666;font-size:11px;">'.Loc::getMessage($descKey).'</div>';
        echo '</td></tr>';
        return;
    }
    if($f === Helper::OPT_IBLOCK_ID){
        echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
        $sfx = $siteId !== '' ? '_'.$siteId : '';
        if(!$canEdit){
            echo '<input type="text" size="5" name="'.htmlspecialcharsbx($name).'" value="'.htmlspecialcharsbx($raw).'" disabled>';
            echo '</td></tr>';
            return;
        }
        $iblockId = (int)$raw;
        $curType = '';
        if($iblockId > 0){
            $dbIb = \CIBlock::GetByID($iblockId);
            if($ibArr = $dbIb->Fetch()){
                $curType = (string)$ibArr['IBLOCK_TYPE_ID'];
            }
        }
        $types = Manager::getIblockTypes();
        $groups = [];
        $dbRes = \CIBlock::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N']
        );
        while($ib = $dbRes->Fetch()){
            $t = (string)$ib['IBLOCK_TYPE_ID'];
            if(!isset($types[$t])) continue;
            $groups[$t][] = $ib;
        }
        echo '<select name="IBM_IBLOCK'.$sfx.'" id="IBM_IBLOCK'.$sfx.'" onchange="awzDomainsIbNew(this, \''.htmlspecialcharsbx($sfx).'\')" style="min-width:250px;">';
        if(!$isCommon){
            echo '<option value="" '.($raw===''?'selected':'').'>'.Loc::getMessage('AWZ_DOMAINS_OPT_INHERIT').'</option>';
        }
        foreach($groups as $t => $list){
            echo '<optgroup label="'.htmlspecialcharsbx($types[$t]).'">';
            foreach($list as $ib){
                $sel = ((int)$ib['ID'] === $iblockId) ? ' selected' : '';
                echo '<option value="'.(int)$ib['ID'].'"'.$sel.'>'.htmlspecialcharsbx($ib['NAME'].' ['.(int)$ib['ID'].']').'</option>';
            }
            echo '</optgroup>';
        }
        echo '<option value="new">'.Loc::getMessage('AWZ_DOMAINS_OPT_IBM_NEW').'</option>';
        echo '</select>';
        echo '<div id="IBM_NEW'.$sfx.'" style="display:none;margin-top:5px;">';
        echo '<select name="IBM_TYPE'.$sfx.'">';
        foreach($types as $tid => $tname){
            $sel = ($tid === $curType) ? ' selected' : '';
            echo '<option value="'.htmlspecialcharsbx($tid).'"'.$sel.'>'.htmlspecialcharsbx($tname).'</option>';
        }
        echo '</select> ';
        echo '<input type="text" size="25" name="IBM_NAME'.$sfx.'" value="'.htmlspecialcharsbx((string)Loc::getMessage('AWZ_DOMAINS_OPT_IBM_NEW_DEFAULT')).'" placeholder="'.htmlspecialcharsbx((string)Loc::getMessage('AWZ_DOMAINS_OPT_IBM_NEW_DEFAULT')).'">';
        echo '</div>';
        echo '</td></tr>';
        return;
    }
    echo '<tr><td style="width:250px;">'.Loc::getMessage($titleKey).'</td><td>';
    echo '<input type="text" size="50" name="'.htmlspecialcharsbx($name).'" value="'.htmlspecialcharsbx($raw).'" placeholder="'.($isCommon?'':htmlspecialcharsbx(Helper::getRaw($f, ''))).'" '.($canEdit?'':'disabled').'>';
    if($descKey) echo '<div style="color:#666;font-size:11px;">'.Loc::getMessage($descKey).'</div>';
    echo '</td></tr>';
}
?>
    <style>.adm-workarea option:checked {background-color: rgb(206, 206, 206);}</style>
    <script>
    function awzDomainsIbNew(sel, sfx){
        var box = document.getElementById('IBM_NEW' + sfx);
        if(box) box.style.display = (sel.value === 'new') ? '' : 'none';
    }
    </script>
    <form method="POST" action="<?=$saveUrl?>" id="FORMACTION">
        <?
        // общие настройки
        $tabControl->BeginNextTab();
        Extension::load("ui.alerts");
        ?>
        <tr>
            <td style="width:250px;"></td>
            <td>
                <div class="ui-alert ui-alert-success">
                    <span class="ui-alert-message">
                        <?=Loc::getMessage('AWZ_DOMAINS_OPT_SHOW_DESC')?>
                    </span>
                </div>
            </td>
        </tr>
        <?
        awzDomainsOptRow(Helper::OPT_ENABLED, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_ENABLED_TITLE');
        awzDomainsOptRow(Helper::OPT_MODE, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_MODE_TITLE');
        awzDomainsOptRow(Helper::OPT_MAIN_HOST, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_MAIN_HOST_TITLE', 'AWZ_DOMAINS_OPT_MAIN_HOST_DESC');
        awzDomainsOptRow(Helper::OPT_URL_REWRITE_REGEXPS, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_URL_REGEXPS_T', 'AWZ_DOMAINS_OPT_URL_REGEXPS_DESC');
        awzDomainsOptRow(Helper::OPT_IBLOCK_ID, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_IBLOCK_TITLE');
        awzDomainsOptRow(Helper::OPT_DEFAULT_CODE, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_DEFAULT_CODE_TITLE', 'AWZ_DOMAINS_OPT_DEFAULT_CODE_DESC');
        ?>
        <tr>
            <td style="width:250px;"></td>
            <td>
                <div style="color:#666;font-size:11px;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_DEFAULT_CODE_EVENT')?></div>
            </td>
        </tr>
        <?
        awzDomainsOptRow(Helper::OPT_REDIRECT_UNK, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REDIRECT_UNK_TITLE', 'AWZ_DOMAINS_OPT_REDIRECT_UNK_DESC');
        awzDomainsOptRow(Helper::OPT_REDIRECT_STATUS, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REDIRECT_STATUS_TITLE');
        awzDomainsOptRow(Helper::OPT_PROPS, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_PROPS_TITLE', 'AWZ_DOMAINS_OPT_PROPS_DESC');
        ?>
        <tr>
            <td style="width:250px;"></td>
            <td>
                <div style="color:#666;font-size:11px;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_PROPS_EVENT')?></div>
            </td>
        </tr>
        <?
        awzDomainsOptRow(Helper::OPT_REPLACE_TITLE, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_TITLE_T');
        awzDomainsOptRow(Helper::OPT_REPLACE_PAGE_PROPS, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_PP_T');
        awzDomainsOptRow(Helper::OPT_REPLACE_CONTENT, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_C_T');
        awzDomainsOptRow(Helper::OPT_CACHE_TIME, '', '', $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_CACHE_T', 'AWZ_DOMAINS_OPT_CACHE_DESC');

        // настройки по сайтам
        foreach($sites as $site){
            $lid = $site['LID'];
            $tabControl->BeginNextTab();
            ?>
            <tr>
                <td style="width:250px;"></td>
                <td>
                    <div class="ui-alert ui-alert-success">
                        <span class="ui-alert-message">
                            <?=Loc::getMessage('AWZ_DOMAINS_OPT_SITE_DESC')?>
                        </span>
                    </div>
                </td>
            </tr>
            <?
            awzDomainsOptRow(Helper::OPT_ENABLED, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_ENABLED_TITLE');
            awzDomainsOptRow(Helper::OPT_MODE, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_MODE_TITLE');
            awzDomainsOptRow(Helper::OPT_MAIN_HOST, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_MAIN_HOST_TITLE', 'AWZ_DOMAINS_OPT_MAIN_HOST_DESC');
            awzDomainsOptRow(Helper::OPT_URL_REWRITE_REGEXPS, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_URL_REGEXPS_T', 'AWZ_DOMAINS_OPT_URL_REGEXPS_DESC');
            awzDomainsOptRow(Helper::OPT_IBLOCK_ID, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_IBLOCK_TITLE');
            awzDomainsOptRow(Helper::OPT_DEFAULT_CODE, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_DEFAULT_CODE_TITLE', 'AWZ_DOMAINS_OPT_DEFAULT_CODE_DESC');
            awzDomainsOptRow(Helper::OPT_REDIRECT_UNK, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REDIRECT_UNK_TITLE', 'AWZ_DOMAINS_OPT_REDIRECT_UNK_DESC');
            awzDomainsOptRow(Helper::OPT_REDIRECT_STATUS, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REDIRECT_STATUS_TITLE');
            awzDomainsOptRow(Helper::OPT_PROPS, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_PROPS_TITLE', 'AWZ_DOMAINS_OPT_PROPS_DESC');
            awzDomainsOptRow(Helper::OPT_REPLACE_TITLE, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_TITLE_T');
            awzDomainsOptRow(Helper::OPT_REPLACE_PAGE_PROPS, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_PP_T');
            awzDomainsOptRow(Helper::OPT_REPLACE_CONTENT, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_REPLACE_C_T');
            awzDomainsOptRow(Helper::OPT_CACHE_TIME, '_'.$lid, $lid, $canEdit, $boolFields, $selectFields, 'AWZ_DOMAINS_OPT_CACHE_T', 'AWZ_DOMAINS_OPT_CACHE_DESC');
        }

        $tabControl->BeginNextTab();
        $hPreStyle = 'margin:6px 0 12px;padding:10px 12px;background:#f2f5f7;border:1px solid #dce3e8;border-radius:4px;overflow:auto;font-size:12px;line-height:1.5;';
        ?>
        <tr>
            <td colspan="2" style="padding:10px 0;">
                <div style="font-size:16px;font-weight:bold;margin-bottom:8px;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_MACROS_T')?></div>
                <table class="adm-detail-content-table edit-table" style="width:100%;">
                    <tr><td style="width:340px;"><b>#AWZ_S_NAME#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_NAME')?></td></tr>
                    <tr><td><b>#AWZ_S_CODE#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_CODE')?></td></tr>
                    <tr><td><b>#AWZ_S_PHONE#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_PROP')?></td></tr>
                    <tr><td><b>#AWZ_S_PHONE~tel#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_TEL')?></td></tr>
                    <tr><td><b>#AWZ_S_NAME~upper#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_UPPER')?></td></tr>
                    <tr><td><b>#AWZ_S_PHONE~default:Позвоните нам#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_DEFAULT')?></td></tr>
                    <tr><td><b>#AWZ_S_COUNT~plural:товар|товара|товаров#</b></td><td><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_PLURAL')?></td></tr>
                </table>
                <div style="margin-top:10px;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_MODS_LIST')?></div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding:10px 0;">
                <div style="font-size:16px;font-weight:bold;margin-bottom:4px;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_EVENTS_T')?></div>
                <div><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_EVENTS')?></div>
                <div style="margin-top:12px;font-weight:bold;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_EV_MACROS_T')?></div>
                <pre style="<?=$hPreStyle?>"><?=htmlspecialcharsbx(Loc::getMessage('AWZ_DOMAINS_OPT_H_EV_MACROS'))?></pre>
                <div style="font-weight:bold;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_EV_DEF_T')?></div>
                <pre style="<?=$hPreStyle?>"><?=htmlspecialcharsbx(Loc::getMessage('AWZ_DOMAINS_OPT_H_EV_DEF'))?></pre>
                <div style="margin-top:12px;font-weight:bold;"><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_DBCONN_T')?></div>
                <div><?=Loc::getMessage('AWZ_DOMAINS_OPT_H_DBCONN')?></div>
            </td>
        </tr>
        <?
        $tabControl->Buttons();
        ?>
        <input <?if (!$canEdit) echo "disabled" ?> type="submit" class="adm-btn-green" name="Update" value="<?=Loc::getMessage('AWZ_DOMAINS_OPT_L_BTN_SAVE')?>" />
        <input type="hidden" name="Update" value="Y" />
        <?if(AccessController::isViewRight()){?>
            <button class="adm-header-btn adm-security-btn" onclick="BX.SidePanel.Instance.open('<?=$saveUrl?>');return false;">
                <?=Loc::getMessage('AWZ_DOMAINS_OPT_SECT2')?>
            </button>
        <?}?>
        <?$tabControl->End();?>
    </form>
<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
