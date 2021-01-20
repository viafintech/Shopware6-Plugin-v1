const ApiService = Shopware.Classes.ApiService;

class ViacashResendService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'viacash') {
        super(httpClient, loginService, apiEndpoint);
    }

    resend(data = {orderId: null, versionId: null}) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/resend`,
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

export default ViacashResendService;
