<?php

namespace Cipi\Agent\Console\Commands;

use Illuminate\Console\Command;

class CipiDeployKeyCommand extends Command
{
    protected $signature = 'cipi:deploy-key';

    protected $description = 'Show the SSH deploy key for this application';

    public function handle(): int
    {
        $appUser = config('cipi.app_user');

        if (empty($appUser)) {
            $this->components->error('CIPI_APP_USER is not configured in .env');
            return self::FAILURE;
        }

        $keyPath = "/home/{$appUser}/.ssh/id_ed25519.pub";

        if (! file_exists($keyPath)) {
            $this->components->error("Deploy key not found at {$keyPath}");
            return self::FAILURE;
        }

        $key = trim(file_get_contents($keyPath));

        $this->components->info('Deploy Key');
        $this->newLine();
        $this->line("  <fg=cyan>{$key}</>");
        $this->newLine();

        $this->components->info('Add this key as a Deploy Key in your Git provider:');
        $this->line('  GitHub:    Repository → Settings → Deploy keys → Add');
        $this->line('  GitLab:    Repository → Settings → Repository → Deploy keys');
        $this->line('  Bitbucket: Repository → Settings → Access keys');
        $this->line('  Gitea:     Repository → Settings → Deploy Keys');

        return self::SUCCESS;
    }
}
