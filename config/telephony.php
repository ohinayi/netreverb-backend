<?php

return [
    'sip_realm' => env('KAMAILIO_SIP_REALM', 'sip.classyra.com.ng'),
    'automatic_extension_start' => (int) env('AUTOMATIC_EXTENSION_START', 100000),
    'automatic_extension_end' => (int) env('AUTOMATIC_EXTENSION_END', 899999),
];
