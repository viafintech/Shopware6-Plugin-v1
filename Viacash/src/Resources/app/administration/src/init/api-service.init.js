import ViacashRefundService from '../core/service/api/viacash-refund.service';
import ViacashResendService from '../core/service/api/viacash-resend.service';
import ViacashPingService   from '../core/service/api/viacash-ping.service';

const { Application } = Shopware;

Application.addServiceProvider('ViacashRefundService', (container) => {
    const initContainer = Application.getContainer('init');
    return new ViacashRefundService(initContainer.httpClient, container.loginService);
});

Application.addServiceProvider('ViacashResendService', (container) => {
    const initContainer = Application.getContainer('init');
    return new ViacashResendService(initContainer.httpClient, container.loginService);
});

Application.addServiceProvider('ViacashPingService', (container) => {
    const initContainer = Application.getContainer('init');
    return new ViacashPingService(initContainer.httpClient, container.loginService);
});
