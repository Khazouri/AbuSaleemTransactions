<?php

namespace App\Console\Commands;

use App\Services\WorkflowVersionValidator;
use Illuminate\Console\Command;

/**
 * Maintenance console — «بعد الرفع» (2026-10-30). The end-to-end walk found a
 * host months behind its code: two migrations unrun and the path's rules from
 * before 2026-10-17, which nothing reported until a meeting's results were
 * refused. One press after an upload now brings the schema and the path up to
 * the release, then checks the path the files actually run on.
 */
class AfterUpload extends Command
{
    protected $signature = 'maintenance:after-upload';

    protected $description = 'Run the migrations, re-seed the workflow, then check every published workflow version.';

    public function handle(WorkflowVersionValidator $validator): int
    {
        foreach ([['migrate', ['--force' => true]], ['db:seed', ['--class' => 'WorkflowSeeder', '--force' => true]], ['db:seed', ['--class' => 'WalkthroughVideoSeeder', '--force' => true]]] as [$command, $params]) {
            if ($this->call($command, $params) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $errors = $validator->publishedErrors();
        foreach ($errors as $error) {
            $this->error("{$error['code']}: {$error['message_ar']}");
        }

        if ($errors !== []) {
            return self::FAILURE;
        }

        $this->info('The published workflow checks clean.');

        return self::SUCCESS;
    }
}
