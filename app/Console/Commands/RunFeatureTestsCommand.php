<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunFeatureTestsCommand extends Command
{
    protected $signature = 'test {args?*}';

    protected $description = 'Run the application feature tests (PHPUnit)';

    public function isEnabled(): bool
    {
        return ! class_exists(\NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand::class);
    }

    public function handle(): int
    {
        $phpunit = base_path('vendor/bin/phpunit');

        if (! is_file($phpunit)) {
            $this->error('PHPUnit is not installed, so the "test" command is missing.');
            $this->newLine();
            $this->line('This usually means Composer was installed with <fg=yellow>--no-dev</> (typical on Hostinger).');
            $this->line('Do not run tests against the live MySQL database — they reset tables.');
            $this->newLine();
            $this->line('On your computer, in the project folder (the one that contains artisan):');
            $this->line('  composer install');
            $this->line('  php artisan test');
            $this->line('or:');
            $this->line('  ./vendor/bin/phpunit');

            return self::FAILURE;
        }

        $command = [PHP_BINARY, $phpunit, ...$this->argument('args')];
        $process = new Process($command, base_path(), timeout: 120);
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        return $process->getExitCode() ?? self::FAILURE;
    }
}
