<?php

namespace Cotonet\SoakTime;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    protected $composer;
    
    /** @var IOInterface */
    protected $io;
    
    /** @var int Minimum package age in hours */
    protected $minHours = 168; // Default: 168 hours (7 days)

    /**
     * Called when the plugin is activated.
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        // Fetch custom configuration from the user's composer.json
        $extra = $composer->getPackage()->getExtra();
        if (isset($extra['soak-time-hours'])) {
            $this->minHours = (int) $extra['soak-time-hours'];
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void 
    {
        // Not needed for this plugin
    }

    public function uninstall(Composer $composer, IOInterface $io): void 
    {
        // Not needed for this plugin
    }

    /**
     * Subscribe to specific Composer events.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Hook into the exact moment before the solver starts calculating dependencies
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
        ];
    }

    /**
     * Intercepts the package pool creation to filter out recent releases.
     */
    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
         // Emergency bypass using environment variable
        if (getenv('SOAK_TIME_SKIP') === '1') {
            $this->io->write("<warning>[Soak Time] Emergency bypass detected! Skipping filters.</warning>");
            return;
        }
        
        $this->io->write("<info>[Soak Time] Inspecting packages (requiring minimum age of {$this->minHours} hours)...</info>");

        $packages = $event->getPackages();
        $filteredPackages = [];
        $droppedCount = 0;
        
        // Calculate the exact date and time threshold
        $thresholdDate = (new \DateTimeImmutable())->modify("-{$this->minHours} hours");

        foreach ($packages as $package) {
            $releaseDate = $package->getReleaseDate();
            
            // If the package has a release date and it's newer than our threshold
            if ($releaseDate !== null && $releaseDate > $thresholdDate) {
                
                // If the user runs 'composer update -v', print which packages are being dropped
                if ($this->io->isVerbose()) {
                    $this->io->write(sprintf(
                        "  - <warning>Dropping %s v%s (released %s)</warning>", 
                        $package->getName(), 
                        $package->getPrettyVersion(), 
                        $releaseDate->format('Y-m-d H:i:s')
                    ));
                }
                
                $droppedCount++;
                continue; // Skip adding this package to the safe list
            }
            
            // Package is safe (older than threshold or has no date, e.g., local dev paths)
            $filteredPackages[] = $package;
        }

        if ($droppedCount > 0) {
            $this->io->write("<info>[Soak Time] Successfully filtered out {$droppedCount} recent package version(s).</info>");
        }

        // Replace the Composer pool with our clean, filtered list
        $event->setPackages($filteredPackages);
    }
}
