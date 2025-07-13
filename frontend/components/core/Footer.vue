<script lang="ts" setup>
const { t } = useI18n({
    useScope: 'local',
});
const links = computed(() => [
    { name: t('issue'), Link: 'https://github.com/rekryt/iplist/issues' },
    { name: t('lightVersion'), Link: '/index' },
    { name: t('genericVersion'), Link: 'https://iplist.opencck.org' },
    { name: t('betaVersion'), Link: 'https://beta.iplist.opencck.org' },
]);
</script>
<template>
    <v-footer id="core-footer">
        <div class="footer-items">
            <a v-for="link in links" :key="link.name" :href="link.Link" class="footer-link">
                {{ link.name }}
            </a>
        </div>
        <v-spacer />
        <span class="font-weight-light copyright">
            &copy; {{ new Date().getFullYear() }}
            <a href="https://github.com/rekryt" target="_blank">Rekryt</a>
            <v-icon style="margin-top: -3px" color="tertiary" size="17">mdi-star</v-icon>
            for a better web
            <br />
        </span>
    </v-footer>
</template>
<i18n lang="json">
{
    "en": {
        "issue": "Issue",
        "lightVersion": "Light version",
        "genericVersion": "Main version",
        "betaVersion": "Beta version"
    },
    "ru": {
        "issue": "Задать вопрос",
        "lightVersion": "Лёгкая версия",
        "genericVersion": "Основная версия",
        "betaVersion": "Бета версия"
    },
    "cn": {
        "issue": "提交问题",
        "lightVersion": "轻量版",
        "genericVersion": "主版本",
        "betaVersion": "测试版"
    }
}
</i18n>

<style lang="scss">
#core-footer {
    flex: 0 0 auto;
    z-index: 0;
    margin-top: auto;
    height: 100px;
}
.copyright {
    font-size: 14px;
}
.footer-items {
    display: flex;
    flex-wrap: wrap;
    font-size: 14px;
}
.footer-link {
    margin: 5px 5px 5px 0;
}
</style>
