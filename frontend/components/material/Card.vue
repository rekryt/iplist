<template>
    <v-card class="v-card--material-card" :style="styles" v-bind="$attrs">
        <helper-offset v-if="hasOffset" :inline="inline" :full-width="fullWidth" :offset="offset">
            <v-card
                v-if="!$slots.offset"
                :color="color"
                :elevation="elevation"
                class="v-card--material__header d-flex align-center"
                min-height="80"
            >
                <slot v-if="!title && !text" name="header" />
                <div v-else class="px-3">
                    <h4 class="title font-weight-light mb-2" v-text="title" />
                    <p class="category font-weight-thin mb-0" v-text="text" />
                </div>
            </v-card>

            <slot v-else name="offset" />
        </helper-offset>

        <v-card-text class="cardText">
            <slot />
        </v-card-text>

        <v-divider v-if="$slots.actions" class="mx-3" />

        <v-card-actions v-if="$slots.actions">
            <slot name="actions" />
        </v-card-actions>
    </v-card>
</template>

<script>
export default {
    name: 'MaterialCard',

    inheritAttrs: false,

    props: {
        color: {
            type: String,
            default: 'secondary',
        },
        elevation: {
            type: [Number, String],
            default: 10,
        },
        inline: {
            type: Boolean,
            default: false,
        },
        fullWidth: {
            type: Boolean,
            default: false,
        },
        offset: {
            type: [Number, String],
            default: 24,
        },
        title: {
            type: String,
            default: undefined,
        },
        text: {
            type: String,
            default: undefined,
        },
    },

    computed: {
        hasOffset() {
            return this.$slots.header || this.$slots.offset || this.title || this.text;
        },
        styles() {
            if (!this.hasOffset) return null;

            return {
                marginBottom: `${this.offset}px`,
                marginTop: `${this.offset * 2}px`,
            };
        },
    },
};
</script>
<style>
.v-card--material-card {
    overflow: visible;
}
.cardText {
    font-size: 18px !important;
    line-height: 1.5 !important;
}
</style>
