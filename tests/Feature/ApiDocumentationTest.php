<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_swagger_ui_is_available_when_api_docs_are_enabled(): void
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('NetReverb API Documentation')
            ->assertSee(route('api-docs.spec'), escape: false);
    }

    public function test_openapi_contract_is_served_as_yaml(): void
    {
        $this->get('/docs/api/openapi.yaml')
            ->assertOk()
            ->assertHeader('content-type', 'application/yaml')
            ->assertSee('openapi: 3.1.0', escape: false)
            ->assertSee('/auth/register:', escape: false);
    }
}
