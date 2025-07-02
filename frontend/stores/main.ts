export const useMainStore = defineStore('main', {
    state: () => ({
        sidebar: false,
    }),
    actions: {
        toggleSidebar() {
            this.sidebar = !this.sidebar;
        },
    },
});
