import template from './sw-order-state-history-card.html.twig';

const {Component, Service} = Shopware;

Component.override('sw-order-state-history-card', {
    template,

    props: {
        orderId: {
            type: String,
            required: true,
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
            maxRefundValue: 1.0 * (this.order.customFields.custom_viacash_refundable_amount),
            isViacash: Boolean(this.order.customFields.custom_viacash_checkout_token),
            askForRefundConfirmation: 0,
        };
    },

    methods: {

        onConfirmRefund() {

            this.ViacashRefundService.refund({
                orderId: this.order.id,
                versionId: this.order.versionId,
                refundAmount: this.askForRefundConfirmation
            }).then(document.location.reload());

            this.askForRefundConfirmation = 0;
            document.getElementById('sw-field--refundValue').readOnly = false;
        },

        onAbortRefund() {
            this.askForRefundConfirmation = 0;
            document.getElementById('sw-field--refundValue').readOnly = false;
        },

        onInitiateRefund(refundValue, message) {
            if (refundValue <= 0) {

                document.getElementById('refundErrors').innerHTML =
                    '<span style="background:#FFB9AD; color:red; padding: 8px">'
                    + this.$tc('refundamount.mustbepositive')
                    + '</span>';

            } else if (refundValue > this.order.customFields.custom_viacash_refundable_amount) {

                document.getElementById('refundErrors').innerHTML =
                    '<span style="background:#FFB9AD; color:red; padding: 8px">'
                    + this.$tc('refundamount.mustbesmallerthan')
                    + this.order.customFields.custom_viacash_refundable_amount
                    + '</span>';

            } else {
                document.getElementById('refundErrors').innerText = '';
                document.getElementById('sw-field--refundValue').readOnly = true;
                this.askForRefundConfirmation = refundValue;
            }
        },

        onResend(message) {
            if (this.order.customFields.custom_viacash_slip_id) {
                this.ViacashResendService.resend({
                    orderId: this.order.id,
                    versionId: this.order.versionId,
                });
            }
        },

    }
});
