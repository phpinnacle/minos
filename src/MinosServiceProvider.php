<?php

namespace PHPinnacle\Minos;

use PHPinnacle\Minos\Services\ProviderRegistry;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MinosServiceProvider extends PackageServiceProvider
{
    public static string $name = 'phpinnacle-minos';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->discoversMigrations()
            ->hasTranslations()
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('phpinnacle/minos');
            });
    }

    public function packageRegistered(): void
    {
        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $registry) {
            MinosPlugin::get()->loadProviders($registry);
        });
    }
}
