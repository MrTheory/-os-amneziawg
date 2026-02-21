# os-amneziawg

**AmneziaWG VPN plugin for OPNsense**

AmneziaWG — обфусцированный форк WireGuard для обхода DPI-блокировок. Этот плагин добавляет AmneziaWG в OPNsense как нативный VPN-клиент с поддержкой селективной маршрутизации.

---

## Возможности

- Импорт клиентского `.conf` файла одной кнопкой (Parse & Fill)
- Полная поддержка параметров обфускации AmneziaWG (Jc, Jmin, Jmax, S1, S2, H1–H4)
- Управление туннелем через GUI: **VPN → AmneziaWG**
- Совместимость с селективной маршрутизацией OPNsense (Firewall Rules + Gateway)
- Поддержка нескольких туннелей (awg0, awg1, ...)

## Системные требования

| Компонент | Версия |
|---|---|
| OPNsense | 25.x |
| FreeBSD | 14.3-RELEASE amd64 |
| AmneziaWG server | Любая актуальная версия |

> Пакеты `amnezia-kmod` и `amnezia-tools` отсутствуют в репозитории OPNsense, поэтому устанавливаются напрямую из репозитория FreeBSD. Архитектуры ARM и i386 требуют других URL пакетов.

---

## Быстрый старт исключительно в озонокомительных целях.

### 1. Сервер на VPS (Ubuntu/Debian) 

```bash
curl -fsSL https://raw.githubusercontent.com/Toujifushiguro/vps-scripts/main/amneziawg-install.sh \
    -o amneziawg-install.sh
bash amneziawg-install.sh
```

Клиентский конфиг будет в `/etc/amnezia/amneziawg/clients/<name>.conf`.

### 2. Установка плагина на OPNsense

```bash
scp os-amneziawg-v2.tar.gz root@<opnsense-ip>:/tmp/
ssh root@<opnsense-ip>
cd /tmp && tar xzf os-amneziawg-v2.tar.gz && cd os-amneziawg
sh install.sh
```

Скрипт автоматически установит пакеты, загрузит модуль ядра, скопирует файлы плагина, перезапустит configd и очистит кэш.

### 3. Настройка в GUI

1. Обнови браузер (Ctrl+F5) → **VPN → AmneziaWG**
2. Нажми **Import .conf** → вставь конфиг → **Parse & Fill**
3. Очисти поле **DNS** (оставь пустым — Unbound справится)
4. **Save** → **Apply**
5. Проверь туннель:

```bash
awg show
# Должен показать: latest handshake: N seconds ago
```

### 4. Интерфейс и шлюз

| Шаг | Путь в GUI |
|---|---|
| Назначить интерфейс | Interfaces → Assignments → awg0 → + |
| Настроить интерфейс | Interfaces → AWG_VPN → Enable, IPv4: None |
| Создать шлюз | System → Gateways → + (IP: 10.8.1.1, Far Gateway ✅, Monitoring off ✅) |

### 5. MSS Clamping (обязательно!)

Без этого видео и тяжёлые страницы работать не будут.

**Firewall → Settings → Normalization → +**

| Поле | Значение |
|---|---|
| Interface | AWG_VPN |
| Protocol | TCP |
| Max MSS | 1380 |

### 6. Селективная маршрутизация

- **Firewall → Aliases** — создай список IP/сетей
- **Firewall → Rules → LAN** — правило: Source=LAN net, Destination=alias, Gateway=AWG_GW

---

## Устранение неполадок

**Туннель не поднимается**
```bash
php /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php reconfigure
kldstat | grep amn
```

**Меню не появляется**
```bash
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
# Затем Ctrl+F5
```

**Частичная работа (сайты открываются, видео не грузит)** — проверь MSS Clamping (шаг 5).

**Ошибки PHP**
```bash
cat /var/lib/php/tmp/PHP_errors.log
configctl amneziawg status
```

---

## Структура файлов

```
plugin/
├── scripts/AmneziaWG/amneziawg-service-control.php   # Основной скрипт сервиса
├── service/conf/actions.d/actions_amneziawg.conf      # Команды configd
├── etc/inc/plugins.inc.d/amneziawg.inc                # Регистрация сервиса
└── mvc/app/
    ├── models/OPNsense/AmneziaWG/
    │   ├── General.xml / General.php                  # Модель общих настроек
    │   ├── Instance.xml / Instance.php                # Модель туннелей (все AWG параметры)
    │   └── Menu/Menu.xml                              # Пункт меню VPN → AmneziaWG
    ├── controllers/OPNsense/AmneziaWG/
    │   ├── IndexController.php                        # Рендеринг страницы
    │   ├── Api/GeneralController.php                  # API: общие настройки
    │   ├── Api/InstanceController.php                 # API: CRUD + генерация ключей
    │   ├── Api/ServiceController.php                  # API: start/stop/reconfigure
    │   ├── Api/ImportController.php                   # API: парсинг .conf
    │   ├── forms/general.xml                          # Форма общих настроек
    │   └── forms/dialogEditInstance.xml               # Форма редактирования туннеля
    └── views/OPNsense/AmneziaWG/general.volt          # Шаблон GUI
```
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
