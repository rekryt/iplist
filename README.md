### IP Address Collection and Management Service for Mikrotik RouterOS Script, JSON or Text Format 
For english readme: [README.en.md](README.en.md)

Demo URL: [https://iplist.opencck.org](https://iplist.opencck.org)

![iplist](https://github.com/user-attachments/assets/2e363fd8-1df7-4554-bf9e-98f58c13df96)

# Сервис сбора IP-адресов и CIDR зон
Данный сервис предназначен для сбора и обновления IP-адресов (IPv4 и IPv6), а также их CIDR зон для указанных доменов.
Это асинхронный PHP веб-сервер на основе [AMPHP](https://amphp.org/) и Linux-утилит `whois` и `ipcalc`.
Сервис предоставляет интерфейсы для получения списков зон ip адресов указанных доменов (IPv4 адресов, IPv6 адресов, а также CIDRv4 и CIDRv6 зон) в различных форматах, включая текстовый, JSON и формате скрипта для добавления в "Address List" на роутерах Mikrotik (RouterOS).

Основные возможности
- Мониторинг доменов: Сбор и обновление IP-адресов и CIDR зон для указанных доменов.
- Многоформатный вывод: Поддержка вывода данных в текстовом формате, формате JSON и в виде скрипта для RouterOS Mikrotik.
- Интеграция с внешними источниками данных: поддержка импорта начальных данных из внешних URL.
- Легкое развертывание с помощью Docker Compose.
- Настройка через JSON файлы для управления доменами и IP.

Используемые технологии
- PHP 8.1+ (amphp, revolt)
- whois, ipcalc (linux)

## Настройки
Конфигурационные файлы хранятся в директории `config`. Каждый JSON файл представляет собой конфигурацию для конкретного портала, задавая домены для мониторинга и источники начальных данных по IP и CIDR.
```json
{
    "domains": [
        "youtube.com",
        "www.youtube.com",
        "m.youtube.com",
        "www.m.youtube.com",
        "googlevideo.com",
        "www.googlevideo.com",
        "ytimg.com",
        "i.ytimg.com"
    ],
    "dns": ["127.0.0.11:53", "77.88.8.88:53", "8.8.8.8:53"],
    "timeout": 43200,
    "ip4": [],
    "ip6": [],
    "cidr4": [],
    "cidr6": [],
    "external": {
        "domains": ["https://raw.githubusercontent.com/nickspaargaren/no-google/master/categories/youtubeparsed"],
        "ip4": ["https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/ipv4_list.txt"],
        "ip6": ["https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/ipv6_list.txt"],
        "cidr4": ["https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/cidr4.txt"],
        "cidr6": ["https://raw.githubusercontent.com/touhidurrr/iplist-youtube/main/cidr6.txt"]
    }
}
```
| свойство | тип      | описание                                                                                                                                                                                                             |
|----------|----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| domains  | string[] | Список доменов портала                                                                                                                                                                                               |
| dns      | string[] | Список DNS серверов для обновления ip-адресов. По мимо локального и google dns, можно использовать [публичные российские DNS](https://public-dns.info/nameserver/ru.html), например [Яндекс](https://dns.yandex.ru/) |
| timeout  | int      | Время между обновлением ip-адресов доменов (секунды)                                                                                                                                                                 |
| ip4      | string[] | Начальный список ipv4 адресов                                                                                                                                                                                        |
| ip6      | string[] | Начальный список ipv6 адресов                                                                                                                                                                                        |
| cidr4    | string[] | Начальный список CIDRv4 зон ipv4 адресов                                                                                                                                                                             |
| cidr6    | string[] | Начальный список CIDRv6 зон ipv6 адресов                                                                                                                                                                             |
| external | object   | Списки URL для получения начальных данных от сторонних источников                                                                                                                                                    |

| свойство | тип      | описание                                                   |
|----------|----------|------------------------------------------------------------|
| domains  | string[] | Список URL для получения доменов портала                   |
| ip4      | string[] | Список URL для получения начальных ipv4 адресов            |
| ip6      | string[] | Список URL для получения начальных ipv6 адресов            |
| cidr4    | string[] | Список URL для получения начальных CIDRv4 зон ipv4 адресов |
| cidr6    | string[] | Список URL для получения начальных CIDRv6 зон ipv6 адресов |

## Настройка и запуск под docker
```shell
git clone git@github.com:rekryt/iplist.git
cd iplist
cp .env.example .env
```

Если требуется отредактируйте `.env` файл

| свойство              | значение по умолчанию | описание                                                            |
|-----------------------|-----------------------|---------------------------------------------------------------------|
| COMPOSE_PROJECT_NAME  | iplist                | Имя compose проекта                                                 |
| STORAGE_SAVE_INTERVAL | 120                   | Период сохранения кеша whois (секунды)                              |
| SYS_MEMORY_LIMIT      | 512M                  | Предельное кол-во памяти. Для начальной конфигурации достаточно 1МБ |
| SYS_TIMEZONE          | Europe/Moscow         | Список URL для получения начальных CIDRv4 зон ipv4 адресов          |
| DEBUG                 | true                  | Определяет уровень логирования                                      |

```shell
docker compose up -d
```

Открыть сервис можно в браузере по протоколу http, порт `8080`
```
http://0.0.0.0:8080/
http://0.0.0.0:8080/?format=json
http://0.0.0.0:8080/?format=json&site=youtube.com&data=domains
http://0.0.0.0:8080/?format=text&site=youtube.com&data=ip4
http://0.0.0.0:8080/?format=mikrotik&data=cidr4
http://0.0.0.0:8080/?format=mikrotik&site=youtube.com&data=cidr4
```

## Настройка SSL
Для настройки SSL сертификата вам понадобится домен настроенный на ваш сервер.
Если у вас нет собственного домена - как вариант бесплатный домен можно получить например на [https://noip.com](https://noip.com).
Актуальность такого домена придётся подтверждать раз в месяц.
- Установите и настройте реверс-прокси, например [NginxProxyManager](https://nginxproxymanager.com/guide/#quick-setup) 
- Создайте виртуальную сеть docker
```shell
docker network create web
```
- Настройте её в docker-compose.yml файлах реверс-прокси и данного проекта
```yml
services:
    ...
    app:
        networks:
            - web
networks:
    web:
        external: true
        name: web
```
- Удалите свойство ports из docker-compose.yml (этого проекта)
- Примените изменения:
```shell
docker compose up -d
```
- Имя контейнера можно посмотреть командой `docker compose ps`
- В панели администрирования реверс-прокси настройте домен на него `iplist-app-1` порт `8080` и включите SSL
- NginxProxyManager будет продлевать ssl сертификат автоматически

## Ручной запуск (PHP 8.1+)
```shell
apt-get install -y ntp whois dnsutils ipcalc
cp .env.example .env
composer install
php index.php
```

## Настройка Mikrotik
- В администраторской панели роутера (или через winbox) откройте раздел System -> Scripts
- Создайте новый скрипт "Add new" с произвольным именем, например `iplist_youtube_v4_cidr`
- В поле `Source` введите следующий код (используйте `url` адрес вашего сервера, протокол в `mode` тоже может отличаться):
```
/tool fetch url="https://iplist.opencck.org/?format=mikrotik&site=youtube.com&data=cidr4" mode=https dst-path=iplist_youtube_v4_cidr.rsc
:delay 5s
:log info "Downloaded iplist_youtube_v4_cidr.rsc succesfully";

/ip firewall address-list remove [find where comment="youtube.com"];
:delay 5s

/import file-name=iplist_youtube_v4_cidr.rsc
:delay 10s
:log info "New iplist_youtube_v4_cidr added successfully";
```
- ![1](https://github.com/user-attachments/assets/5c88ea7a-7d5b-41de-8405-e1d2b13b96a2)
- Сохраните скрипт
- Откройте раздел планировщика System -> Scheduler
- Создайте новое задание с произвольным именем, например `iplist_youtube_v4_cidr`
- В качестве `Start time` укажите время для старта задания (пример: `00:05:00`). Для `Interval` введите значение `1d 00:00:00`.
- В поле `On event` введите имя скрипта
```
iplist_youtube_v4_cidr
```
- ![2](https://github.com/user-attachments/assets/1b364ddc-a4b7-4563-987c-3dd382eb082d)
- Откройте скрипт в разделе System -> Scripts и запустите его нажатием на кнопку `Run Script`
- В разделе Logs вы должны увидеть сообщение `New iplist_youtube_v4_cidr added successfully`
- ![3](https://github.com/user-attachments/assets/4ef15415-60f5-4c70-9f18-c8bece797e3d)
- А в разделе IP -> Firewall -> Address Lists должен появиться новый список (в примере с именем `youtube.com`)
- ![4](https://github.com/user-attachments/assets/72d00414-252c-4ddb-84ed-80b09e247e39)

### License
The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
