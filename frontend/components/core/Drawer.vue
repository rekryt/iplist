<template>
    <v-navigation-drawer id="app-drawer" v-model="inputValue" width="260" elevation="5" floating rail>
        <v-row justify="center" class="text-center">
            <v-col class="pt-8">
                <v-avatar color="white">
                    <v-img src="/icon.png" height="34" contain />
                </v-avatar>
            </v-col>
        </v-row>

        <v-divider class="mx-3 mb-3" />

        <v-list density="compact" nav>
            <v-list-item v-for="(link, i) in links" :key="i" :to="link.to" active-class="primary white--text">
                <template #prepend>
                    <v-icon>{{ link.icon }}</v-icon>
                </template>
                <v-list-item-title>{{ link.text }}</v-list-item-title>
            </v-list-item>
        </v-list>

        <template #append>
            <v-list density="compact" nav>
                <v-list-item tag="a" href="https://github.com/rekryt/iplist" target="_blank">
                    <template #prepend>
                        <v-icon>mdi-github</v-icon>
                    </template>
                    <v-list-item-title class="font-weight-light">GitHub</v-list-item-title>
                </v-list-item>
            </v-list>
        </template>
    </v-navigation-drawer>
</template>
<i18n lang="json">
{
    "en": {
        "main": "Portals",
        "groups": "Groups",
        "about": "About"
    },
    "ru": {
        "main": "Порталы",
        "groups": "Группы",
        "about": "О проекте"
    },
    "cn": {
        "main": "通过门户",
        "groups": "分组",
        "about": "关于项目"
    }
}
</i18n>
<script>
import { mapActions, mapState } from 'pinia';
import { useAppStore } from '~/stores/app';

export default {
    setup() {
        const { t } = useI18n({
            useScope: 'local',
        });
        const localePath = useLocalePath();
        const links = computed(() => [
            {
                to: localePath('/'),
                icon: 'mdi-web-sync',
                text: t('main'),
            },
            {
                to: localePath('/about'),
                icon: 'mdi-information-outline',
                text: t('about'),
            },
        ]);

        return { t, links };
    },
    computed: {
        ...mapState(useAppStore, ['color']),
        inputValue: {
            get() {
                return useAppStore().drawer;
            },
            set(val) {
                this.setDrawer(val);
            },
        },
    },

    methods: {
        ...mapActions(useAppStore, ['setDrawer', 'toggleDrawer']),
    },
};
</script>
