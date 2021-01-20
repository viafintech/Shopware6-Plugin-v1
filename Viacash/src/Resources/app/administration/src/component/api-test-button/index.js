const { Component, Mixin } = Shopware;
import template from './api-test-button.html.twig';

Component.register('api-test-button', {
    template,

    props: ['label'],
    inject: ['ViacashPingService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            return this.$parent.$parent.$parent.actualConfigData.null;
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        ping() {
            this.isLoading = true;
            this.ViacashPingService.ping().then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('api-test-button.title'),
                        message: this.$tc('api-test-button.success') + res.successful_ids.toString()
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('api-test-button.title'),
                        message: this.$tc('api-test-button.error')
                    });
                }

                this.isLoading = false;
            });
        }
    }
})
