<script lang="ts" setup>
const { t } = useI18n({
    useScope: 'local',
});
const props = defineProps({
    modelValue: {
        type: Array,
        required: true,
    },
    label: {
        type: String,
        default: '',
    },
    items: {
        type: Array,
        default() {
            return [];
        },
    },
    persistentHint: {
        type: Boolean,
        default: false,
    },
    hint: {
        type: String,
        default: '',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    hideDetails: {
        type: Boolean,
        default: false,
    }
});
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
        "allPortals": "All portals",
        "noData": "Not found"
    },
    "ru": {
        "allPortals": "Все порталы",
        "noData": "Не найдено"
    },
    "cn": {
        "allPortals": "所有门户",
        "noData": "未找到"
    }
}
</i18n>
<template>
    <v-autocomplete
        v-model="selected"
        :items="items"
        :label="label"
        item-title="label"
        item-value="value"
        item-children="items"
        variant="outlined"
        :placeholder="t('allPortals')"
        :no-data-text="t('noData')"
        :hint="hint"
        :persistent-hint="persistentHint"
        :loading="loading"
        :hide-details="hideDetails"
        multiple
        chips
        clearable
    >
        <template #item="{ item }">
            <v-list lines="one" select-strategy="classic">
                <v-list-item class="font-weight-bold" density="compact" @click="() => setSelectGroup(item.raw)">
                    {{ item.raw.label }}
                </v-list-item>
                <v-list-item
                    v-for="(subItem, index) in item.raw.items"
                    :key="index"
                    :tabindex="index"
                    :value="`nestedList${index}`"
                    :active="selected.includes(subItem)"
                    density="compact"
                    @click="() => setSelect(subItem)"
                >
                    <v-list-item-title>
                        {{ subItem.label }}
                    </v-list-item-title>
                </v-list-item>
            </v-list>
        </template>
    </v-autocomplete>
</template>
