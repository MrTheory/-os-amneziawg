# os-amneziawg

**AmneziaWG VPN plugin for OPNsense**

AmneziaWG — обфусцированный форк WireGuard для обхода DPI-блокировок. Этот плагин добавляет AmneziaWG в OPNsense как нативный VPN-клиент с поддержкой селективной маршрутизации.

---

## Возможности

- Импорт клиентского `.conf` файла одной кнопкой — **Parse & Fill**
- Полная поддержка параметров обфускации AmneziaWG (Jc, Jmin, Jmax, S1, S2, H1–H4)
- Генерация keypair прямо в GUI
- Управление туннелем через GUI: **VPN → AmneziaWG**
- Статус туннеля через `awg show` в реальном времени
- Совместимость с селективной маршрутизацией OPNsense (Firewall Rules + Gateway)
- Корректное отображение статуса сервиса в дашборде OPNsense
- Журнал операций `/var/log/amneziawg.log`

---

## Системные требования

| Компонент | Версия |
|---|---|
| OPNsense | 25.x / 26.x |
| FreeBSD | 14.x amd64 |
| AmneziaWG server | Любая актуальная версия |

> Пакеты `amnezia-kmod` и `amnezia-tools` отсутствуют в репозитории OPNsense и устанавливаются напрямую из репозитория FreeBSD. Архитектуры ARM и i386 требуют других URL пакетов.

---

## Быстрый старт

> ⚠️ Только в ознакомительных целях.

### 1. Сервер на VPS (Ubuntu/Debian)

```bash
curl -fsSL https://raw.githubusercontent.com/Toujifushiguro/vps-scripts/main/amneziawg-install.sh \
    -o amneziawg-install.sh
bash amneziawg-install.sh
```

Клиентский конфиг будет в `/etc/amnezia/amneziawg/clients/<n>.conf`.

### 2. Установка плагина на OPNsense

```bash
scp os-amneziawg-v4.tar.gz root@<opnsense-ip>:/tmp/
ssh root@<opnsense-ip>
pkg install bash
cd /tmp && tar xzf os-amneziawg-v4.tar.gz
sh install.sh
```

Скрипт автоматически:
- установит пакеты `amnezia-kmod` и `amnezia-tools`
- загрузит модуль ядра `if_amn` и пропишет его в `/boot/loader.conf`
- скопирует файлы плагина
- перезапустит configd и очистит кэш

### 3. Настройка в GUI

1. Обнови браузер (Ctrl+F5) → **VPN → AmneziaWG**
2. Нажми **Import .conf** → вставь конфиг → **Parse & Fill**
3. Поле **DNS** — оставь пустым, если используешь Unbound (рекомендуется)
4. Перейди на вкладку **General** → поставь галочку **Enable AmneziaWG**
5. Нажми **Apply**
6. Проверь туннель:

```bash
awg show
# Должен показать: latest handshake: N seconds ago
```

### 4. Интерфейс и шлюз

| Шаг | Путь в GUI |
|---|---|
| Назначить интерфейс | Interfaces → Assignments → awg0 → **+** |
| Настроить интерфейс | Interfaces → AWG_VPN → Enable, IPv4: None |
| Создать шлюз | System → Gateways → **+** |

Параметры шлюза:
- **Interface:** AWG_VPN
- **Gateway IP:** IP пира из туннеля (например `10.8.1.1`)
- **Far Gateway:** ✅
- **Disable Monitoring:** ✅

### 5. MSS Clamping (обязательно!)

Без этого видео и тяжёлые страницы могут не работать.

**Firewall → Settings → Normalization → +**

| Поле | Значение |
|---|---|
| Interface | AWG_VPN |
| Protocol | TCP |
| Max MSS | 1380 |

### 6. Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей для маршрутизации через VPN
- **Firewall → Rules → LAN** — добавь правило:
  - Source: `LAN net`
  - Destination: созданный alias
  - Gateway: `AWG_GW`

