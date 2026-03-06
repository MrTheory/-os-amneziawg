# os-amneziawg

**AmneziaWG VPN plugin for OPNsense** — v2.2.0

AmneziaWG — обфусцированный форк WireGuard для обхода DPI-блокировок. Этот плагин добавляет AmneziaWG в OPNsense как нативный VPN-клиент с поддержкой селективной маршрутизации.

---

## Возможности

- Импорт клиентского `.conf` файла одной кнопкой — **Parse & Fill**
- Полная поддержка параметров обфускации AmneziaWG (Jc, Jmin, Jmax, S1, S2, H1–H4)
- Генерация keypair прямо в GUI — публичный ключ отображается для передачи администратору сервера
- Приватный ключ хранится в защищённом файле `private.key` (0600) — не попадает в бэкапы конфига
- Управление туннелем через GUI: **VPN → AmneziaWG** — кнопки Start/Stop/Restart
- Автозапуск туннеля при перезагрузке OPNsense (rc.syshook)
- Статус туннеля через `awg show` в реальном времени
- Валидация всех полей: CIDR, host:port, Base64 ключи
- Совместимость с селективной маршрутизацией OPNsense (Firewall Rules + Gateway)
- Корректное отображение статуса сервиса в дашборде OPNsense
- Журнал операций `/var/log/amneziawg.log` с автоматической ротацией
- ACL-контроль доступа к API через `System → Access → Groups`

---

## Системные требования

| Компонент | Версия |
|---|---|
| OPNsense | 25.x / 26.x |
| FreeBSD | 14.x amd64 |
| AmneziaWG server | Любая актуальная версия |

> Пакеты `amnezia-kmod` и `amnezia-tools` отсутствуют в репозитории OPNsense. Установщик автоматически подключает FreeBSD quarterly repo и ставит их через `pkg install` (с подтверждением). Архитектуры ARM и i386 могут потребовать ручной установки.

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
scp os-amneziawg/ root@<opnsense-ip>:/tmp/os-amneziawg
ssh root@<opnsense-ip>
cd /tmp/os-amneziawg
sh install.sh
```

Скрипт автоматически:
- проверит наличие `awg` и модуля ядра `if_amn`
- предложит установить недостающие пакеты из FreeBSD quarterly repo (`[Y/n]`)
- создаст временный repo-конфиг, установит пакеты, удалит конфиг
- загрузит модуль ядра и пропишет его в `/boot/loader.conf`
- скопирует файлы плагина
- установит конфиг ротации лога newsyslog
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

### 7. Outbound NAT (обязательно!)

Без этого трафик через туннель не будет NATиться и не уйдёт дальше VPN-сервера.

**Firewall → NAT → Outbound**

1. Переключи режим на **Hybrid outbound NAT rule generation** (если ещё не переключён)
2. Добавь правило **+**:

| Поле | Значение |
|---|---|
| Interface | AWG (awg) |
| TCP/IP Version | IPv4 |
| Protocol | any |
| Source address | LAN net |
| Source port | any |
| Destination address | any |
| Destination port | any |
| Translation / target | Interface address |

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

Если `General → Enable AmneziaWG` включён, сервис должен стартовать автоматически. Если нет — проверь лог и нажми Start:
```bash
tail /var/log/amneziawg.log
configctl amneziawg start
```

**Меню VPN → AmneziaWG не появляется**
```bash
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5
```

**Частичная работа (сайты открываются, видео не грузит)**

Проверь MSS Clamping (шаг 5). Попробуй уменьшить Max MSS до 1360 или 1280.

**Кнопка Apply / Start / Stop не работает ("No response from configd")**

1. Перезапусти configd: `service configd restart`
2. Проверь lock: `cat /var/run/amneziawg.lock` — если PID зависшего процесса, убей его:
```bash
kill -9 $(cat /var/run/amneziawg.lock) 2>/dev/null
rm -f /var/run/amneziawg.lock
service configd restart
```
3. Проверь лог: `tail -20 /var/log/amneziawg.log`

**Ошибки PHP**
```bash
cat /var/lib/php/tmp/PHP_errors.log
configctl amneziawg status
```

**Удаление плагина**
```bash
sh install.sh uninstall
```

> При удалении директория `/usr/local/etc/amnezia/` (включая `private.key` и `.conf` файлы) удаляется автоматически.

---

## Структура файлов

```
plugin/
├── scripts/AmneziaWG/
│   └── amneziawg-service-control.php     # Управление туннелем: start/stop/reconfigure/status/gen_keypair
├── service/conf/actions.d/
│   └── actions_amneziawg.conf            # Команды configd
├── etc/
│   ├── inc/plugins.inc.d/
│   │   └── amneziawg.inc                 # Регистрация сервиса в OPNsense
│   └── newsyslog.conf.d/
│       └── amneziawg.conf                # Ротация лога (1MB / daily, 5 архивов, gzip)
└── mvc/app/
    ├── models/OPNsense/AmneziaWG/
    │   ├── General.xml / General.php      # Модель: флаг enabled
    │   ├── Instance.xml / Instance.php    # Модель: все параметры туннеля (плоская)
    │   ├── ACL/ACL.xml                    # ACL: права доступа к API и UI
    │   └── Menu/Menu.xml                  # Пункт меню VPN → AmneziaWG
    ├── controllers/OPNsense/AmneziaWG/
    │   ├── IndexController.php            # Рендеринг страницы
    │   ├── Api/GeneralController.php      # API: get/set general.enabled
    │   ├── Api/InstanceController.php     # API: get/set + genKeyPair (SEC-1/SEC-2)
    │   ├── Api/ServiceController.php      # API: reconfigure/start/stop/restart/tunnelStatus
    │   ├── Api/ImportController.php       # API: парсинг .conf файла (POST only)
    │   └── forms/
    │       ├── general.xml                # Форма общих настроек
    │       └── instance.xml              # Форма параметров туннеля
    └── views/OPNsense/AmneziaWG/
        └── general.volt                   # Шаблон GUI (вкладки Instance + General)
