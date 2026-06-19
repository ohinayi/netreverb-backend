<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>NetReverb API Documentation</title>
        @vite('resources/js/swagger.js')
    </head>
    <body>
        <div id="swagger-ui" data-spec-url="{{ route('api-docs.spec') }}"></div>
    </body>
</html>
