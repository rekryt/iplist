<template>
    <v-main class="grey lighten-3">
        <v-fade-transition mode="out-in">
            <div id="core-view">
                <slot></slot>
            </div>
        </v-fade-transition>
    </v-main>
</template>

<script>
export default {
    name: 'CoreView',
};
</script>
<style lang="scss">
body {
    min-width: 320px;
}
#core-view {
    display: flex;
    flex-direction: column;
    height: 100%;
}
</style>
