<?php

declare(strict_types=1);

namespace BusinessErrorHandler\Laravel;

use Illuminate\Console\Command;
use RuntimeException;

final class MakeErrorHandlerCommand extends Command
{
    protected $signature = 'make:errorhandler {--logkibana : Enable Kibana logging in the generated configuration}';

    protected $description = 'Generate a global error handler configuration file';

    public function handle(): int
    {
        $path = base_path('config/error-handler.php');
        if (is_file($path)) {
            $this->error('The error handler configuration already exists: ' . $path);

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the configuration directory: ' . $directory);
        }

        $kibanaLogging = $this->option('logkibana') ? 'true' : 'false';
        $contents = <<<PHP
<?php

return [
    'environment' => env('APP_ENV', 'production'),
    'kibana_logging' => {$kibanaLogging},
    'kibana_url' => env('KIBANA_URL', 'http://localhost:9200/error-handler/_doc'),
    'default_message' => 'Internal Server Error',
    'exception_messages' => [],
];
PHP;

        if (file_put_contents($path, $contents . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the configuration file: ' . $path);
        }

        $this->info('Error handler configuration created at ' . $path);
        $this->comment('Register it from your exception bootstrap with ErrorHandler::register(config("error-handler"));');

        return self::SUCCESS;
    }
}
