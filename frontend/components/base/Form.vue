<script lang="ts" setup>
interface Item {
    label: string;
    value: string;
}

interface Group {
    label: string;
    items: Item[];
}

const { t } = useI18n({
    useScope: 'local',
});

const { data, pending } = useFetch((process.env.NODE_ENV === 'production' ? '/' : '/api') + '?format=json&data=group', {
    lazy: true,
    server: false,
    default: () => [],
});
const selected = ref<Item[]>([]);
const selectedGroups = ref<Group[]>([]);
const selectedExcluded = ref<Item[]>([]);
const selectedExcludedGroups = ref<Group[]>([]);
const selectedExcludedCIDR4 = ref<string[]>([]);
const selectedExcludedIP4 = ref<string[]>([]);
const selectedExcludedDomains = ref<string[]>([]);
const selectedExcludedIP6 = ref<string[]>([]);
const selectedExcludedCIDR6 = ref<string[]>([]);
const isWildCard = ref(false);
const isFileSave = ref(false);

const items = computed<Group[]>(() => {
    return Object.entries(data.value as Record<string, string[]>).reduce<Group[]>((acc, [site, group]) => {
        let groupObj = acc.find((g) => g.label === group);
        if (!groupObj) {
            groupObj = { label: group, items: [] } as Group;
            acc.push(groupObj);
        }
        groupObj.items.push({ label: site, value: site });
        return acc;
    }, []);
});

const itemsList = computed(() => {
    return selectedGroups.value.length > 0
        ? items.value.filter((item) => selectedGroups.value.includes(item.label))
        : items.value;
});

const itemsExcludedList = computed(() => {
    return selectedExcludedGroups.value.length > 0
        ? items.value.filter((item) => !selectedExcludedGroups.value.includes(item.label))
        : items.value;
});

