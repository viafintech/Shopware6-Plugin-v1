const ApiService = Shopware.Classes.ApiService;

class ViacashRefundService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'viacash') {
        super(httpClient, loginService, apiEndpoint);
    }

    refund(data = {orderId: null, versionId: null, refundAmount: null}) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/refund`,
                JSON.stringify(data),
                {
                    headers: headers
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

}

export default ViacashRefundService;
