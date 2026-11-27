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
if (!is_array($__awzCfg)) {
    return;
}

// выбор записи по хосту (многосайтовость); формат с 'sites' или legacy с 'cities'
if (isset($__awzCfg['sites']) && is_array($__awzCfg['sites'])) {
    $__awzHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $__awzHost = preg_replace('~:\d+~', '', $__awzHost);
    $__awzEntry = null;
    foreach ($__awzCfg['sites'] as $__awzS) {
        if (!is_array($__awzS) || empty($__awzS['hosts'])) {
            continue;
        }
        foreach ((array)$__awzS['hosts'] as $__awzH) {
            if ($__awzH !== '' && $__awzH === $__awzHost) {
                $__awzEntry = $__awzS;
                break 2;
            }
        }
    }
    if ($__awzEntry === null) {
        // сайт без домена (папочный мультисайт) - fallback
        $__awzEntry = isset($__awzCfg['common']) && is_array($__awzCfg['common']) ? $__awzCfg['common'] : null;
    }
    if ($__awzEntry === null || empty($__awzEntry['cities']) || !is_array($__awzEntry['cities'])) {
        return;
    }
    $__awzCities = $__awzEntry['cities'];
    $__awzDefault = isset($__awzEntry['default']) ? (string)$__awzEntry['default'] : '';
    $__awzCfgRegexps = isset($__awzEntry['regexps']) && is_array($__awzEntry['regexps']) ? $__awzEntry['regexps'] : [];
} else {
    if (empty($__awzCfg['cities']) || !is_array($__awzCfg['cities'])) {
        return;
    }
    $__awzCities = $__awzCfg['cities'];
    $__awzDefault = isset($__awzCfg['default']) ? (string)$__awzCfg['default'] : '';
    $__awzCfgRegexps = isset($__awzCfg['regexps']) && is_array($__awzCfg['regexps']) ? $__awzCfg['regexps'] : [];
}

$__awzRequestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
$__awzParsed = parse_url($__awzRequestUri);
$__awzPath = isset($__awzParsed['path']) ? (string)$__awzParsed['path'] : '/';

$__awzDoRewrite = false;
if (preg_match('#^/(' . implode('|', array_map('preg_quote', $__awzCities)) . ')(/.*)?$#i', $__awzPath, $m)) {
    // опция: подменять только URL, подходящие под регулярки (одна в строке);
    // пустой список - подменяются все
    $__awzRegexps = $__awzCfgRegexps;
    $__awzAllowed = empty($__awzRegexps);
    foreach ((array)$__awzRegexps as $__awzRegexp) {
        if (@preg_match('~' . str_replace('~', '\~', trim($__awzRegexp)) . '~i', $__awzPath)) {
            $__awzAllowed = true;
            break;
        }
    }

    if ($__awzAllowed) {
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

        $__awzDoRewrite = true;
    }
}

// подмененный путь физически существует - запоминаем реальный файл:
// nginx на исходном URL файла не нашел и направил на urlrewrite.php,
// поэтому после подмены физический файл подключаем напрямую и завершаемся
if ($__awzDoRewrite) {
    $__awzDocRoot = rtrim($__awzRoot !== '' ? $__awzRoot : dirname($__awzCfgFile, 2), '/\\');
    if (substr($__awzCleanPath, -1) === '/') {
        if (is_file($__awzDocRoot . $__awzCleanPath . 'index.php')) {
            $__awzIncludeFile = $__awzDocRoot . $__awzCleanPath . 'index.php';
        }
    } elseif (is_file($__awzDocRoot . $__awzCleanPath)) {
        $__awzIncludeFile = $__awzDocRoot . $__awzCleanPath;
    } elseif (is_file($__awzDocRoot . $__awzCleanPath . '/index.php')) {
        $__awzIncludeFile = $__awzDocRoot . $__awzCleanPath . '/index.php';
    }
    if (isset($__awzIncludeFile)) {
        $__awzScriptName = str_replace('\\', '/', substr($__awzIncludeFile, strlen($__awzDocRoot)));
        $_SERVER['SCRIPT_FILENAME'] = $__awzIncludeFile;
        $_SERVER['SCRIPT_NAME'] = $__awzScriptName;
        $_SERVER['PHP_SELF'] = $__awzScriptName;
        $_SERVER['DOCUMENT_URI'] = $__awzScriptName;
        if (isset($_SERVER['ORIG_SCRIPT_NAME'])) {
            $_SERVER['ORIG_SCRIPT_NAME'] = $__awzScriptName;
        }
        if (isset($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '';
        }
        if (isset($_SERVER['PATH_TRANSLATED'])) {
            unset($_SERVER['PATH_TRANSLATED']);
        }
    }
    unset($__awzDocRoot, $__awzTarget, $__awzScriptName);
}

// город не определен по URL - код по умолчанию (в т.ч. когда подмена заблокирована регулярками)
if ($__awzDefault !== '' && !defined('AWZ_D_CURRENT_CITY_CODE')) {
    define('AWZ_D_CURRENT_CITY_CODE', $__awzDefault);
}

unset($__awzRoot, $__awzCfgFile, $__awzCfg, $__awzHost, $__awzS, $__awzH, $__awzEntry,
    $__awzCities, $__awzDefault, $__awzCfgRegexps, $__awzRequestUri, $__awzParsed,
    $__awzPath, $m, $__awzRegexps, $__awzRegexp, $__awzAllowed, $__awzCleanPath,
    $__awzQuery, $__awzNewRequestUri, $__awzDoRewrite, $__awzScriptName);

// физический файл существует - выполняем его и завершаемся (не возвращаемся в urlrewrite.php)
if (isset($__awzIncludeFile)) {
    include $__awzIncludeFile;
    exit;
}
unset($__awzIncludeFile);