// prettier-ignore
const formatList = ref([
    { label: 'JSON',                    value: 'json' },
    { label: 'Text',                    value: 'text',          dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'Comma',                   value: 'comma',         dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'v2rayGeoIPDat',           value: 'geoip',         dataTypes: ['cidr4', 'ip4', 'cidr6', 'ip6']  },
    { label: 'MikroTik Script',         value: 'mikrotik',      dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'SwitchyOmega RuleList',   value: 'switchy',       dataTypes: ['domains'] },
    { label: 'Dnsmasq nfset',           value: 'nfset',         dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'Dnsmasq ipset',           value: 'ipset',         dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'ClashX',                  value: 'clashx',        dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'Keenetic KVAS',           value: 'kvas',          dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'Keenetic Routes (.bat)',  value: 'bat',           dataTypes: ['ip4', 'cidr4'] },
    { label: 'Amnezia',                 value: 'amnezia',       dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
    { label: 'Proxy auto configuration (PAC)', value: 'pac',    dataTypes: ['domains', 'cidr4'] },
    { label: 'Custom',                  value: 'custom',        dataTypes: ['cidr4', 'ip4', 'domains', 'cidr6', 'ip6'] },
]);
const selectedFormat = ref('json');
const customTemplate = ref('');

const dataTypeList = ref([
    { label: t('allData'), value: '' },
    { label: t('ipZones4'), value: 'cidr4' },
    { label: t('ipAddresses4'), value: 'ip4' },
    { label: t('domains'), value: 'domains' },
    { label: t('ipZones6'), value: 'cidr6' },
    { label: t('ipAddresses6'), value: 'ip6' },
]);
const selectedDataType = ref('');
const allowedDataTypesList = computed(() => {
    const format = formatList.value.find((format) => format.value === selectedFormat.value);
    return dataTypeList.value.filter((dataType) => !format.dataTypes || format.dataTypes?.includes(dataType.value));
});
watch(selectedFormat, () => {
    const format = formatList.value.find((format) => format.value === selectedFormat.value);
    if (format.dataTypes) {
        if (!format.dataTypes.includes(selectedDataType.value)) {
            selectedDataType.value = allowedDataTypesList.value[0].value;
        }
    }
});
const tab = ref('portals');
const toQueryParams = (params: Record<string, never>): string => {
    const parts: string[] = [];

    for (const key in params) {
        const value = params[key];

        if (value === undefined || value === null) continue;

        if (Array.isArray(value)) {
            for (const item of value) {
                parts.push(`${key}=${encodeURIComponent(item)}`);
            }
        } else if (typeof value === 'object') {
            for (const subKey in value) {
                const subValue = value[subKey];
                if (subValue !== undefined && subValue !== null) {
                    parts.push(`${key}[${encodeURIComponent(subKey)}]=${encodeURIComponent(subValue)}`);
                }
            }
        } else {
            parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(value)}`);
        }
    }

    return parts.join('&');
};

const submit = () => {
    const data = {
        format: selectedFormat.value,
    };
    if (selectedDataType.value) {
        data['data'] = selectedDataType.value;
        if (selectedDataType.value === 'domains' && isWildCard.value) {
            data['wildcard'] = '1';
        }
    }
    if (selected.value.length > 0) {
        data['site'] = selected.value.map((item) => item.label);
    }
    if (selectedGroups.value.length > 0) {
        data['group'] = selectedGroups.value;
    }
    if (selectedFormat.value === 'custom') {
        data['template'] = customTemplate.value;
    }

    if (selectedExcluded.value.length > 0) {
        data['exclude[site]'] = selectedExcluded.value.map((item) => item.label);
    }
    if (selectedExcludedGroups.value.length > 0) {
        data['exclude[group]'] = selectedExcludedGroups.value;
    }
    if (selectedDataType.value === 'ip4' && selectedExcludedIP4.value.length > 0) {
        data['exclude[ip4]'] = selectedExcludedIP4.value;
    }
    if (selectedDataType.value === 'ip6' && selectedExcludedIP6.value.length > 0) {
        data['exclude[ip6]'] = selectedExcludedIP6.value;
    }
    if (selectedDataType.value === 'cidr4' && selectedExcludedCIDR4.value.length > 0) {
        data['exclude[cidr4]'] = selectedExcludedCIDR4.value;
    }
    if (selectedDataType.value === 'cidr6' && selectedExcludedCIDR6.value.length > 0) {
        data['exclude[cidr6]'] = selectedExcludedCIDR6.value;
    }
    if (selectedDataType.value === 'domains' && selectedExcludedDomains.value.length > 0) {
        data['exclude[domain]'] = selectedExcludedDomains.value;
    }

    if (isFileSave.value) {
        data['filesave'] = '1';
    }

    window.location.href = '/?' + toQueryParams(data);
};
</script>
<i18n lang="json">
{
    "en": {
        "format": "Format",
        "dataType": "Data type",
        "template": "Template",
        "groupName": "Group name",
        "siteName": "Portal name",
        "data": "Selected data",
        "shortmask": "Subnet mask (short) (for IP and CIDR)",
        "mask": "Subnet mask (full) (for IP and CIDR)",
        "portals": "Portals",
        "groups": "Groups",
        "exclude": "Exclusions",
        "portalSelection": "Portal selection",
        "doNotSelectIfNeedAll": "Do not select if you want to get all",
        "filteredByGroups": "The set of portals is filtered by the selected groups",
        "groupSelection": "Group selection",
        "excludePortals": "Exclude portals",
        "excludeGroups": "Exclude groups",
        "excludeIpZones": "Exclude IP zones",
        "excludeIp": "Exclude IP",
        "excludeDomains": "Exclude domains",
        "onlyWildcard": "Only wildcard domains",
        "saveToFile": "Save as file",
        "submit": "Submit",
        "allData": "All data",
        "ipZones4": "IPv4 zones (CIDR)",
        "ipAddresses4": "IPv4 addresses",
        "domains": "Domains",
        "ipZones6": "IPv6 zones (CIDR)",
        "ipAddresses6": "IPv6 addresses"
    },
    "ru": {
        "format": "Формат",
        "dataType": "Тип данных",
        "template": "Шаблон",
        "groupName": "Имя группы",
        "siteName": "Имя портала",
        "data": "Выбранные данные",
        "shortmask": "Маска подсети (короткая) (для ip и cidr)",
        "mask": "Маска подсети (полная) (для ip и cidr)",
        "portals": "Порталы",
        "groups": "Группы",
        "exclude": "Исключения",
        "portalSelection": "Выбор порталов",
        "doNotSelectIfNeedAll": "Не выбирайте, если хотите получить все",
        "filteredByGroups": "Набор порталов отфильтрован по выбранным группам",
        "groupSelection": "Выбор групп",
        "excludePortals": "Исключить порталы",
        "excludeGroups": "Исключить группы",
        "excludeIpZones": "Исключить IP-зоны",
        "excludeIp": "Исключить IP",
        "excludeDomains": "Исключить домены",
        "onlyWildcard": "Только wildcard домены",
        "saveToFile": "Сохранить как файл",
        "submit": "Отправить",
        "allData": "Все данные",
        "ipZones4": "IP-зоны ipv4 (CIDR)",
        "ipAddresses4": "IP-адреса ipv4",
        "domains": "Домены",
        "ipZones6": "IP-зоны ipv6 (CIDR)",
        "ipAddresses6": "IP-адреса ipv6"
    },
    "cn": {
        "format": "格式",
        "dataType": "数据类型",
        "template": "模板",
        "groupName": "分组名称",
        "siteName": "门户名称",
        "data": "已选数据",
        "shortmask": "子网掩码（简写）（用于 IP 和 CIDR）",
        "mask": "子网掩码（完整）（用于 IP 和 CIDR）",
        "portals": "门户",
        "groups": "分组",
        "exclude": "排除项",
        "portalSelection": "门户选择",
        "doNotSelectIfNeedAll": "如果需要全部，请不要选择",
        "filteredByGroups": "门户集合已根据所选分组进行筛选。",
        "groupSelection": "分组选择",
        "excludePortals": "排除门户",
        "excludeGroups": "排除分组",
        "excludeIpZones": "排除 IP 区域",
        "excludeIp": "排除 IP",
        "excludeDomains": "排除域名",
        "onlyWildcard": "仅限通配符域名",
        "saveToFile": "保存为文件",
        "submit": "提交",
        "allData": "所有数据",
        "ipZones4": "IPv4 区域（CIDR）",
        "ipAddresses4": "IPv4 地址",
        "domains": "域名",
        "ipZones6": "IPv6 区域（CIDR）",
        "ipAddresses6": "IPv6 地址"
    }
}
</i18n>
<template>
    <v-form class="baseForm mx-auto">
        <v-card class="px-4 py-8" elevation="10">
            <v-row>
                <v-col cols="6">
                    <v-select
                        v-model="selectedFormat"
                        :items="formatList"
                        item-title="label"
                        item-value="value"
                        :label="t('format')"
                        variant="outlined"
                        density="compact"
                        hide-details
                    ></v-select>
                </v-col>
                <v-col cols="6">
                    <v-select
                        v-model="selectedDataType"
                        :items="allowedDataTypesList"
                        item-title="label"
                        item-value="value"
                        :label="t('dataType')"
                        variant="outlined"
                        density="compact"
                        hide-details
                    ></v-select>
                </v-col>
                <v-col v-if="selectedFormat === 'custom'" cols="12">
                    <v-row>
                        <v-col>
                            <v-text-field
                                v-model="customTemplate"
                                :label="t('template')"
                                variant="outlined"
                                density="compact"
                                hide-details
                            ></v-text-field>
                        </v-col>
                        <v-col cols="auto" class="d-flex flex-column justify-center pl-0">
                            <v-tooltip interactive>
                                <template #activator="{ props: activatorProps }">
                                    <v-icon v-bind="activatorProps" color="tertiary">mdi-help</v-icon>
                                </template>
                                <div class="pa-4">
                                    <ul>
                                        <li>{group} - {{ t('groupName') }}</li>
                                        <li>{site} - {{ t('siteName') }}</li>
                                        <li>{data} - {{ t('groupName') }}</li>
                                        <li>{shortmask} - {{ t('shortmask') }}</li>
                                        <li>{mask} - {{ t('mask') }}</li>
                                    </ul>
                                </div>
                            </v-tooltip>
                        </v-col>
                    </v-row>
                </v-col>
                <v-col cols="12">
                    <v-card>
                        <v-tabs v-model="tab" bg-color="primary">
                            <v-tab value="portals">{{ t('portals') }}</v-tab>
                            <v-tab value="groups">{{ t('groups') }}</v-tab>
                            <v-tab value="exclude">{{ t('exclude') }}</v-tab>
                        </v-tabs>
                        <v-card-text class="px-0">
                            <v-tabs-window v-model="tab">
                                <v-tabs-window-item class="pt-2" value="portals">
                                    <base-form-portals
                                        v-model="selected"
                                        :label="t('portalSelection')"
                                        :items="itemsList"
                                        :selected-groups="selectedGroups"
                                        :hint="
                                            selectedGroups.length === 0
                                                ? t('doNotSelectIfNeedAll')
                                                : t('filteredByGroups')
                                        "
                                        persistent-hint
                                        :loading="pending"
                                    ></base-form-portals>
                                </v-tabs-window-item>

                                <v-tabs-window-item class="pt-2" value="groups">
                                    <base-form-groups
                                        v-model="selectedGroups"
                                        :label="t('groupSelection')"
                                        :items="items"
                                        :hint="selected.length === 0 ? t('doNotSelectIfNeedAll') : ''"
                                        :persistent-hint="selected.length === 0"
                                        :loading="pending"
                                    ></base-form-groups>
                                </v-tabs-window-item>

                                <v-tabs-window-item class="pt-2" value="exclude">
                                    <v-row>
                                        <v-col cols="12">
                                            <base-form-groups
                                                v-model="selectedExcludedGroups"
                                                :label="t('excludeGroups')"
                                                :items="items"
                                                :loading="pending"
                                                hide-details
                                            ></base-form-groups>
                                        </v-col>
                                        <v-col cols="12">
                                            <base-form-portals
                                                v-model="selectedExcluded"
                                                :label="t('excludePortals')"
                                                :items="itemsExcludedList"
                                                :loading="pending"
                                                hide-details
                                            ></base-form-portals>
                                        </v-col>
                                        <v-col v-if="selectedDataType === 'cidr4'" cols="12">
                                            <v-combobox
                                                v-model="selectedExcludedCIDR4"
                                                :label="t('excludeIpZones') + ' ipv4'"
                                                variant="outlined"
                                                hide-details
                                                multiple
                                                chips
                                                clearable
                                            ></v-combobox>
                                        </v-col>
                                        <v-col v-if="selectedDataType === 'ip4'" cols="12">
                                            <v-combobox
                                                v-model="selectedExcludedIP4"
                                                :label="t('excludeIp') + ' ipv4'"
                                                variant="outlined"
                                                hide-details
                                                multiple
                                                chips
                                                clearable
                                            ></v-combobox>
                                        </v-col>
                                        <v-col v-if="selectedDataType === 'domains'" cols="12">
                                            <v-combobox
                                                v-model="selectedExcludedDomains"
                                                :label="t('excludeDomains')"
                                                variant="outlined"
                                                hide-details
                                                multiple
                                                chips
                                                clearable
                                            ></v-combobox>
                                        </v-col>
                                        <v-col v-if="selectedDataType === 'cidr6'" cols="12">
                                            <v-combobox
                                                v-model="selectedExcludedCIDR6"
                                                :label="t('excludeIpZones') + ' ipv6'"
                                                variant="outlined"
                                                hide-details
                                                multiple
                                                chips
                                                clearable
                                            ></v-combobox>
                                        </v-col>
                                        <v-col v-if="selectedDataType === 'ip6'" cols="12">
                                            <v-combobox
                                                v-model="selectedExcludedIP6"
                                                :label="t('excludeIp') + ' ipv6'"
                                                variant="outlined"
                                                hide-details
                                                multiple
                                                chips
                                                clearable
                                            ></v-combobox>
                                        </v-col>
                                    </v-row>
                                </v-tabs-window-item>
                            </v-tabs-window>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col class="py-0" cols="12">
                    <v-checkbox
                        v-if="selectedDataType === 'domains'"
                        v-model="isWildCard"
                        :label="t('onlyWildcard')"
                        :value="true"
                        color="primary"
                        density="compact"
                        hide-details
                    ></v-checkbox>
                    <v-checkbox
                        v-if="selectedFormat !== 'geoip'"
                        v-model="isFileSave"
                        :label="t('saveToFile')"
                        :value="true"
                        color="primary"
                        density="compact"
                        hide-details
                    ></v-checkbox>
                </v-col>
                <v-col cols="12">
                    <v-btn color="primary" block size="50" @click="submit">{{ t('submit') }}</v-btn>
                </v-col>
            </v-row>
        </v-card>
    </v-form>
</template>
<style lang="scss">
.baseForm {
    max-width: 920px;
}
</style>
