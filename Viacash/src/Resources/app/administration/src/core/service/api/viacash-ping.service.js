const ApiService = Shopware.Classes.ApiService;

class ViacashPingService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'viacash') {
        super(httpClient, loginService, apiEndpoint);
    }

    ping() {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .get(
                `_action/${this.getApiBasePath()}/ping`
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

}

export default ViacashPingService;
