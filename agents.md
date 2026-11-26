# AGENTS.md — awz.domains

Инструкции для ИИ-агентов, работающих с репозиторием модуля `awz.domains`.

## Назначение модуля

SEO-региональность сайта: город определяется по поддомену (`msk.site.ru`) или по сегменту URL (`site.ru/msk/link/`).
Данные города берутся из инфоблока, значения подставляются в контент макросами `#AWZ_S_*#` с модификаторами `~` (`#AWZ_S_PHONE~tel#`).
Настройки отдельные для каждого сайта (Option с site_id) + общие (наследование).
Ролевая модель прав доступа (источник шаблона — awz.acl): действия `settview`, `settedit`, `rightview`, `rightedit`.

Маршрутизация URL-городов: `main.OnPageNotFound` (`App::onPageNotFound`) — если первый сегмент URL-кода валиден
(есть элемент в инфоблоке городов), целевой файл инклюдится без сегмента, включая главную города `/msk/` (→ `/index.php`).
Также `main.OnPageStart` (`App::onPageStart` → `City::rewriteUrlFromRequest()`) и ранняя подмена из `dbconn.php`:
`include/rewrite.php` (без ядра; города — `bitrix/php_interface/dbcon_awz_d_cities_config.php`, генерируется
`Helper::writeUrlIncludeConfig()`; код города — константа `AWZ_D_CURRENT_CITY_CODE`). Конфиг/кеш обновляется при
OnAfterIBlockElementAdd/Update/Delete (`App::refreshCityCacheAndConfig`). Подмена URL ограничивается опцией
`URL_REWRITE_REGEXPS`. Событие `onCollectDefaultCode` — код города по умолчанию из обработчика.

## Структура репозитория

```
bitrix/modules/awz.domains/   исходный код модуля
  lib/Helper.php     настройки (per-site), текущий сайт
  lib/City.php       определение города, кеш, редирект
  lib/Modifier.php   функции-модификаторы макросов
  lib/Macros.php     движок замены макросов, события разработчиков
  lib/App.php        обработчики OnProlog/OnEpilog/OnEndBufferContent
  options.php        страница настроек (вкладка на каждый сайт)
  admin/menu.php     меню админки
  install/           установщик (events, db, version.php)
dist/                сборки (.last_version.zip, update/<ver>.zip, descriptionRU.txt)
docs/install.md      инструкция установки
tools/bxbuildtools.json  параметры сборщика (lang_prefix AWZ_DOMAINS_)
update/              подготовленные обновления
```

`tools/build` и `tools/tests` — git submodules: после clone выполнить `git submodule update --init`.

## Правила работы с кодом

- PHP совместимость: 8.0+ (строгие типы не включены, скалярные подсказки допустимы аккуратно).
- Все языковые строки — только через `Loc::getMessage` и файлы `lang/ru/**`, префикс ключей `AWZ_DOMAINS_`.
- Кодировка модуля — UTF-8.
- Настройки: `Option::set/get` с site_id. Схема: общие настройки — site_id='', настройки сайта — site_id=LID.
  Пустое значение сайта = наследование общего значения (`Option::delete` для сайта при сохранении пустого).
  Для булевых полей на вкладках сайтов — три состояния ('' / Y / N).
  Коды опций — константы в `\Awz\Domains\Helper`; `Helper::get` — с наследованием, `Helper::getRaw` — только значение сайта.
- Многодоменность: опция MAIN_HOST — список доменов через запятую (`MAIN_HOSTS` в getSettings); поддомен ищется относительно каждого; редирект — на совпавший домен.
- Инфоблок городов создается по кнопке в options.php через `\Awz\Domains\Manager::createIblock` (для общих настроек siteId='*', инфоблок на все сайты; для сайта — свой, тип выбирается в форме).
- Кеш города: `City::clearCache($siteId)` ('*' или '' — полная очистка), вызывается при сохранении настроек.
- Доступ в админ-скриптах: `AccessController::isViewSettings()/isEditSettings()/isViewRight()/isEditRight()`.
- Не менять механизм замены классов/суффиксов из `awz.acl` (`awzdomains-user`, `awzdomains-group`, `Awz\Domains\...`).

## Сборка и релизы

- Сборка обновления: скилл `marketplace-update` (gulp up через tools/build).
- Версии: `install/version.php` (VERSION, VERSION_DATE), запись в `CHANGELOG.md`.
- Тест языковых файлов: `python tools/tests/lang.py` (после `git submodule update --init`).

## События для разработчиков (расширение)

- `onCollectMacros` — добавить/переопределить свои макросы (значения разработчика приоритетнее системных).
- `onReplaceContent` — своя замена в финальном контенте (вызывается после системной замены).
- `onCollectModifiers` — добавить свои модификаторы `~`.
