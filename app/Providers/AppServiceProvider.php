<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\File;
use App\Observers\DocumentObserver;
use App\Observers\FileObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        File::observe(FileObserver::class);
        Document::observe(DocumentObserver::class);
    }
}
