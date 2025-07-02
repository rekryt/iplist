<script lang="ts" setup>
const { t } = useI18n({
    useScope: 'local',
});
const links = computed(() => [{ name: t('issue'), Link: 'https://github.com/rekryt/iplist/issues' }]);
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
            <a href="https://vk.com/rekryt" target="_blank">Rekryt</a>
            <v-icon style="margin-top: -3px" color="tertiary" size="17">mdi-star</v-icon>
            for a better web
            <br />
        </span>
    </v-footer>
</template>
<i18n lang="json">
{
    "en": {
        "issue": "Issue"
    },
    "ru": {
        "issue": "Задать вопрос"
    },
    "cn": {
        "issue": "提交问题"
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
