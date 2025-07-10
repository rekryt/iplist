<script lang="ts" setup>
useI18n({
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
    },
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
// const setSelect = (subItem: { label: string; value: string }): void => {
//     if (selected.value.includes(subItem as never)) {
//         selected.value.splice(selected.value.indexOf(subItem as never), 1);
//     } else {
//         selected.value.push(subItem);
//     }
// };
// const setSelectGroup = (item: { label: string; items: { label: string; value: string }[] }): void => {
//     if (item.items.every((subItem) => selected.value.includes(subItem as never))) {
//         item.items.forEach((subItem) => selected.value.splice(selected.value.indexOf(subItem as never), 1));
//     } else {
//         item.items.forEach((subItem: { label: string; value: string }) => {
//             if (!selected.value.includes(subItem as never)) {
//                 selected.value.push(subItem);
//             }
//         });
//     }
// };
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
    <base-select v-if="!loading" v-model="selected" :items="items"></base-select>
    <v-skeleton-loader v-else type="article"></v-skeleton-loader>
    <v-banner-text class="pt-4 px-4">{{ hint }}</v-banner-text>
</template>
