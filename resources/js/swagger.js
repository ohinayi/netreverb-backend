import SwaggerUI from 'swagger-ui-dist/swagger-ui-es-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

const container = document.querySelector('#swagger-ui');

if (container) {
    SwaggerUI({
        dom_id: '#swagger-ui',
        url: container.dataset.specUrl,
        deepLinking: true,
        persistAuthorization: true,
        layout: 'BaseLayout',
    });
}
