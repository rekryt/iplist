### IP Address Collection and Management Service with multiple formats 
For english readme: [README.en.md](README.en.md)

Demo URL: [https://iplist.opencck.org](https://iplist.opencck.org)

![iplist](https://github.com/user-attachments/assets/e004bc06-3646-4eec-acce-9c6799a3661a)

# Сервис сбора IP-адресов и CIDR зон
Данный сервис предназначен для сбора и обновления IP-адресов (IPv4 и IPv6), а также их CIDR зон для указанных доменов.
Это асинхронный PHP веб-сервер на основе [AMPHP](https://amphp.org/) и Linux-утилит `whois` и `ipcalc`.
Сервис предоставляет интерфейсы для получения списков зон ip адресов указанных доменов (IPv4 адресов, IPv6 адресов, а также CIDRv4 и CIDRv6 зон) в различных форматах, включая текстовый, JSON, форматы скриптов для добавления в "Address List" на роутерах Mikrotik (RouterOS), Keenetic KVAS\BAT, SwitchyOmega, Amnezia и др.

Основные возможности
- Сбор и авитоматическое обновление IP-адресов и CIDR зон для доменов.
- Поддержка вывода данных в различных форматах (JSON, lst, Mikrotik, OpenWRT, ipset и т.д).
- Интеграция с внешними источниками данных (поддержка импорта начальных данных из внешних URL).
- Легкое развертывание с помощью Docker Compose.
- Настройка через JSON файлы для управления доменами.

Используемые технологии
- PHP 8.1+ (amphp, revolt)
- whois, ipcalc (linux)

# Форматы выгрузки
| формат   | описание                      |
|----------|-------------------------------|
| json     | JSON формат                   |
| text     | Разделение новой строкой      |
| comma    | Разделение запятыми           |
| mikrotik | MikroTik Script               |
| switchy  | SwitchyOmega RuleList         |
| nfset    | Dnsmasq nfset                 |
| ipset    | Dnsmasq ipset                 |
| clashx   | ClashX                        |
| kvas     | Keenetic KVAS                 |
| bat      | Keenetic Routes .bat          |
| amnezia  | Amnezia filter list           |
| pac      | Proxy Auto-Configuration file |

## Настройки
Конфигурационные файлы хранятся в `config/<группа>/<портал>.json`. Каждый JSON файл представляет собой конфигурацию для конкретного портала, задавая домены для мониторинга и источники начальных данных по IP и CIDR.
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
| external | object   | Списки URL для получения данных от сторонних источников                                                                                                                                                              |

| свойство | тип      | описание                                            |
|----------|----------|-----------------------------------------------------|
| domains  | string[] | Список URL для пополнения доменов портала           |
| ip4      | string[] | Список URL для пополнения ipv4 адресов              |
| ip6      | string[] | Список URL для пополнения ipv6 адресов              |
| cidr4    | string[] | Список URL для пополнения CIDRv4 зон ipv4 адресов   |
| cidr6    | string[] | Список URL для пополнения CIDRv6 зон ipv6 адресов   |

## Настройка и запуск под docker
```shell
git clone https://github.com/rekryt/iplist.git
cd iplist
cp .env.example .env
```

Если требуется отредактируйте `.env` файл

| свойство                   | значение по умолчанию | описание                                                   |
|----------------------------|-----------------------|------------------------------------------------------------|
| COMPOSE_PROJECT_NAME       | iplist                | Имя compose проекта                                        |
| STORAGE_SAVE_INTERVAL      | 120                   | Период сохранения кеша whois (секунды)                     |
| SYS_DNS_RESOLVE_IP4        | true                  | Получать ipv4 адреса                                       |
| SYS_DNS_RESOLVE_IP6        | true                  | Получать ipv6 адреса                                       |
| SYS_DNS_RESOLVE_CHUNK_SIZE | 10                    | Размер чанка для получения dns записей                     |
| SYS_DNS_RESOLVE_DELAY      | 100                   | Задержка между получением dns записей (миллисекунды)       |
| SYS_MEMORY_LIMIT           | 1024M                 | Предельное кол-во памяти.                                  |
| SYS_TIMEZONE               | Europe/Moscow         | Список URL для получения начальных CIDRv4 зон ipv4 адресов |
| HTTP_HOST                  | 0.0.0.0               | IP сетевого интерфейса (по умолчанию все интерфейсы)       |
| HTTP_PORT                  | 8080                  | Сетевой порт сервера (по умолчанию 8080)                   |
| DEBUG                      | true                  | Определяет уровень логирования                             |

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
http://0.0.0.0:8080/?format=comma&data=cidr4
```

| get параметр     | описание                         | пример                                                                                                                                                                                                                                  |
|------------------|----------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| format           | Формат выгрузки данных           | ?format=text                                                                                                                                                                                                                            |
| data             | Данные для выгрузки              | ?data=cidr4                                                                                                                                                                                                                             |
| site             | Портал для выгрузки данных       | ?site=youtube.com                                                                                                                                                                                                                       |
| group            | Группа для выгрузки данных       | ?group=youtube                                                                                                                                                                                                                          |
| exclude[ip4]     | Исключить ipv4 адреса            | ?exclude[ip4]=1.1.1.1&exclude[ip4]=2.2.2.2                                                                                                                                                                                              | 
| exclude[ip6]     | Исключить ipv6 адреса            | ?exclude[ip6]=2a06:98c1:3121::a                                                                                                                                                                                                         |
| exclude[cidr4]   | Исключить CIDRv4 зоны            | ?exclude[cidr4]=1.1.1.0/24                                                                                                                                                                                                              |
| exclude[cidr6]   | Исключить CIDRv6 зоны            | ?exclude[cidr6]=2a06:98c1::/32                                                                                                                                                                                                          |
| exclude[group]   | Исключить группы                 | ?exclude[group]=youtube&exclude[group]=casino                                                                                                                                                                                           |
| exclude[site]    | Исключить порталы                | ?exclude[site]=youtube.com                                                                                                                                                                                                              |
| exclude[domain]  | Исключить домены                 | ?exclude[domain]=youtube.com                                                                                                                                                                                                            |
| wildcard         | Оставлять только wildcard домены | ?wildcard=1                                                                                                                                                                                                                             |
| filesave         | Сохранять как файл               | ?filesave=1                                                                                                                                                                                                                             |
| template         | Шаблон выгрузки                  | ?format=custom&template=[подробнее](https://github.com/rekryt/iplist?tab=readme-ov-file#%D0%BA%D0%B0%D1%81%D1%82%D0%BE%D0%BC%D0%BD%D1%8B%D0%B9-%D1%84%D0%BE%D1%80%D0%BC%D0%B0%D1%82-%D0%B2%D1%8B%D0%B2%D0%BE%D0%B4%D0%B0)               |

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

## Кастомный формат вывода
Для получения выгрузки данных по заданному шаблону используются get параметры: format=custom и template=шаблон, где шаблон может содержать такие паттерны как:

| свойство    | описание                                 |
|-------------|------------------------------------------|
| {group}     | Имя группы                               |
| {site}      | Имя сайта                                |
| {data}      | Выбранные данные                         |
| {shortmask} | Маска подсети (короткая) (для ip и cidr) |
| {mask}      | Маска подсети (полная)  (для ip и cidr)  |

Примеры:
```
Wildcard домены twitter для dns static add под mikrotik для forward-to=localhost:
https://iplist.opencck.org/?format=custom&data=domains&site=x.com&wildcard=1&template=%2Fip%20dns%20static%20add%20name%3D%7Bdata%7D%20type%3DFWD%20address-list%3D%7Bgroup%7D_%7Bsite%7D%20match-subdomain%3Dyes%20forward-to%3Dlocalhost

Wildcard домены в кастомном формате:
https://iplist.opencck.org/?format=custom&data=domains&wildcard=1&template=data%3A%20%7Bdata%7D%20group%3A%20%7Bgroup%7D%20site%3A%20%7Bsite%7D

Маска подсети в кастомном формате:
https://iplist.opencck.org/?format=custom&data=cidr4&template=data%3A%20%7Bdata%7D%20group%3A%20%7Bgroup%7D%20site%3A%20%7Bsite%7D%20shortmask%3A%20%7Bshortmask%7D%20mask%3A%20%7Bmask%7D
```

## Настройка Mikrotik
- В администраторской панели роутера (или через winbox) откройте раздел System -> Scripts
- Создайте новый скрипт "Add new" с произвольным именем, например `iplist_v4_cidr`
- В поле `Source` введите следующий код (используйте `url` адрес вашего сервера, протокол в `mode` тоже может отличаться):
```
/tool fetch url="https://iplist.opencck.org/?format=mikrotik&data=cidr4&append=timeout%3D1d" mode=https dst-path=iplist_v4_cidr.rsc
:delay 5s
:log info "Downloaded iplist_v4_cidr.rsc succesfully";

/import file-name=iplist_v4_cidr.rsc
:delay 10s
:log info "New iplist_v4_cidr added successfully";
```
- ![1](https://github.com/user-attachments/assets/6de7211b-7758-4498-985b-04c407dc3ca7)
- Сохраните скрипт
- Откройте раздел планировщика System -> Scheduler
- Создайте новое задание с произвольным именем, например `iplist_v4_cidr`
- В качестве `Start time` укажите время для старта задания (пример: `00:05:00`). Для `Interval` введите значение `1d 00:00:00`.
- В поле `On event` введите имя скрипта
```
iplist_v4_cidr
```
- ![2](https://github.com/user-attachments/assets/c3e7277a-5c0f-4413-885f-87efb13ac5cf)
- Откройте скрипт в разделе System -> Scripts и запустите его нажатием на кнопку `Run Script`
- В разделе Logs вы должны увидеть сообщение `New iplist_v4_cidr added successfully`
- ![3](https://github.com/user-attachments/assets/6d631a64-68cf-46bc-82d9-d58332e4112c)
- А в разделе IP -> Firewall -> Address Lists должны появиться новые списоки (в примере с именем `youtube`)
- ![4](https://github.com/user-attachments/assets/bb9ada57-60eb-40df-a031-7a0bc05bc4cb)

## Настройка HomeProxy (sing-box)
Включите "Routing mode" в "Only proxy mainland China":
![1](https://github.com/user-attachments/assets/b0295368-b160-430d-8802-9b65db4e096f)
Подключитесь к роутеру по ssh и выполните следующие команды:
```shell
# переименовываем старый скрипт обновления
mv /etc/homeproxy/scripts/update_resources.sh /etc/homeproxy/scripts/update_resources.sh.origin

# загружаем новый скрипт
wget https://iplist.opencck.org/scripts/homeproxy/update_resources.sh -O /etc/homeproxy/scripts/update_resources.sh

# добавляем права на выполнение
chmod +x /etc/homeproxy/scripts/update_resources.sh

# отменяем стирание cron после перезапуска homeproxy
sed -i '/sed -i/s/^/\t#/; /\/etc\/init.d\/cron restart >/s/^/\t#/' /etc/init.d/homeproxy

# вы захостили это решение? - тогда раскомментируйте следующую строку и поменяйте "example.com" на ваш домен
# sed -i 's/iplist.opencck.org/example.com/g' /etc/homeproxy/scripts/update_resources.sh
```
Откройте административную панель OpenWRT раздел "System" - "Sсheduled Tasks".
Добавьте строку, чтобы автоматически запускать скрипт обновления при старте, а также в 00:05:00 и 12:05:00
```
5 0,12 * * * /etc/homeproxy/scripts/update_crond.sh
```
![2](https://github.com/user-attachments/assets/2369b32c-d43a-4837-97ce-c46a9dd79e5e)

## Настройка дополнения для Chrome - Proxy SwitchySharp
Установить можно [по ссылке](https://chromewebstore.google.com/detail/proxy-switchysharp/dpplabbmogkhghncfbfdeeokoefdjegm)
![1](https://github.com/user-attachments/assets/10aaa2f6-5502-472b-97e0-0c4d4e38358d)

Подробнее о формате [Switchy RuleList](https://code.google.com/archive/p/switchy/wikis/RuleList.wiki)

### License
The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
