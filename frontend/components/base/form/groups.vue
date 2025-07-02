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
</script>
<i18n lang="json">
{
    "en": {
        "allGroups": "All groups",
        "noData": "Not found"
    },
    "ru": {
        "allGroups": "Все группы",
        "noData": "Не найдено"
    },
    "cn": {
        "allGroups": "所有分组",
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
        item-value="label"
        variant="outlined"
        :placeholder="t('allGroups')"
        :no-data-text="t('noData')"
        :hint="hint"
        :persistent-hint="persistentHint"
        :loading="loading"
        :hide-details="hideDetails"
        multiple
        chips
        clearable
    ></v-autocomplete>
</template>
