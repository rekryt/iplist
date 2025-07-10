<script lang="ts" setup>
const { t } = useI18n({
    useScope: 'local',
});
const props = defineProps({
    modelValue: {
        type: Array,
        required: true,
    },
    items: {
        type: Array,
        default() {
            return [];
        },
    },
});
const panel = ref(props.items.map((_, i) => i));
const emit = defineEmits(['update:modelValue']);
const selected = computed({
    get() {
        return props.modelValue;
    },
    set(value) {
        emit('update:modelValue', value);
    },
});
const setSelect = (subItem: { label: string; value: string }): void => {
    if (selected.value.includes(subItem as never)) {
        selected.value.splice(selected.value.indexOf(subItem as never), 1);
    } else {
        selected.value.push(subItem);
    }
};
const setSelectGroup = (item: { label: string; items: { label: string; value: string }[] }): void => {
    if (item.items.every((subItem) => selected.value.includes(subItem as never))) {
        item.items.forEach((subItem) => selected.value.splice(selected.value.indexOf(subItem as never), 1));
    } else {
        item.items.forEach((subItem: { label: string; value: string }) => {
            if (!selected.value.includes(subItem as never)) {
                selected.value.push(subItem);
            }
        });
    }
};
</script>
<i18n lang="json">
{
    "en": {
        "cleanSelection": "Clear selection",
        "collapseAll": "Collapse all",
        "expandAll": "Expand all"
    },
    "ru": {
        "cleanSelection": "Очистить выбор",
        "collapseAll": "Свернуть всё",
        "expandAll": "Развернуть всё"
    },
    "cn": {
        "cleanSelection": "清除选择",
        "collapseAll": "全部折叠",
        "expandAll": "全部展开"
    }
}
</i18n>
<template>
    <v-row class="px-2 mb-1" justify="end">
        <v-col v-if="selected.length > 0" cols="auto">
            <v-btn @click="selected.splice(0)">{{ t('cleanSelection') }}</v-btn>
        </v-col>
        <v-col v-if="panel.length > 0" cols="auto">
            <v-btn @click="panel.splice(0)">{{ t('collapseAll') }}</v-btn>
        </v-col>
        <v-col v-else cols="auto">
            <v-btn @click="panel = items.map((_, i) => i)">{{ t('expandAll') }}</v-btn>
        </v-col>
    </v-row>
    <v-expansion-panels v-model="panel" class="select px-2" multiple>
        <v-expansion-panel v-for="(group, index) in items" :key="index" class="select" elevation="10">
            <v-expansion-panel-title class="select-title">
                <v-checkbox
                    class="select-checkbox"
                    :model-value="group.items.every((item) => modelValue.includes(item))"
                    :label="group.label"
                    hide-details
                    @click.stop="setSelectGroup(group)"
                ></v-checkbox>
            </v-expansion-panel-title>
            <v-expansion-panel-text>
                <v-btn
                    v-for="(site, key) in group.items"
                    :key="key"
                    class="ma-1"
                    elevation="5"
                    border="5"
                    height="30"
                    :active="selected.includes(site)"
                    active-color="primary"
                    @click="setSelect(site)"
                >
                    <img :src="'/favicon?site=' + site.value" class="select-icon" />
                    {{ site.label }}
                </v-btn>
            </v-expansion-panel-text>
        </v-expansion-panel>
    </v-expansion-panels>
</template>
<style lang="scss">
.select {
    &-title {
        padding-top: 5px;
        padding-bottom: 5px;
        height: 35px !important;
        min-height: 0 !important;
        .v-expansion-panel--active > & {
            height: 45px !important;
            min-height: 0 !important;
        }
    }
    &-icon {
        margin-left: -6px;
        margin-right: 5px;
        max-width: 16px;
    }
    &-checkbox {
        --v-input-control-height: 30px;
    }
}
</style>
