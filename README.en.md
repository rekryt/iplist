# IP Address Collection and Management Service
This service is designed for collecting and updating IP addresses (IPv4 and IPv6) and their CIDR zones for specified domains. It is implemented as a web server using asynchronous PHP 8.1+ with the AMPHP library and integrates with Linux utilities like `whois` and `ipcalc`. The service provides interfaces for retrieving lists of domains, IPv4 addresses, IPv6 addresses, as well as CIDRv4 and CIDRv6 zones in various formats, including plain text, JSON, and scripts for adding to "Address List" on Mikrotik routers (RouterOS).

Demo URL: [https://iplist.opencck.org](https://iplist.opencck.org)

![iplist](https://github.com/user-attachments/assets/2e363fd8-1df7-4554-bf9e-98f58c13df96)

## Key Features

- **Domain Monitoring**: Collect and update IP addresses and CIDR zones for specified domains.
- **Multi-Format Output**: Supports output in plain text, JSON format, script format for RouterOS Mikrotik, or comma separated values.
- **Integration with External Data Sources**: Supports importing initial data from external URLs.
- **Easy Deployment with Docker Compose**.
- **Configuration through JSON files for managing domains and IPs**.

## Technologies Used

- **PHP 8.1+ (amphp, revolt)**
- **whois, ipcalc (Linux utilities)**

# Formats of Output
| format   | description           |
|----------|-----------------------|
| json     | JSON format           |
| text     | Newline-separated     |
| comma    | Comma-separated       |
| mikrotik | MikroTik Script       |
| switchy  | SwitchyOmega RuleList |
| nfset    | Dnsmasq nfset         |
| ipset    | Dnsmasq ipset         |
| clashx   | ClashX                |
| kvas     | Keenetic KVAS         |

## Configuration

Configuration files are stored in the `config/<group>/<site>.json`. Each JSON file represents a configuration for a specific portal, defining the domains to monitor and the sources of initial data for IP and CIDR.

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
| property | type     | description                                                  |
|----------|----------|--------------------------------------------------------------|
| domains  | string[] | List of portal domains                                       |
| dns      | string[] | List of DNS servers for updating IP addresses                |
| timeout  | int      | Time interval between domain IP address updates (seconds)    |
| ip4      | string[] | Initial list of IPv4 addresses                               |
| ip6      | string[] | Initial list of IPv6 addresses                               |
| cidr4    | string[] | Initial list of CIDRv4 zones of IPv4 addresses               |
| cidr6    | string[] | Initial list of CIDRv6 zones of IPv6 addresses               |
| external | object   | Lists of URLs to retrieve initial data from external sources |

| property | type     | description                                                     |
|----------|----------|-----------------------------------------------------------------|
| domains  | string[] | List of URLs to retrieve portal domains                         |
| ip4      | string[] | List of URLs to retrieve initial IPv4 addresses                 |
| ip6      | string[] | List of URLs to retrieve initial IPv6 addresses                 |
| cidr4    | string[] | List of URLs to retrieve initial CIDRv4 zones of IPv4 addresses |
| cidr6    | string[] | List of URLs to retrieve initial CIDRv6 zones of IPv6 addresses |

## Setting Up and Running in Docker
```shell
git clone https://github.com/rekryt/iplist.git
cd iplist
cp .env.example .env
```

If needed, edit the `.env` file:

| property                   | default value | description                                                    |
|----------------------------|---------------|----------------------------------------------------------------|
| COMPOSE_PROJECT_NAME       | iplist        | Name of the compose project                                    |
| STORAGE_SAVE_INTERVAL      | 120           | Cache save interval for whois (seconds)                        |
| SYS_DNS_RESOLVE_CHUNK_SIZE | 10            | Chunk size for retrieving DNS records                          |
| SYS_DNS_RESOLVE_DELAY      | 100           | Delay between receiving dns records (milliseconds)             |
| SYS_MEMORY_LIMIT           | 1024M         | Memory limit                                                   |
| SYS_TIMEZONE               | Europe/Moscow | List of URLs to obtain initial CIDRv4 zones for IPv4 addresses |
| DEBUG                      | true          | Determines the logging level                                   |

You can access the service in your browser via the HTTP protocol on port 8080:
```
http://0.0.0.0:8080/
http://0.0.0.0:8080/?format=json
http://0.0.0.0:8080/?format=json&site=youtube.com&data=domains
http://0.0.0.0:8080/?format=text&site=youtube.com&data=ip4
http://0.0.0.0:8080/?format=mikrotik&data=cidr4
http://0.0.0.0:8080/?format=mikrotik&site=youtube.com&data=cidr4
http://0.0.0.0:8080/?format=comma&data=cidr4
```

## SSL Setup
- Install and configure a reverse proxy, for example, [NginxProxyManager](https://nginxproxymanager.com/guide/#quick-setup).
- Create a Docker virtual network:
```shell
docker network create web
```
- Configure it in the docker-compose.yml files of both the reverse proxy and this project:
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
- Remove the ports property from this project's docker-compose.yml file.
- Apply the changes:
```shell
docker compose up -d
```
- You can view the container name with the command docker compose ps.
- In the reverse proxy administration panel, configure the domain to point to `iplist-app-1` on port `8080` and enable SSL.
- NginxProxyManager will automatically renew the SSL certificate.

## Manual Launch (PHP 8.1+)
```shell
apt-get install -y ntp whois dnsutils ipcalc
cp .env.example .env
composer install
php index.php
```

## Setting up Mikrotik
- In the router's admin panel (or via winbox), navigate to System -> Scripts.
- Create a new script by clicking "Add new" and give it a name, for example `iplist_v4_cidr`
- In the `Source` field, enter the following code (replace `url` with your server's address, and the protocol in `mode` may differ):
```
/tool fetch url="https://iplist.opencck.org/?format=mikrotik&data=cidr4" mode=https dst-path=iplist_v4_cidr.rsc
:delay 5s
:log info "Downloaded iplist_v4_cidr.rsc succesfully";

/import file-name=iplist_v4_cidr.rsc
:delay 10s
:log info "New iplist_v4_cidr added successfully";
```
- ![1](https://github.com/user-attachments/assets/6de7211b-7758-4498-985b-04c407dc3ca7)
- Save the script
- Go to System -> Scheduler
- Create a new task with a name of your choice, for example `iplist_v4_cidr`
- Set the `Start time` for the task (e.g., `00:05:00`). For Interval, enter `1d 00:00:00`.
- In the `On event` field, enter the script name
```
iplist_v4_cidr
```
- ![2](https://github.com/user-attachments/assets/c3e7277a-5c0f-4413-885f-87efb13ac5cf)
- Open the script in System -> Scripts and run it by clicking the `Run Script` button
- In the Logs section, you should see the message `New iplist_v4_cidr added successfully`
- ![3](https://github.com/user-attachments/assets/6d631a64-68cf-46bc-82d9-d58332e4112c)
- In IP -> Firewall -> Address Lists, a new lists should appear (in this example, named `youtube`)
- ![4](https://github.com/user-attachments/assets/bb9ada57-60eb-40df-a031-7a0bc05bc4cb)

## Setting up HomeProxy (sing-box)
Enable "Routing mode" in "Only proxy mainland China":
![1](https://github.com/user-attachments/assets/b0295368-b160-430d-8802-9b65db4e096f)
Connect to the router via SSH and execute the following commands:
```shell
# Rename the old update script
mv /etc/homeproxy/scripts/update_resources.sh /etc/homeproxy/scripts/update_resources.sh.origin

# Download the new script
wget https://iplist.opencck.org/scripts/homeproxy/update_resources.sh -O /etc/homeproxy/scripts/update_resources.sh

# Add execution permissions
chmod +x /etc/homeproxy/scripts/update_resources.sh

# Did you host this solution? - then uncomment the following line and replace "example.com" with your domain
# sed -i 's/iplist.opencck.org/example.com/g' /etc/homeproxy/scripts/update_resources.sh
```
Open the administrative panel in OpenWRT, go to the "System" - "Startup" - "Local Startup" section.
Add the following lines before "exit 0" to automatically run the update script at startup, as well as at 00:05:00 and 12:05:00
```shell
/etc/homeproxy/scripts/update_crond.sh

echo "5 0,12 * * * /etc/homeproxy/scripts/update_crond.sh" > /etc/crontabs/root
/etc/init.d/cron start
/etc/init.d/cron enable
```
![2](https://github.com/user-attachments/assets/2369b32c-d43a-4837-97ce-c46a9dd79e5e)

## Setting up the Chrome extension - Proxy SwitchySharp
You can install it [via the link](https://chromewebstore.google.com/detail/proxy-switchysharp/dpplabbmogkhghncfbfdeeokoefdjegm)
![1](https://github.com/user-attachments/assets/10aaa2f6-5502-472b-97e0-0c4d4e38358d)

More about the [Switchy RuleList](https://code.google.com/archive/p/switchy/wikis/RuleList.wiki)

### License
The MIT License (MIT). Please see [LICENSE](./LICENSE) for more information.