---

## Устранение неполадок

**Туннель не поднимается**
```bash
# Запустить вручную и посмотреть вывод
php /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php reconfigure

# Проверить загрузку модуля ядра
kldstat | grep amn

# Посмотреть лог операций плагина
cat /var/log/amneziawg.log
```

**Сервис отображается как "stopped" в дашборде после перезагрузки**

Это нормально — при перезагрузке OPNsense сервис не стартует автоматически. Нажми Apply в GUI или:
```bash
configctl amneziawg start
```

**Меню VPN → AmneziaWG не появляется**
```bash
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5
```

**Частичная работа (сайты открываются, видео не грузит)**

Проверь MSS Clamping (шаг 5). Попробуй уменьшить Max MSS до 1360 или 1280.

**Кнопка Apply долго крутится**

Таймаут configd для операций с туннелем — 60 секунд. Это нормально при медленном подключении к endpoint. Если Apply всё равно возвращает ошибку:
```bash
php /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php reconfigure
```

**Ошибки PHP**
```bash
cat /var/lib/php/tmp/PHP_errors.log
configctl amneziawg status
```

**Удаление плагина**
```bash
sh install.sh uninstall
```

---

## Структура файлов

```
plugin/
├── scripts/AmneziaWG/
│   └── amneziawg-service-control.php     # Управление туннелем: start/stop/reconfigure/status/gen_keypair
├── service/conf/actions.d/
│   └── actions_amneziawg.conf            # Команды configd (timeout: 60s)
├── etc/inc/plugins.inc.d/
│   └── amneziawg.inc                     # Регистрация сервиса в OPNsense
└── mvc/app/
    ├── models/OPNsense/AmneziaWG/
    │   ├── General.xml / General.php      # Модель: флаг enabled
    │   ├── Instance.xml / Instance.php    # Модель: все параметры туннеля (плоская)
    │   └── Menu/Menu.xml                  # Пункт меню VPN → AmneziaWG
    ├── controllers/OPNsense/AmneziaWG/
    │   ├── IndexController.php            # Рендеринг страницы
    │   ├── Api/GeneralController.php      # API: get/set general.enabled
    │   ├── Api/InstanceController.php     # API: get/set + genKeyPair
    │   ├── Api/ServiceController.php      # API: reconfigure/status
    │   ├── Api/ImportController.php       # API: парсинг .conf файла
    │   └── forms/general.xml             # Форма общих настроек
    │   └── forms/instance.xml            # Форма параметров туннеля
    └── views/OPNsense/AmneziaWG/
        └── general.volt                   # Шаблон GUI (вкладки Instance + General)
```

---

## Архитектурные решения

**Table = off** — `awg-quick` не трогает таблицу маршрутизации. Маршрутами управляет OPNsense через Firewall Rules + Gateway. Это позволяет реализовать селективную маршрутизацию идентично Xray/WireGuard плагинам.

**Плоская модель** — один туннель, одна модель без ArrayField. XML-путь: `//OPNsense/amneziawg/instance`. Подходит для большинства сценариев использования AmneziaWG как клиента.

**Конфиг туннеля** записывается в `/usr/local/etc/amnezia/awg0.conf` (права 0600) при каждом Apply.

**PID файл** `/var/run/amneziawg.pid` создаётся после успешного `awg-quick up` и удаляется при `stop` — обеспечивает корректный статус в дашборде OPNsense.

---

## Лицензия

BSD 2-Clause License

Copyright (c) 2026 Merkulov Pavel Sergeevich (Меркулов Павел Сергеевич)

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.

---

## Автор

**Меркулов Павел Сергеевич**
Февраль 2026

## Благодарности

- [AmneziaVPN](https://github.com/amnezia-vpn) — за разработку AmneziaWG
- [OPNsense](https://opnsense.org) — за открытую архитектуру плагинов
- [Toujifushiguro](https://github.com/Toujifushiguro) — за скрипт установки сервера
