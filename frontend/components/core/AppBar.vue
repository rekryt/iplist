<template>
    <v-app-bar id="core-app-bar" absolute color="transparent" flat height="88">
        <v-toolbar-title class="font-weight-light align-self-center text-no-wrap">
            <v-btn v-show="!responsive" icon @click.stop="onClick">
                <v-icon>mdi-view-list</v-icon>
            </v-btn>
            {{ title }}
        </v-toolbar-title>

        <v-spacer></v-spacer>

        <v-toolbar-items class="flex-fill">
            <v-row align="center" justify="end" class="mx-0 px-4">
                <v-col class="px-0 d-block d-md-none" cols="auto">
                    <github-button
                        class="d-block mt-1"
                        href="https://github.com/rekryt/iplist"
                        :data-color-scheme="theme.name.value"
                        data-icon="octicon-star"
                        data-size="small"
                        aria-label="Star rekryt/iplist on GitHub"
                    >
                        Star
                    </github-button>
                </v-col>
                <v-col class="d-none d-md-block" cols="auto">
                    <github-button
                        class="d-block mt-1"
                        href="https://github.com/rekryt/iplist"
                        :data-color-scheme="theme.name.value"
                        data-icon="octicon-star"
                        data-size="large"
                        data-show-count="true"
                        aria-label="Star rekryt/iplist on GitHub"
                    >
                        Star
                    </github-button>
                </v-col>
                <v-col cols="auto">
                    <v-select
                        v-model="locale"
                        :items="localesList"
                        item-title="label"
                        item-value="code"
                        :label="t('language')"
                        variant="outlined"
                        density="compact"
                        class="w-32"
                        hide-details
                        @update:model-value="setLocale"
                    >
                        <template #selection="{ item }">
                            <div class="d-flex align-center">
                                <v-avatar size="20" class="mr-2">
                                    <img :src="item.raw.flag" alt="flag" />
                                </v-avatar>
                                <span class="d-none d-md-block">{{ item.raw.label }}</span>
                            </div>
                        </template>
                        <template #item="{ item, props }">
                            <v-list-item v-bind="props">
                                <template #prepend>
                                    <v-avatar size="20">
                                        <img :src="item.raw.flag" alt="flag" />
                                    </v-avatar>
                                </template>
                            </v-list-item>
                        </template>
                    </v-select>
                </v-col>
                <v-btn height="48" icon @click="toggleTheme">
                    <v-icon color="tertiary">mdi-theme-light-dark</v-icon>
                </v-btn>
            </v-row>
        </v-toolbar-items>
    </v-app-bar>
</template>
<i18n lang="json">
{
    "en": {
        "language": "Language",
        "index___en": "Portals",
        "about___en": "About",
        "groups___en": "Groups"
    },
    "ru": {
        "language": "Язык",
        "index___ru": "Порталы",
        "about___ru": "О проекте",
        "groups___ru": "Группы"
    },
    "cn": {
        "language": "语言",
        "index___cn": "通过门户",
        "about___cn": "关于项目",
        "groups___cn": "分组"
    }
}
</i18n>
<script>
// Utilities
import { mapActions } from 'pinia';
import { useAppStore } from '~/stores/app';
import { useDisplay, useTheme } from 'vuetify';
import GithubButton from 'vue-github-button';

export default {
    components: {
        GithubButton,
    },
    setup() {
        const { t, locale, locales } = useI18n({
            useScope: 'local',
        });
        const theme = useTheme();
        const cookieTheme = useCookieTheme();
        const localePath = useLocalePath();
        const router = useRouter();
        const localsData = [
            { code: 'en', language: 'English', flag: 'https://flagcdn.com/w40/us.png' },
            { code: 'ru', language: 'Русский', flag: 'https://flagcdn.com/w40/ru.png' },
            { code: 'cn', language: '简体中文', flag: 'https://flagcdn.com/w40/cn.png' },
        ];
        const localesList = computed(() =>
            locales.value.map((l) => ({
                value: l,
                code: l.code,
                label: localsData.find((d) => d.code === l.code).language,
                flag: localsData.find((d) => d.code === l.code).flag,
            }))
        );
        const localeData = computed(() => localsData.find((l) => l.code === locale.value));

        const setLocale = (value) => {
            router.push(localePath(router.currentRoute.value.path, value));
        };

        const route = useRoute();
        const title = computed(() => {
            return t(route.name);
        });

        const toggleTheme = () => {
            const themeValue = theme.global.current.value.dark ? 'light' : 'dark';
            theme.global.name.value = themeValue;
            cookieTheme.value = themeValue;
        };

        onMounted(async () => {
            toggleTheme();
            await nextTick();
            toggleTheme();
        });

        return {
            theme,
            t,
            locale,
            localesList,
            setLocale,
            localeData,
            title,
            toggleTheme,
        };
    },

    data: () => ({
        notifications: [
            'Mike, John responded to your email',
            'You have 5 new tasks',
            "You're now a friend with Andrew",
            'Another Notification',
            'Another One',
        ],
    }),

    computed: {
        responsive() {
            const display = useDisplay();
            return display.lgAndUp.value;
        },
    },

    created() {
        this.setDrawer(this.responsive);
    },

    methods: {
        ...mapActions(useAppStore, ['setDrawer', 'toggleDrawer']),
        onClick() {
            this.setDrawer(!useAppStore().drawer);
        },
    },
};
</script>

<style>
/* Fix coming in v2.0.8 */
#core-app-bar {
    width: auto;
}

#core-app-bar a {
    text-decoration: none;
}
</style>