```

**Файлы на OPNsense после установки:**
```
/usr/local/etc/amnezia/private.key          (0600) — приватный ключ (не в бэкапах)
/usr/local/etc/amnezia/awg0.conf            (0600) — конфиг туннеля
/var/run/amneziawg.pid                             — PID файл статуса
/var/run/amneziawg.lock                            — lock файл от параллельных запусков
/var/log/amneziawg.log                             — лог операций
```

---

## Архитектурные решения

**Table = off** — `awg-quick` не трогает таблицу маршрутизации. Маршрутами управляет OPNsense через Firewall Rules + Gateway. Это позволяет реализовать селективную маршрутизацию идентично Xray/WireGuard плагинам.

**Плоская модель** — один туннель, одна модель без ArrayField. XML-путь: `//OPNsense/amneziawg/instance`. Подходит для большинства сценариев использования AmneziaWG как клиента.

**Sentinel private.key** — в `config.xml` хранится строка `::file::` вместо ключа. Реальный ключ в `private.key` (0600). `InstanceController` перехватывает `get`/`set`/`genKeyPair` и управляет файлом напрямую. GUI показывает bullet-плейсхолдер.

**Конфиг туннеля** записывается в `/usr/local/etc/amnezia/awg0.conf` (права 0600) при каждом Apply.

**PID файл** `/var/run/amneziawg.pid` создаётся после успешного `awg-quick up` и удаляется при `stop` — обеспечивает корректный статус в дашборде OPNsense.

**flock защита** — параллельные вызовы `reconfigure` (от configd и ручного запуска) не конкурируют: второй вызов немедленно возвращает `OK` и выходит.

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
Февраль–Март 2026

## Благодарности

- [AmneziaVPN](https://github.com/amnezia-vpn) — за разработку AmneziaWG
- [OPNsense](https://opnsense.org) — за открытую архитектуру плагинов
- [Toujifushiguro](https://github.com/Toujifushiguro) — за скрипт установки сервера
