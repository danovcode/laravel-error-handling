<?php

declare(strict_types=1);

return [
    'environment' => 'production',
    'kibana_logging' => false,
    'kibana_url' => getenv('KIBANA_URL') ?: 'http://localhost:9200/error-handler/_doc',
    'default_message' => 'Internal Server Error',
    'exception_messages' => [],
];
