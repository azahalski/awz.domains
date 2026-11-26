<?php
/**
 * AWZ.Domains: ранняя подмена URL с городом в сегменте пути.
 * Подключается из dbconn.php (до загрузки ядра):
 *   @include_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/awz.domains/include/rewrite.php');
 * Список городов берется из bitrix/php_interface/dbcon_awz_d_cities_config.php
 * (генерируется модулем при сохранении настроек и изменении элементов инфоблока городов).
 */

if (defined('AWZ_D_REWRITE_DONE')) {
    return;
}
define('AWZ_D_REWRITE_DONE', true);

$__awzRoot = defined('DOCUMENT_ROOT') ? DOCUMENT_ROOT
    : (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '');
if ($__awzRoot !== '') {
    $__awzCfgFile = $__awzRoot . '/bitrix/php_interface/dbcon_awz_d_cities_config.php';
} else {
    // include/rewrite.php -> /bitrix/php_interface/dbcon_awz_d_cities_config.php
    $__awzCfgFile = dirname(__DIR__, 2) . '/php_interface/dbcon_awz_d_cities_config.php';
}
if (!file_exists($__awzCfgFile)) {
    return;
}
$__awzCfg = include $__awzCfgFile;
if (!is_array($__awzCfg) || empty($__awzCfg['cities']) || !is_array($__awzCfg['cities'])) {
    return;
}

$__awzCities = $__awzCfg['cities'];
$__awzDefault = isset($__awzCfg['default']) ? (string)$__awzCfg['default'] : '';

$__awzRequestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
$__awzParsed = parse_url($__awzRequestUri);
$__awzPath = isset($__awzParsed['path']) ? (string)$__awzParsed['path'] : '/';

if (preg_match('#^/(' . implode('|', array_map('preg_quote', $__awzCities)) . ')(/.*)?$#i', $__awzPath, $m)) {
    if (!defined('AWZ_D_CURRENT_CITY_CODE')) {
        define('AWZ_D_CURRENT_CITY_CODE', strtolower($m[1]));
    }

    // /msk/ -> /, /msk/link/ -> /link/
    $__awzCleanPath = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
    $__awzQuery = isset($__awzParsed['query']) ? '?' . $__awzParsed['query'] : '';
    $__awzNewRequestUri = $__awzCleanPath . $__awzQuery;

    $_SERVER['ORIGINAL_REQUEST_URI'] = $__awzRequestUri;
    $_SERVER['REQUEST_URI'] = $__awzNewRequestUri;

    if (isset($_SERVER['SCRIPT_URL'])) {
        $_SERVER['SCRIPT_URL'] = $__awzCleanPath;
    }
    if (isset($_SERVER['REDIRECT_URL'])) {
        $_SERVER['REDIRECT_URL'] = $__awzCleanPath;
    }

    unset($__awzCities, $__awzDefault, $__awzRequestUri, $__awzParsed, $__awzPath,
        $m, $__awzCleanPath, $__awzQuery, $__awzNewRequestUri, $__awzCfg, $__awzCfgFile);
    return;
}

if ($__awzDefault !== '' && !defined('AWZ_D_CURRENT_CITY_CODE')) {
    define('AWZ_D_CURRENT_CITY_CODE', $__awzDefault);
}

unset($__awzCities, $__awzDefault, $__awzRequestUri, $__awzParsed, $__awzPath, $__awzCfg, $__awzCfgFile);
