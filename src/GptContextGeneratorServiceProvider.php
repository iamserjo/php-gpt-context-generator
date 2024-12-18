<?php

namespace Iamserjo\PhpGptContextGenerator;

use Iamserjo\PhpGptContextGenerator\Command\GptContextGeneratorCommand;
use Illuminate\Support\ServiceProvider;

class GptContextGeneratorServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register bindings if needed
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GptContextGeneratorCommand::class,
            ]);
        }
    }
}
