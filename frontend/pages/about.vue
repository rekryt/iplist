<script lang="ts" setup>
const { t } = useI18n({
    useScope: 'local',
});
</script>
<i18n lang="json">
{
    "en": {
        "title": "IP Address Collection and Management Service",
        "about": "This service is designed to collect and update IP addresses (IPv4 and IPv6), as well as their CIDR zones for specified domains. It is an asynchronous PHP web server based on AMPHP and uses the Linux utilities whois and ipcalc. The service provides interfaces to retrieve lists of IP address zones of specified domains (IPv4 addresses, IPv6 addresses, as well as CIDRv4 and CIDRv6 zones) in various formats, including plain text, JSON, and script formats for importing into \"Address List\" on routers such as MikroTik (RouterOS), Keenetic KVAS\\BAT, SwitchyOmega, Amnezia, and others."
    },
    "ru": {
        "title": "Сервис сбора IP-адресов и CIDR зон",
        "about": "Данный сервис предназначен для сбора и обновления IP-адресов (IPv4 и IPv6), а также их CIDR зон для указанных доменов. Это асинхронный PHP веб-сервер на основе AMPHP и Linux-утилит whois и ipcalc. Сервис предоставляет интерфейсы для получения списков зон ip адресов указанных доменов (IPv4 адресов, IPv6 адресов, а также CIDRv4 и CIDRv6 зон) в различных форматах, включая текстовый, JSON, форматы скриптов для добавления в \"Address List\" на роутерах Mikrotik (RouterOS), Keenetic KVAS\\BAT, SwitchyOmega, Amnezia и др."
    },
    "cn": {
        "title": "IP地址收集与管理服务",
        "about": "该服务用于收集和更新指定域名的 IP 地址（IPv4 和 IPv6）及其 CIDR 区段。它是一个基于 AMPHP 的异步 PHP Web 服务器，使用 Linux 工具 whois 和 ipcalc。该服务提供接口，以多种格式（包括纯文本、JSON，以及可用于 MikroTik（RouterOS）、Keenetic KVAS\\BAT、SwitchyOmega、Amnezia 等路由器的“地址列表”导入脚本）获取指定域名的 IP 地址区段（IPv4 地址、IPv6 地址、CIDRv4 和 CIDRv6 区段）列表。"
    }
}
</i18n>
<template>
    <v-container fluid>
        <div class="max-w-[960px] mx-auto">
            <h1 class="mb-4">{{ t('title') }}</h1>
            <material-card>{{ t('about') }}</material-card>
        </div>
    </v-container>
</template>
<style lang="scss">
.max-w-\[960px\] {
    max-width: 960px;
}
</style>
