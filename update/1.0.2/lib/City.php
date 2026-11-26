<?php
namespace Awz\Domains;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

Loc::loadMessages(__FILE__);

class City
{
    /** @var City|null */
    private static $instance = null;

    /** @var array|null текущий город */
    private $city = null;
    /** @var string код текущего города */
    private $code = '';
    /** @var string поддомен, если найден */
    private $subDomain = '';
    /** @var string основной домен, к которому относится текущий хост (многодоменность) */
    private $mainHost = '';
    /** @var array|null настройки сайта */
    private $settings = null;
    /** @var string код города, извлеченный из URL до подмены */
    private $preUrlCode = '';
    /** @var bool подмена URL выполнена */
    private $urlRewritten = false;

    public static function getInstance(): City
    {
        if (is_null(self::$instance)) {
            self::$instance = new City();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Инициализация: определить город, загрузить данные, редирект при необходимости.
     * Вызывается один раз из OnProlog.
     */
    public function init(): void
    {
        $siteId = Helper::getSiteId();
        $this->settings = Helper::getSettings($siteId);
        if (!$this->settings['ENABLED'] || $this->settings['IBLOCK_ID'] <= 0) {
            return;
        }

        $context = Context::getCurrent();
        $request = $context->getRequest();
        if ($request->isAdminSection()) {
            return;
        }

        $host = strtolower(trim($context->getServer()->getHttpHost()));
        $host = preg_replace('~:\d+~', '', $host);

        $code = '';
        $subDomain = '';
        $this->mainHost = '';

        if (in_array($this->settings['MODE'], ['sub', 'both'], true)) {
            [$subDomain, $isSub, $matchedHost] = $this->detectSubDomain($host);
            if ($isSub) {
                $this->mainHost = $matchedHost;
            }
            if ($isSub && $subDomain !== '' && $subDomain !== 'www') {
                $code = $subDomain;
            }
        }
        if ($code === '' && in_array($this->settings['MODE'], ['url', 'both'], true)) {
            $code = $this->preUrlCode !== ''
                ? $this->preUrlCode
                : $this->detectUrlCode($request->getRequestedPage());
        }

        if ($code === '' && $this->settings['DEFAULT_CODE'] !== '') {
            $code = $this->settings['DEFAULT_CODE'];
        }

        // обработчик для разработчиков: свой код города по умолчанию
        if ($code === '') {
            $code = $this->getDefaultCodeEvent($siteId, $host, $subDomain);
        }

        $this->code = $code;
        $this->subDomain = $subDomain;

        if ($code === '') {
            return;
        }

        $this->city = $this->loadCity($code);

        // код региона может задаваться как полный домен (msk.site.ru), так и как часть (msk)
        if (is_null($this->city) && $subDomain !== '' && $subDomain !== 'www' && $code === $subDomain) {
            $this->city = $this->loadCity($host);
            if (!is_null($this->city)) {
                $this->code = $host;
            }
        }

        if (is_null($this->city) && $subDomain !== '' && $subDomain !== 'www'
            && $this->settings['REDIRECT_UNK'] && $this->mainHost !== '') {
            $this->redirectMain();
        }
    }

    /**
     * Код города по умолчанию из обработчика разработчика (onCollectDefaultCode).
     * Результат: строка с кодом или ['code' => 'msk'] через EventResult::SUCCESS.
     * Пустой результат - код не задан.
     */
    private function getDefaultCodeEvent(string $siteId, string $host, string $subDomain): string
    {
        $event = new Event(Helper::MODULE_ID, 'onCollectDefaultCode', [
            'siteId' => $siteId,
            'host' => $host,
            'subDomain' => $subDomain,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() == EventResult::SUCCESS) {
                $params = $result->getParameters();
                $code = '';
                if (is_string($params)) {
                    $code = $params;
                } elseif (is_array($params)) {
                    $code = (string)($params['code'] ?? reset($params));
                }
                $code = trim((string)$code);
                if ($code !== '') {
                    return $code;
                }
            }
        }
        return '';
    }

    /**
     * Подмена фактического URL: сегмент города в начале пути
     * (/msk/link/ -> /link/) вырезается до роутинга и urlrewrite.
     * Вызывается из OnPageStart. Код города запоминается для init().
     */
    public function rewriteUrlFromRequest(): void
    {
        if ($this->urlRewritten) {
            return;
        }
        $this->urlRewritten = true;

        if (is_null($this->settings)) {
            $this->settings = Helper::getSettings(Helper::getSiteId());
        }

        if (!$this->settings['ENABLED']
            || !in_array($this->settings['MODE'], ['url', 'both'], true)) {
            return;
        }

        $context = Context::getCurrent();
        $request = $context->getRequest();
        if ($request->isAdminSection()) {
            return;
        }

        $path = parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return;
        }
        $parts = explode('/', trim($path, '/'));
        $code = strtolower(trim((string)reset($parts)));
        if ($code === '' || !preg_match('~^[a-zA-Z0-9_\-]{1,64}$~', $code)) {
            return;
        }

        // подменяем только если такой город реально есть в инфоблоке
        if (is_null($this->loadCity($code))) {
            return;
        }

        array_shift($parts);
        $newPath = '/' . implode('/', $parts);
        if ($newPath === '//' || trim($newPath, '/') === '') {
            $newPath = '/';
        }

        // опция: подменять только URL, подходящие под регулярки (одна в строке);
        // пустой список - подменяются все
        $regexps = (array)($this->settings['URL_REWRITE_REGEXPS'] ?? []);
        if (!empty($regexps)) {
            $allowed = false;
            foreach ($regexps as $regexp) {
                if (@preg_match('~' . str_replace('~', '\~', trim($regexp)) . '~i', $path)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return;
            }
        }

        // обработчик для разработчиков: разрешить/запретить подмену, подменить код города
        $event = new Event(Helper::MODULE_ID, 'onCheckRewriteCityUrl', [
            'siteId' => $this->settings['SITE_ID'],
            'code' => $code,
            'path' => $path,
            'newPath' => $newPath,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() == EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (is_array($params)) {
                    if (array_key_exists('rewrite', $params) && $params['rewrite'] !== true) {
                        return;
                    }
                    if (isset($params['code']) && is_string($params['code']) && $params['code'] !== '') {
                        $code = strtolower(trim($params['code']));
                    }
                }
            }
        }

        $hasTrailingSlash = substr($newPath, -1) === '/';
        array_shift($parts);
        $newPath = '/' . implode('/', $parts);
        if ($newPath !== '/' && $hasTrailingSlash) {
            $newPath .= '/';
        }

        $uri = new Uri($request->getRequestUri());
        $uri->setPath($newPath);
        $newRequestUri = $uri->getUri();

        // суперглобальные для старого ядра и urlrewrite
        $_SERVER['REQUEST_URI'] = $newRequestUri;
        $_SERVER['SCRIPT_URL'] = $newPath;

        // новое ядро: пересобираем Request и переинициализируем контекст
        $server = $context->getServer();
        $serverArray = $server->toArray();
        $serverArray['REQUEST_URI'] = $newRequestUri;
        $server->set($serverArray);



        $newRequest = new \Bitrix\Main\HttpRequest(
            $server,
            $request->getQueryList()->toArray(),
            $request->getPostList()->toArray(),
            $request->getFileList()->toArray(),
            $request->getCookieList()->toArray()
        );
        if ($request instanceof \Psr\Http\Message\RequestInterface && method_exists($newRequest, 'withUri')) {
            $newRequest = $newRequest->withUri($uri);
        }
        $context->initialize(
            $newRequest,
            $context->getResponse(),
            $server,
            ['env' => $context->getEnvironment()]
        );

        $this->preUrlCode = $code;

        // 1. Проверяем, не открыт ли уже целевой файл
        $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $targetFile = null;

        if (substr($newPath, -1) === '/') {
            if (is_file($documentRoot . $newPath . 'index.php')) {
                $targetFile = $documentRoot . $newPath . 'index.php';
            }
        } elseif (is_file($documentRoot . $newPath)) {
            $targetFile = $documentRoot . $newPath;
        } elseif (is_file($documentRoot . $newPath . '/index.php')) {
            $targetFile = $documentRoot . $newPath . '/index.php';
        }

// 2. Если мы сейчас внутри urlrewrite.php (или 404) и целевой физический файл существует — подключаем его!
        if ($targetFile !== null && realpath($_SERVER['SCRIPT_FILENAME']) !== realpath($targetFile)) {
            // Сбрасываем статус 404, если веб-сервер/битрикс его уже выставил
            \CHTTP::SetStatus('200 OK');
            if (!defined('ERROR_404')) {
                // Не определяем ERROR_404
            }

            $_SERVER['SCRIPT_FILENAME'] = $targetFile;
            $_SERVER['SCRIPT_NAME'] = str_replace($documentRoot, '', $targetFile);
            $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];

            // Переинициализируем Request ещё раз, чтобы SCRIPT_NAME обновился
            $server = $context->getServer();
            $serverArray = $server->toArray();
            $serverArray['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_FILENAME'];
            $serverArray['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'];
            $serverArray['PHP_SELF'] = $_SERVER['PHP_SELF'];
            $server->set($serverArray);

            $newRequest = new \Bitrix\Main\HttpRequest(
                $server,
                $request->getQueryList()->toArray(),
                $request->getPostList()->toArray(),
                $request->getFileList()->toArray(),
                $request->getCookieList()->toArray()
            );
            if ($request instanceof \Psr\Http\Message\RequestInterface && method_exists($newRequest, 'withUri')) {
                $newRequest = $newRequest->withUri($uri);
            }
            $context->initialize(
                $newRequest,
                $context->getResponse(),
                $server,
                ['env' => $context->getEnvironment()]
            );

            // Подключаем реальный файл страницы и завершаем работу urlrewrite.php
            include $targetFile;
            die();
        }

    }

    /**
     * Код города из поддомена. Поддержка многодоменности:
     * основной хост — список доменов, поддомен ищется относительно каждого.
     * @return array [subDomain, found, matchedHost]
     */
    private function detectSubDomain(string $host): array
    {
        foreach ($this->settings['MAIN_HOSTS'] as $mainHost) {
            if ($mainHost === '' || $host === $mainHost) {
                continue;
            }
            if ($host === 'www.' . $mainHost) {
                return ['', false, $mainHost];
            }
            if (mb_strlen($host) > mb_strlen($mainHost) + 1
                && mb_substr($host, -(mb_strlen($mainHost) + 1)) === '.' . $mainHost) {
                $prefix = mb_substr($host, 0, mb_strlen($host) - mb_strlen($mainHost) - 1);
                $parts = explode('.', $prefix);
                return [trim((string)reset($parts)), true, $mainHost];
            }
        }
        return ['', false, ''];
    }

    /**
     * Код города из сегмента URL: site.ru/{town}/link/
     */
    private function detectUrlCode(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $parts = explode('/', trim($path, '/'));
        $code = trim((string)reset($parts));
        if ($code === '' || !preg_match('~^[a-zA-Z0-9_\-]{1,64}$~', $code)) {
            return '';
        }
        return strtolower($code);
    }

    /**
     * Данные города из инфоблока с кешированием
     */
    public function loadCity(string $code): ?array
    {
        $settings = $this->settings;
        $cacheId = md5(
            'city|' . $settings['SITE_ID'] . '|' . $settings['IBLOCK_ID'] . '|'
            . implode(',', $settings['PROPS']) . '|' . $code
        );
        $cacheDir = '/awz_domains/' . $settings['SITE_ID'] . '_' . $settings['IBLOCK_ID'];
        $obCache = Cache::createInstance();
        if ($obCache->initCache($settings['CACHE_TIME'], $cacheId, $cacheDir)) {
            $vars = $obCache->getVars();
            return is_array($vars) ? $vars : null;
        }
        if ($obCache->startDataCache()) {
            $data = null;
            if (Loader::includeModule('iblock')) {
                $select = array_merge(['ID', 'NAME', 'IBLOCK_ID', 'CODE'], $settings['PROPS']);
                $dbRes = \CIBlockElement::GetList(
                    [],
                    [
                        '=CODE' => $code,
                        'IBLOCK_ID' => $settings['IBLOCK_ID'],
                        'ACTIVE' => 'Y',
                    ],
                    false,
                    false,
                    $select
                );
                if ($row = $dbRes->Fetch()) {
                    $data = [
                        'ID' => (int)$row['ID'],
                        'NAME' => (string)$row['NAME'],
                        'CODE' => (string)$row['CODE'],
                        'IBLOCK_ID' => (int)$row['IBLOCK_ID'],
                    ];
                    foreach ($settings['PROPS'] as $p) {
                        $val = isset($row['PROPERTY_' . $p . '_VALUE']) ? $row['PROPERTY_' . $p . '_VALUE'] : '';
                        if (is_array($val)) {
                            $val = implode(', ', $val);
                        }
                        $data[$p] = (string)$val;
                        if (isset($row['PROPERTY_' . $p . '_DESCRIPTION'])) {
                            $data[$p . '_DESCR'] = (string)$row['PROPERTY_' . $p . '_DESCRIPTION'];
                        }
                    }
                }
            }
            $obCache->endDataCache($data);
            return $data;
        }
        return null;
    }

    /**
     * Очистка кеша города (при сохранении настроек).
     * '*' или '' - полная очистка кеша модуля.
     */
    public static function clearCache(string $siteId): void
    {
        if ($siteId === '*' || $siteId === '') {
            Cache::createInstance()->cleanDir('/awz_domains');
            return;
        }
        $settings = Helper::getSettings($siteId);
        $cacheDir = '/awz_domains/' . $settings['SITE_ID'] . '_' . $settings['IBLOCK_ID'];
        Cache::createInstance()->cleanAll($cacheDir);
    }

    private function redirectMain(): void
    {
        $context = Context::getCurrent();
        $url = 'https://' . $this->mainHost . '/';
        $redirect = $context->getResponse()->redirectTo($url)
            ->setSkipSecurity(true)
            ->setStatus($this->settings['REDIRECT_STATUS'] . ' Found');
        Application::getInstance()->end(0, $redirect);
    }

    /**
     * Код текущего города
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Данные текущего города (null если нет)
     */
    public function getCity(): ?array
    {
        return $this->city;
    }

    /**
     * Значение свойства города: get('PHONE') / get('NAME') / get('CODE')
     */
    public function get(string $code): string
    {
        if (is_null($this->city)) {
            return '';
        }
        $code = strtoupper(trim($code));
        if (isset($this->city[$code])) {
            return (string)$this->city[$code];
        }
        return '';
    }

    /**
     * Все доступные значения города для макросов
     */
    public function getValues(): array
    {
        if (is_null($this->city)) {
            return [];
        }
        $res = [];
        foreach ($this->city as $k => $v) {
            if (is_scalar($v)) {
                $res[strtoupper($k)] = (string)$v;
            }
        }
        return $res;
    }

    /**
     * Коды свойств инфоблока (тип "привязка к элементам"),
     * ссылающихся на инфоблок городов. Кеш - 24 часа.
     */
    public function getCityLinkProps(int $iblockId): array
    {
        $iblockId = (int)$iblockId;
        if ($iblockId <= 0) {
            return [];
        }
        if (is_null($this->settings)) {
            $this->settings = Helper::getSettings(Helper::getSiteId());
        }
        $citiesIblockId = (int)($this->settings['IBLOCK_ID'] ?? 0);
        if ($citiesIblockId <= 0) {
            return [];
        }
        $cacheId = 'city_link_props|' . $iblockId . '|' . $citiesIblockId;
        $cacheDir = '/awz_domains/link_props';
        $obCache = Cache::createInstance();
        if ($obCache->initCache(86400, $cacheId, $cacheDir)) {
            $vars = $obCache->getVars();
            return is_array($vars) ? $vars : [];
        }
        if ($obCache->startDataCache()) {
            $props = [];
            if (Loader::includeModule('iblock')) {
                $dbRes = \CIBlockProperty::GetList(
                    ['SORT' => 'ASC', 'ID' => 'ASC'],
                    [
                        'IBLOCK_ID' => $iblockId,
                        'PROPERTY_TYPE' => 'E',
                        'LINK_IBLOCK_ID' => $citiesIblockId,
                        'ACTIVE' => 'Y',
                        'CHECK_PERMISSIONS' => 'N',
                    ]
                );
                while ($row = $dbRes->Fetch()) {
                    $code = trim((string)$row['CODE']);
                    if ($code !== '') {
                        $props[] = $code;
                    }
                }
            }
            $obCache->endDataCache($props);
            return $props;
        }
        return [];
    }

    /**
     * Добавление фильтра по текущему городу в массив фильтра инфоблока.
     * Ищет свойства-привязки к элементам инфоблока городов и фильтрует
     * по ID текущего города. Если города нет - фильтр не меняется.
     *
     * $filter['IBLOCK_ID'] обязателен, остальные ключи сохраняются.
     * Пример: City::getInstance()->applyRegionFilter($arFilter);
     */
    public function applyRegionFilter(array &$filter): void
    {
        if (is_null($this->city) || is_null($this->settings)) {
            return;
        }
        $iblockId = (int)($filter['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0) {
            return;
        }
        $cityId = (int)($this->city['ID'] ?? 0);
        if ($cityId <= 0) {
            return;
        }
        $props = $this->getCityLinkProps($iblockId);
        if (empty($props)) {
            return;
        }
        $conditions = [];
        foreach ($props as $code) {
            $conditions['=PROPERTY_' . $code] = $cityId;
        }
        if (count($conditions) === 1) {
            $filter += $conditions;
        } else {
            $or = ['LOGIC' => 'OR'];
            foreach ($conditions as $k => $v) {
                $or[$k] = $v;
            }
            $filter[] = $or;
        }
    }
}
