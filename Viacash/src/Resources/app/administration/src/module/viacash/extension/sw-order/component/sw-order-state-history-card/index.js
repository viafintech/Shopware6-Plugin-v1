import template from './sw-order-state-history-card.html.twig';

const {Component, Service} = Shopware;

Component.override('sw-order-state-history-card', {
    template,

    props: {
        orderId: {
            type: String,
            required: true
        }
    },

    inject: [
        'ViacashRefundService',
        'ViacashResendService',
    ],

    data() {
        return {
            isLoading: false,
            refundValue: this.order.customFields.custom_viacash_refundable_amount,
            maxRefundValue: 1.0*(this.order.customFields.custom_viacash_refundable_amount),
            isViacash: Boolean(this.order.customFields.custom_viacash_checkout_token),
        };
    },

    methods: {

        onConfirmRefund(refundValue, message) {
            if (refundValue > 0 && refundValue <= this.order.customFields.custom_viacash_refundable_amount) {
                if (confirm(message)) {
                    this.ViacashRefundService.refund({
                        orderId: this.order.id,
                        versionId: this.order.versionId,
                        refundAmount: refundValue
                    }).then(document.location.reload());
                }
            }
        },

        onResend(message) {
            if (this.order.customFields.custom_viacash_slip_id) {
                if (confirm(message)) {
                    this.ViacashResendService.resend({
                        orderId: this.order.id,
                        versionId: this.order.versionId,
                    });
                }
            }
        },

    }
});
